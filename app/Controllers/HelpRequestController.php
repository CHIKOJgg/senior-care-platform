<?php

namespace App\Controllers;

use App\Core\Router;
use App\Models\HelpRequest;
use App\Models\VolunteerProfile;
use App\Services\GeoMatchingService;
use App\Services\SecurityService;

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

        Router::json(['success' => true, 'data' => $req]);
    }

    public function store(): void
    {
        $input = Router::getJsonInput();
        $elderlyId = (int)($input['elderly_id'] ?? 1);
        $categoryId = (int)($input['category_id'] ?? 1);
        $title = trim($input['title'] ?? '');
        $description = trim($input['description'] ?? '');
        $address = trim($input['address'] ?? 'г. Минск, пр-т Независимости, 45');
        $lat = (float)($input['latitude'] ?? 53.9168);
        $lon = (float)($input['longitude'] ?? 27.5862);

        if (!$title) {
            Router::json(['error' => 'Укажите, какая помощь требуется'], 400);
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
        $volunteerId = (int)($input['volunteer_id'] ?? 2);

        $req = HelpRequest::find((int)$id);
        if (!$req) {
            Router::json(['error' => 'Заявка не найдена'], 404);
        }

        if ($req['status'] !== 'open') {
            Router::json(['error' => 'Заявка уже взята другим волонтёром или завершена'], 400);
        }

        HelpRequest::update((int)$id, [
            'volunteer_id' => $volunteerId,
            'status' => 'in_progress'
        ]);

        Router::json([
            'success' => true,
            'message' => 'Заявка переведена в статус «В работе». Свяжитесь с подопечным для уточнения деталей.',
            'secret_code' => $req['secret_code']
        ]);
    }

    public function complete(string $id): void
    {
        $input = Router::getJsonInput();
        $secretWord = trim($input['secret_code'] ?? '');

        $req = HelpRequest::find((int)$id);
        if (!$req) {
            Router::json(['error' => 'Заявка не найдена'], 404);
        }

        if ($secretWord && !SecurityService::verifyCode($secretWord, $req['secret_code'])) {
            Router::json(['error' => 'Проверочный код безопасности не совпадает!'], 400);
        }

        HelpRequest::update((int)$id, [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s')
        ]);

        // Add karma points to volunteer
        if ($req['volunteer_id']) {
            $vp = VolunteerProfile::find($req['volunteer_id']);
            if ($vp) {
                VolunteerProfile::update($req['volunteer_id'], [
                    'points' => (int)$vp['points'] + 50,
                    'completed_tasks_count' => (int)$vp['completed_tasks_count'] + 1
                ]);
            }
        }

        Router::json([
            'success' => true,
            'message' => 'Заявка успешно завершена! Волонтёру начислено +50 баллов опыта.'
        ]);
    }
}
