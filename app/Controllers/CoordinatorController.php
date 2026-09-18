<?php

namespace App\Controllers;

use App\Core\Router;
use App\Models\HelpRequest;
use App\Models\VolunteerProfile;
use App\Models\FraudAlert;
use App\Models\User;

class CoordinatorController
{
    public function stats(): void
    {
        $totalRequests = count(HelpRequest::all());
        $activeRequests = count(HelpRequest::where('status', 'open'));
        $volunteers = VolunteerProfile::all();
        $verifiedCount = 0;
        $pendingCount = 0;

        foreach ($volunteers as $v) {
            if ($v['is_verified']) $verifiedCount++;
            else $pendingCount++;
        }

        Router::json([
            'success' => true,
            'stats' => [
                'total_requests' => $totalRequests,
                'active_requests' => $activeRequests,
                'verified_volunteers' => $verifiedCount,
                'pending_volunteers' => $pendingCount
            ]
        ]);
    }

    public function pendingVolunteers(): void
    {
        $sql = "SELECT vp.*, u.name, u.phone, u.created_at as registered_at
                FROM volunteer_profiles vp
                JOIN users u ON u.id = vp.user_id
                WHERE vp.is_verified = 0";
        $list = VolunteerProfile::query($sql);

        Router::json([
            'success' => true,
            'data' => $list
        ]);
    }

    public function verifyVolunteer(string $id): void
    {
        VolunteerProfile::update((int)$id, ['is_verified' => 1]);
        Router::json([
            'success' => true,
            'message' => 'Волонтёр успешно верифицирован и допущен к заявкам'
        ]);
    }

    public function createAlert(): void
    {
        $input = Router::getJsonInput();
        $title = trim($input['title'] ?? '');
        $description = trim($input['description'] ?? '');
        $severity = $input['severity'] ?? 'warning';

        if (!$title || !$description) {
            Router::json(['error' => 'Заполните заголовок и текст предупреждения'], 400);
        }

        $id = FraudAlert::create([
            'title' => $title,
            'description' => $description,
            'severity' => $severity,
            'is_active' => 1
        ]);

        Router::json([
            'success' => true,
            'message' => 'Предупреждение опубликовано на главных экранах подопечных',
            'alert_id' => $id
        ], 201);
    }

    public function leaderboard(): void
    {
        $sql = "SELECT vp.user_id, vp.points, vp.rating, vp.completed_tasks_count, u.name
                FROM volunteer_profiles vp
                JOIN users u ON u.id = vp.user_id
                WHERE vp.is_verified = 1
                ORDER BY vp.points DESC
                LIMIT 10";
        $top = VolunteerProfile::query($sql);

        Router::json([
            'success' => true,
            'data' => $top
        ]);
    }
}
