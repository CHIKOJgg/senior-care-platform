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
        } elseif (getenv('APP_ENV') !== 'production' && isset($_GET['elderly_id'])) {
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
        } elseif (getenv('APP_ENV') !== 'production' && isset($_GET['volunteer_id'])) {
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
        } elseif (getenv('APP_ENV') !== 'production') {
            $elderlyId = (int)($input['elderly_id'] ?? 1);
        }
        if ($elderlyId === null) {
            AuthService::requireAuth(['elderly']);
            return;
        }

        $categoryId = (int)($input['category_id'] ?? 1);
        $title = trim($input['title'] ?? '');
        $description = trim($input['description'] ?? '');
        $address = trim((string)($input['address'] ?? ''));

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

        if (!$title && !$description) {
            Router::json(['error' => 'Укажите или надиктуйте, какая помощь требуется'], 400);
        }

        if (!$title) {
            $title = mb_substr($description, 0, 60);
        }


        if (mb_strlen($title, 'UTF-8') > 255) {
            $title = mb_substr($title, 0, 255, 'UTF-8');
        }
        if (mb_strlen($description, 'UTF-8') > 5000) {
            $description = mb_substr($description, 0, 5000, 'UTF-8');
        }


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


        $gpsCaptured = filter_var($input['gps_captured'] ?? false, FILTER_VALIDATE_BOOLEAN) && $hasCoords;
        if ($elderlyId && $gpsCaptured) {
            try {
                $profileUpdate = [
                    'latitude' => $lat,
                    'longitude' => $lon,
                    'region_code' => $regionCode,
                ];
                if ($address !== '') {
                    $profileUpdate['address'] = $address;
                }
                ElderlyProfile::update((int)$elderlyId, $profileUpdate);
            } catch (\Throwable $e) {
                Logger::warning("Failed to refresh elderly GPS profile for user #{$elderlyId}: " . $e->getMessage());
            }
        }


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
        } elseif (getenv('APP_ENV') !== 'production') {
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


        $volunteer = VolunteerProfile::find($volunteerId);
        if (!$volunteer) {
            Router::json(['error' => 'Профиль волонтёра не найден'], 404);
        }

        $verificationStatus = (string)($volunteer['verification_status'] ?? '');
        $rating = (float)($volunteer['rating'] ?? 0);
        if ($verificationStatus === 'BLOCKED_LOW_RATING' || $rating < 3.0) {
            Logger::security("Blocked low-rating volunteer #{$volunteerId} tried to accept request #{$id}");
            Router::json([
                'error' => 'Из-за низкого рейтинга вы временно не можете принимать задания. Обратитесь в ТЦСОН или позвоните в поддержку по номеру +375441111110 для восстановления прав.',
                'code' => 'BLOCKED_LOW_RATING'
            ], 403);
        }

        if ((int)$volunteer['is_verified'] !== 1) {
            Logger::security("Unverified volunteer #{$volunteerId} tried to accept request #{$id}");
            Router::json([
                'error' => 'Вы не можете брать заявки: ваш профиль волонтёра ещё не прошёл верификацию координатором/администратором.'
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

        $currentUser = AuthService::getCurrentUser();
        if ($currentUser) {
            $role = $currentUser['role_code'] ?? '';
            $uid = (int)$currentUser['user_id'];
            if ($role === 'volunteer' && (int)($req['volunteer_id'] ?? 0) !== $uid) {
                Router::json(['error' => 'Доступ запрещён: завершить можно только свою заявку'], 403);
            }
        } elseif (getenv('APP_ENV') === 'production') {
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
            $failedAttempts++;
            HelpRequest::update((int)$id, ['failed_attempts' => $failedAttempts]);
            Logger::security("Invalid secret code entered for request #{$id}. Attempt {$failedAttempts}/5");

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



        $targetLat = (float)$req['latitude'];
        $targetLon = (float)$req['longitude'];

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

    public function rate(string $id): void
    {
        $currentUser = AuthService::requireAuth(['elderly', 'admin', 'coordinator']);
        $reqId = (int)$id;
        $req = HelpRequest::find($reqId);

        if (!$req) {
            Router::json(['error' => 'Заявка не найдена'], 404);
        }

        $role = $currentUser['role_code'] ?? '';
        $uid = (int)$currentUser['user_id'];
        if ($role === 'elderly' && (int)$req['elderly_id'] !== $uid) {
            Router::json(['error' => 'Недостаточно прав для оценки этой заявки'], 403);
        }

        if (($req['status'] ?? '') !== 'completed') {
            Router::json(['error' => 'Оценить можно только выполненную заявку'], 400);
        }

        if (!empty($req['rating_score'])) {
            Router::json(['error' => 'Заявка уже оценена', 'rating_score' => (int)$req['rating_score']], 409);
        }

        $input = Router::getJsonInput();
        $rating = isset($input['rating']) ? (int)$input['rating'] : 0;
        if ($rating < 1 || $rating > 5) {
            Router::json(['error' => 'Оценка должна быть от 1 до 5'], 400);
        }

        HelpRequest::update($reqId, ['rating_score' => $rating]);

        $volunteerId = (int)($req['volunteer_id'] ?? 0);
        if ($volunteerId > 0) {
            try {
                $avgRow = HelpRequest::query(
                    "SELECT AVG(rating_score) as avg_rating, COUNT(*) as cnt
                     FROM help_requests
                     WHERE volunteer_id = :vid AND rating_score IS NOT NULL",
                    ['vid' => $volunteerId]
                );
                $avg = $avgRow[0]['avg_rating'] ?? null;
                if ($avg !== null) {
                    $newRating = round((float)$avg, 2);
                    $update = ['rating' => $newRating];
                    if ($newRating < 3.0) {
                        $update['is_verified'] = 0;
                        $update['verification_status'] = 'BLOCKED_LOW_RATING';
                        $update['badge_token'] = null;
                        $update['badge_expires_at'] = null;
                    }
                    VolunteerProfile::update($volunteerId, $update);
                }
            } catch (\Throwable $e) {
                Logger::warning("Failed to recalculate volunteer rating for #{$volunteerId}: " . $e->getMessage());
            }
        }

        Logger::info("Request #{$reqId} rated {$rating}/5 by user #{$uid}");
        Router::json([
            'success' => true,
            'message' => 'Спасибо за оценку',
            'rating_score' => $rating
        ]);
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
        } elseif (getenv('APP_ENV') === 'production') {
            AuthService::requireAuth();
        }

        HelpRequest::updateStatus($reqId, 'cancelled');
        Logger::info("Request #{$reqId} cancelled");

        Router::json([
            'success' => true,
            'message' => 'Заявка отменена'
        ]);
    }
}
