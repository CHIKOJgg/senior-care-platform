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

class HelpRequestController
{
    public function index(): void
    {
        $requests = HelpRequest::getActiveRequests();
        $volLat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
        $volLon = isset($_GET['lon']) ? (float)$_GET['lon'] : null;

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
        $elderlyId = ($currentUser && $currentUser['role_code'] === 'elderly') 
            ? (int)$currentUser['user_id'] 
            : (isset($_GET['elderly_id']) ? (int)$_GET['elderly_id'] : 1);

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
        $volunteerId = ($currentUser && $currentUser['role_code'] === 'volunteer')
            ? (int)$currentUser['user_id']
            : (isset($_GET['volunteer_id']) ? (int)$_GET['volunteer_id'] : 2);

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
        if (!RateLimiter::check("create_request:{$ip}", 20, 60)) {
            Router::json(['error' => 'Слишком много запросов на создание заявок. Пожалуйста, подождите минуту.'], 429);
        }

        $input = Router::getJsonInput();
        $currentUser = AuthService::getCurrentUser();
        $elderlyId = ($currentUser && $currentUser['role_code'] === 'elderly') 
            ? (int)$currentUser['user_id'] 
            : (int)($input['elderly_id'] ?? 1);

        $categoryId = (int)($input['category_id'] ?? 1);
        $title = trim($input['title'] ?? '');
        $description = trim($input['description'] ?? '');
        $address = trim($input['address'] ?? 'г. Минск, пр-т Независимости, 45, кв. 12');
        $lat = (float)($input['latitude'] ?? 53.9168);
        $lon = (float)($input['longitude'] ?? 27.5862);

        if (!$title && !$description) {
            Router::json(['error' => 'Укажите или надиктуйте, какая помощь требуется'], 400);
        }

        if (!$title) {
            $title = mb_substr($description, 0, 60);
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
        $volunteerId = ($currentUser && $currentUser['role_code'] === 'volunteer') 
            ? (int)$currentUser['user_id'] 
            : (int)($input['volunteer_id'] ?? 2);

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

        HelpRequest::update((int)$id, [
            'volunteer_id' => $volunteerId,
            'status' => 'in_progress',
            'assigned_at' => date('Y-m-d H:i:s')
        ]);

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
        $secretWord = trim((string)($input['secret_code'] ?? ''));

        $req = HelpRequest::find((int)$id);
        if (!$req) {
            Router::json(['error' => 'Заявка не найдена'], 404);
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
            $failedAttempts++;
            HelpRequest::update((int)$id, ['failed_attempts' => $failedAttempts]);
            Logger::security("Invalid secret code '{$secretWord}' entered for request #{$id}. Attempt {$failedAttempts}/5");

            if ($failedAttempts >= 5) {
                Router::json([
                    'error' => 'Превышено максимальное количество попыток ввода секретного слова (5 попыток). Заявка заблокирована для проверки координатором.'
                ], 400);
            }

            $remaining = 5 - $failedAttempts;
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
        if ($distMeters > 150.0) {
            $distFormatted = (int)round($distMeters);
            Logger::warning("Geofence breach for request #{$id}: volunteer is {$distFormatted}m away from target (>150m)");
            Router::json([
                'error' => "Волонтёр находится вне допустимой зоны (расстояние: {$distFormatted} м > 150 м)",
                'actual_distance_meters' => $distMeters,
                'max_allowed_meters' => 150.0
            ], 400);
        }

        $completedLat = $vLat;
        $completedLon = $vLon;
        $distanceToTarget = round($distMeters, 1);

        HelpRequest::update((int)$id, [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
            'failed_attempts' => 0,
            'completed_lat' => $completedLat,
            'completed_lon' => $completedLon,
            'distance_to_target' => $distanceToTarget
        ]);

        // Add karma points to volunteer
        if ($req['volunteer_id']) {
            $vp = VolunteerProfile::find((int)$req['volunteer_id']);
            if ($vp) {
                VolunteerProfile::update((int)$req['volunteer_id'], [
                    'points' => (int)$vp['points'] + 50,
                    'completed_tasks_count' => (int)$vp['completed_tasks_count'] + 1
                ]);
            }
        }

        Logger::info("Request #{$id} successfully completed. Secret code confirmed" . ($distanceToTarget !== null ? " at {$distanceToTarget}m." : "."));

        $response = [
            'success' => true,
            'message' => 'Заявка успешно выполнена и подтверждена'
        ];
        if ($distanceToTarget !== null) {
            $response['distance_meters'] = $distanceToTarget;
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

        HelpRequest::updateStatus($reqId, 'cancelled');
        Logger::info("Request #{$reqId} cancelled");

        Router::json([
            'success' => true,
            'message' => 'Заявка отменена'
        ]);
    }
}
