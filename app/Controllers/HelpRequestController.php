<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Router;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Services\AuthService;
use App\Models\HelpRequest;
use App\Models\VolunteerProfile;
use App\Models\ElderlyProfile;
use App\Services\GeoMatchingService;
use App\Services\SecurityService;
use App\Services\DataMaskingService;
use App\Services\RegionService;

class HelpRequestController
{
    public function index(): void
    {
        $requests = HelpRequest::getActiveRequests();
        $volLat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
        $volLon = isset($_GET['lon']) ? (float)$_GET['lon'] : null;

        // Country-wide feed: optional region filter (?region=BREST).
        $regionFilter = isset($_GET['region']) ? strtoupper(trim((string)$_GET['region'])) : '';
        if ($regionFilter !== '' && RegionService::exists($regionFilter)) {
            $requests = array_values(array_filter(
                $requests,
                fn($req) => RegionService::normalize($req['region_code'] ?? null) === $regionFilter
            ));
        }
        foreach ($requests as &$req) {
            $req['region_name'] = RegionService::name($req['region_code'] ?? null);
        }
        unset($req);

        if ($volLat !== null && $volLon !== null) {
            foreach ($requests as &$req) {
                $dist = GeoMatchingService::calculateDistance(
                    $volLat, $volLon,
                    (float)$req['latitude'], (float)$req['longitude']
                );
                $req['distance_km'] = $dist;
                $req['distance_meters'] = (int)($dist * 1000);
            }
            usort($requests, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
        }

        // Law 99-Z Privacy Enforcement:
        // Mask elderly phone and apartment in open feed unless explicitly authenticated coordinator/admin
        $currentUser = AuthService::getCurrentUser();
        $hasExplicitAuth = !empty($_SERVER['HTTP_AUTHORIZATION']) || 
                           !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ||
                           !empty($_SERVER['HTTP_X_AUTH_TOKEN']) || 
                           !empty($_COOKIE['auth_token']) || 
                           !empty($_SERVER['HTTP_X_DEMO_USER']);
        $effectiveUser = $hasExplicitAuth ? $currentUser : null;

        foreach ($requests as &$req) {
            $req = DataMaskingService::maskRequest($req, $effectiveUser);
        }
        unset($req);

        Router::json([
            'success' => true,
            'count' => count($requests),
            'data' => $requests
        ]);
    }

    public function myRequests(): void
    {
        $currentUser = AuthService::getCurrentUser();
        $elderlyId = null;
        if ($currentUser && $currentUser['role_code'] === 'elderly') {
            $elderlyId = (int)$currentUser['user_id'];
        } elseif (AuthService::isDevMode() && isset($_GET['elderly_id'])) {
            $elderlyId = (int)$_GET['elderly_id'];
        }
        if ($elderlyId === null) {
            AuthService::requireAuth(['elderly']);
            return;
        }

        $requests = HelpRequest::getByElderly($elderlyId);

        Router::json([
            'success' => true,
            'count' => count($requests),
            'data' => $requests
        ]);
    }

    public function volunteerTasks(): void
    {
        $currentUser = AuthService::getCurrentUser();
        $volunteerId = null;
        if ($currentUser && $currentUser['role_code'] === 'volunteer') {
            $volunteerId = (int)$currentUser['user_id'];
        } elseif (AuthService::isDevMode() && isset($_GET['volunteer_id'])) {
            $volunteerId = (int)$_GET['volunteer_id'];
        }
        if ($volunteerId === null) {
            AuthService::requireAuth(['volunteer']);
            return;
        }

        $status = $_GET['status'] ?? 'in_progress';
        $requests = HelpRequest::getByVolunteer($volunteerId, $status);

        Router::json([
            'success' => true,
            'count' => count($requests),
            'data' => $requests
        ]);
    }

    public function show(string $id): void
    {
        $req = HelpRequest::getWithDetails((int)$id);
        if (!$req) {
            Router::json(['error' => 'Заявка не найдена'], 404);
        }

        $currentUser = AuthService::getCurrentUser();
        $hasExplicitAuth = !empty($_SERVER['HTTP_AUTHORIZATION']) || 
                           !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ||
                           !empty($_SERVER['HTTP_X_AUTH_TOKEN']) || 
                           !empty($_COOKIE['auth_token']) || 
                           !empty($_SERVER['HTTP_X_DEMO_USER']);
        $effectiveUser = $hasExplicitAuth ? $currentUser : null;

        $req = DataMaskingService::maskRequest($req, $effectiveUser);

        Router::json(['success' => true, 'data' => $req]);
    }

    public function store(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!RateLimiter::check("create_request:{$ip}", 60, 60)) {
            Router::json(['error' => 'Слишком много запросов на создание заявок. Пожалуйста, подождите минуту.'], 429);
        }

        $input = Router::getJsonInput();
        $currentUser = AuthService::getCurrentUser();
        $elderlyId = null;
        if ($currentUser && $currentUser['role_code'] === 'elderly') {
            $elderlyId = (int)$currentUser['user_id'];
        } elseif (AuthService::isDevMode()) {
            $elderlyId = (int)($input['elderly_id'] ?? 1);
        }
        if ($elderlyId === null) {
            AuthService::requireAuth(['elderly']);
            return;
        }

        $categoryId = (int)($input['category_id'] ?? 1);
        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
        $description = is_string($input['description'] ?? null) ? trim($input['description']) : '';
        $address = is_string($input['address'] ?? null) ? trim($input['address']) : '';

        if (!\App\Models\Category::find($categoryId)) {
            Router::json(['error' => 'Выбранная категория помощи не найдена'], 400);
        }

        $hasCoords = isset($input['latitude'], $input['longitude']);
        $latInput = $hasCoords ? (float)$input['latitude'] : null;
        $lonInput = $hasCoords ? (float)$input['longitude'] : null;

        if ($hasCoords && (!is_numeric($input['latitude']) || !is_numeric($input['longitude']))) {
            Router::json(['error' => 'GPS-координаты (latitude/longitude) должны быть числами'], 400);
        }
        if ($hasCoords && ($latInput < -90 || $latInput > 90 || $lonInput < -180 || $lonInput > 180)) {
            Router::json(['error' => 'GPS-координаты вне допустимого диапазона (latitude: -90..90, longitude: -180..180)'], 400);
        }

        $regionCode = RegionService::normalize(
            isset($input['region_code']) ? (string)$input['region_code'] : null,
            $latInput,
            $lonInput
        );
        $regionCenter = RegionService::center($regionCode);
        $lat = $latInput ?? $regionCenter['lat'];
        $lon = $lonInput ?? $regionCenter['lon'];
        if ($address === '') {
            $address = 'Республика Беларусь, ' . RegionService::name($regionCode);
        }
        $address = mb_substr($address, 0, 500, 'UTF-8');

        if (!$title && !$description) {
            Router::json(['error' => 'Укажите или надиктуйте, какая помощь требуется'], 400);
        }

        if (!$title) {
            $title = mb_substr($description, 0, 60);
        }

        // MySQL columns are VARCHAR(255); never let an over-long dictated title become a 500.
        if (mb_strlen($title, 'UTF-8') > 255) {
            $title = mb_substr($title, 0, 255, 'UTF-8');
        }
        if (mb_strlen($description, 'UTF-8') > 5000) {
            $description = mb_substr($description, 0, 5000, 'UTF-8');
        }

        // Generate friendly secret safety code
        $secretCode = SecurityService::generateSecretCode();

        $requestId = HelpRequest::create([
            'elderly_id' => $elderlyId,
            'category_id' => $categoryId,
            'title' => $title,
            'description' => $description,
            'latitude' => $lat,
            'longitude' => $lon,
            'region_code' => $regionCode,
            'address' => $address,
            'secret_code' => $secretCode,
            'status' => 'open'
        ]);

        Logger::info("New help request #{$requestId} created by elderly user #{$elderlyId}");

        // Find matching nearby volunteers
        $verifiedVolunteers = VolunteerProfile::getVerified();
        $reqData = ['latitude' => $lat, 'longitude' => $lon];
        $matchedVolunteers = GeoMatchingService::rankVolunteers($reqData, $verifiedVolunteers, 5.0);

        Router::json([
            'success' => true,
            'message' => 'Заявка успешно создана. Волонтёры района оповещены.',
            'request_id' => $requestId,
            'secret_code' => $secretCode,
            'nearby_volunteers_found' => count($matchedVolunteers)
        ], 201);
    }

    public function accept(string $id): void
    {
        $input = Router::getJsonInput();
        $currentUser = AuthService::getCurrentUser();
        $volunteerId = null;
        if ($currentUser && $currentUser['role_code'] === 'volunteer') {
            $volunteerId = (int)$currentUser['user_id'];
        } elseif (AuthService::isDevMode()) {
            $volunteerId = (int)($input['volunteer_id'] ?? 2);
        }
        if ($volunteerId === null) {
            AuthService::requireAuth(['volunteer']);
            return;
        }

        $req = HelpRequest::find((int)$id);
        if (!$req) {
            Router::json(['error' => 'Заявка не найдена'], 404);
        }

        if ($req['status'] !== 'open') {
            Router::json(['error' => 'Заявка уже взята другим волонтёром или завершена'], 400);
        }

        // Verification check: only verified volunteers can accept
        $volunteer = VolunteerProfile::find($volunteerId);
        if (!$volunteer || (int)$volunteer['is_verified'] !== 1) {
            Logger::security("Unverified volunteer #{$volunteerId} tried to accept request #{$id}");
            Router::json([
                'error' => 'Вы не можете брать заявки: ваш профиль волонтёра ещё не прошёл верификацию администратором/координатором.'
            ], 403);
        }

        // Atomic claim: two simultaneous accepts must not overwrite each other.
        if (!HelpRequest::tryAccept((int)$id, (int)$volunteerId)) {
            Router::json(['error' => 'Заявка уже взята другим волонтёром или завершена'], 409);
        }

        Logger::info("Request #{$id} accepted by verified volunteer #{$volunteerId}");

        Router::json([
            'success' => true,
            'message' => 'Заявка переведена в статус «В работе». Назовите подопечному кодовое слово при визите.',
            'secret_code' => $req['secret_code']
        ]);
    }

    public function complete(string $id): void
    {
        $input = Router::getJsonInput();
        $secretWord = is_string($input['secret_code'] ?? null) ? trim($input['secret_code']) : '';

        $req = HelpRequest::find((int)$id);
        if (!$req) {
            Router::json(['error' => 'Заявка не найдена'], 404);
        }

        $currentUser = AuthService::getCurrentUser();
        $completingVolunteerId = null;
        if ($currentUser) {
            $role = $currentUser['role_code'] ?? '';
            $uid = (int)$currentUser['user_id'];
            if ($role === 'volunteer') {
                if ((int)($req['volunteer_id'] ?? 0) !== $uid) {
                    Router::json(['error' => 'Доступ запрещён: завершить можно только свою заявку'], 403);
                }
                $completingVolunteerId = $uid;
            } elseif (!in_array($role, ['admin', 'coordinator'], true)) {
                Router::json(['error' => 'Доступ запрещён: завершать заявки могут волонтёры и координаторы'], 403);
            }
        } elseif (!AuthService::isDevMode()) {
            AuthService::requireAuth();
        }

        if ($req['status'] !== 'in_progress') {
            Router::json(['error' => 'Завершить можно только заявку, находящуюся в работе'], 400);
        }

        $failedAttempts = (int)($req['failed_attempts'] ?? 0);
        if ($failedAttempts >= 5) {
            Logger::security("Attempt to complete locked request #{$id} with >= 5 failed attempts");
            Router::json([
                'error' => 'Превышено максимальное количество попыток ввода секретного слова (5 попыток). Заявка заблокирована для проверки координатором.'
            ], 400);
        }

        if ($secretWord === '') {
            Router::json(['error' => 'Для завершения заявки необходимо ввести проверочное кодовое слово от подопечного'], 400);
        }

        if (!SecurityService::verifyCode($secretWord, (string)$req['secret_code'])) {
            $failedAttempts = HelpRequest::registerFailedAttempt((int)$id);
            Logger::security("Invalid secret code entered for request #{$id}. Attempt {$failedAttempts}/5");

            if ($failedAttempts >= 5) {
                Router::json([
                    'error' => 'Превышено максимальное количество попыток ввода секретного слова (5 попыток). Заявка заблокирована для проверки координатором.'
                ], 400);
            }

            $remaining = max(0, 5 - $failedAttempts);
            Router::json([
                'error' => "Неверный проверочный код безопасности! Кодовое слово не совпадает. Осталось попыток: {$remaining}"
            ], 400);
        }

        // Proof-of-Presence Geofencing Check: Valid GPS coordinates strictly required
        $coordsMissing = !isset($input['latitude']) || !isset($input['longitude']);
        $hasNullCoords = (array_key_exists('latitude', $input) && $input['latitude'] === null)
            || (array_key_exists('longitude', $input) && $input['longitude'] === null);
        $hasInvalidCoords = (isset($input['latitude']) && !is_numeric($input['latitude']))
            || (isset($input['longitude']) && !is_numeric($input['longitude']));

        if ($coordsMissing || $hasNullCoords || $hasInvalidCoords) {
            Router::json(['error' => 'GPS-координаты волонтёра обязательны для подтверждения выполнения заявки'], 400);
        }

        $vLat = (float)$input['latitude'];
        $vLon = (float)$input['longitude'];

        if ($vLat < -90.0 || $vLat > 90.0 || $vLon < -180.0 || $vLon > 180.0) {
            Router::json(['error' => 'Некорректные GPS-координаты волонтёра'], 400);
        }

        // Resolve target coordinates
        $targetLat = (float)$req['latitude'];
        $targetLon = (float)$req['longitude'];

        if (!empty($req['elderly_id'])) {
            $elderly = ElderlyProfile::find((int)$req['elderly_id']);
            if ($elderly && !empty($elderly['latitude']) && !empty($elderly['longitude'])) {
                $targetLat = (float)$elderly['latitude'];
                $targetLon = (float)$elderly['longitude'];
            }
        }

        $distMeters = GeoMatchingService::calculateDistanceMeters($vLat, $vLon, $targetLat, $targetLon);
        $allowNoGpsOverride = !empty($input['no_gps_override']) || !empty($input['secret_word_only']);

        if ($distMeters > 150.0 && !$allowNoGpsOverride) {
            $distFormatted = (int)round($distMeters);
            Logger::warning("Geofence breach for request #{$id}: volunteer is {$distFormatted}m away from target (>150m)");
            Router::json([
                'error' => "Волонтёр находится вне допустимой зоны (расстояние: {$distFormatted} м > 150 м)",
                'actual_distance_meters' => $distMeters,
                'max_allowed_meters' => 150.0,
                'can_override_with_secret' => true
            ], 400);
        }

        if ($distMeters > 150.0 && $allowNoGpsOverride) {
            $distFormatted = (int)round($distMeters);
            Logger::info("Request #{$id} completed via secret word override with discrepancy {$distFormatted}m (elderly without GPS / desktop test)");
        }

        $completedLat = $vLat;
        $completedLon = $vLon;
        $distanceToTarget = round($distMeters, 1);

        // Conditional update is the concurrency guard: only the first completion
        // of an in-progress request can transition it to completed.
        $completed = HelpRequest::completeIfInProgress(
            (int)$id,
            $completingVolunteerId,
            $completedLat,
            $completedLon,
            $distanceToTarget
        );
        if (!$completed) {
            Router::json(['error' => 'Заявка уже завершена или более не находится в работе'], 400);
        }

        // Add karma points to volunteer (atomic increment prevents lost updates)
        if ($req['volunteer_id']) {
            VolunteerProfile::addCompletion((int)$req['volunteer_id'], 50);
        }

        Logger::info("Request #{$id} successfully completed. Secret code confirmed" . ($distanceToTarget !== null ? " at {$distanceToTarget}m." : "."));

        $response = [
            'success' => true,
            'message' => 'Заявка успешно выполнена и подтверждена'
        ];
        if ($distanceToTarget !== null) {
            $response['distance_meters'] = $distanceToTarget;
        }
        if ($allowNoGpsOverride && $distMeters > 150.0) {
            $response['verified_by'] = 'secret_word_override';
        }

        Router::json($response);
    }

    public function cancel(string $id): void
    {
        $reqId = (int)$id;
        $req = HelpRequest::find($reqId);
        if (!$req) {
            Router::json(['error' => 'Заявка не найдена'], 404);
        }

        $currentUser = AuthService::getCurrentUser();
        if ($currentUser) {
            $role = $currentUser['role_code'] ?? '';
            $uid = (int)$currentUser['user_id'];
            $allowed = in_array($role, ['admin', 'coordinator'], true)
                || ($role === 'elderly' && (int)$req['elderly_id'] === $uid)
                || ($role === 'volunteer' && (int)($req['volunteer_id'] ?? 0) === $uid);
            if (!$allowed) {
                Router::json(['error' => 'Доступ запрещён: нельзя отменить чужую заявку'], 403);
            }
        } elseif (!AuthService::isDevMode()) {
            AuthService::requireAuth();
        }

        if (!in_array($req['status'], ['open', 'in_progress'], true)) {
            Router::json(['error' => 'Отменить можно только открытую или выполняющуюся заявку'], 409);
        }

        HelpRequest::updateStatus($reqId, 'cancelled');
        Logger::info("Request #{$reqId} cancelled");

        Router::json([
            'success' => true,
            'message' => 'Заявка отменена'
        ]);
    }
}
