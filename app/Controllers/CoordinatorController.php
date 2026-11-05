<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Router;
use App\Core\Logger;
use App\Services\AuthService;
use App\Models\HelpRequest;
use App\Models\VolunteerProfile;
use App\Models\FraudAlert;
use App\Models\SosAlert;
use App\Models\Category;
use App\Services\TaskWatchdogService;

class CoordinatorController
{
    public function stats(): void
    {
        AuthService::requireAuth(['admin', 'coordinator']);

        $allRequests = HelpRequest::all();
        $totalRequests = count($allRequests);
        $activeCount = 0;
        $completedCount = 0;
        $cancelledCount = 0;

        foreach ($allRequests as $r) {
            if ($r['status'] === 'open' || $r['status'] === 'in_progress') {
                $activeCount++;
            } elseif ($r['status'] === 'completed') {
                $completedCount++;
            } elseif ($r['status'] === 'cancelled') {
                $cancelledCount++;
            }
        }

        $volunteers = VolunteerProfile::all();
        $totalVolunteers = count($volunteers);
        $verifiedCount = 0;
        $pendingCount = 0;
        $rejectedCount = 0;

        foreach ($volunteers as $v) {
            $status = (int)$v['is_verified'];
            if ($status === 1) {
                $verifiedCount++;
            } elseif ($status === 0) {
                $pendingCount++;
            } else {
                $rejectedCount++;
            }
        }

        // Category breakdown
        $categories = Category::all();
        $categoryStats = [];
        foreach ($categories as $cat) {
            $catRequests = HelpRequest::where('category_id', $cat['id']);
            $categoryStats[] = [
                'id' => $cat['id'],
                'code' => $cat['code'],
                'title' => $cat['title'],
                'icon' => $cat['icon'],
                'count' => count($catRequests)
            ];
        }

        $activeAlerts = count(FraudAlert::getActiveAlerts());

        Router::json([
            'success' => true,
            'stats' => [
                'total_requests' => $totalRequests,
                'active_requests' => $activeCount,
                'completed_requests' => $completedCount,
                'cancelled_requests' => $cancelledCount,
                'total_volunteers' => $totalVolunteers,
                'verified_volunteers' => $verifiedCount,
                'pending_volunteers' => $pendingCount,
                'rejected_volunteers' => $rejectedCount,
                'active_alerts' => $activeAlerts,
                'category_distribution' => $categoryStats
            ]
        ]);
    }

    public function pendingVolunteers(): void
    {
        AuthService::requireAuth(['admin', 'coordinator']);
        $sql = "SELECT vp.*, u.name, u.phone, u.created_at as registered_at 
                FROM volunteer_profiles vp 
                JOIN users u ON u.id = vp.user_id 
                WHERE vp.is_verified = 0 OR vp.verification_status = 'PENDING'
                ORDER BY u.created_at DESC";
        $list = VolunteerProfile::query($sql);
        foreach ($list as &$item) {
            if (empty($item['police_clearance_status'])) {
                $item['police_clearance_status'] = 'NOT_SUBMITTED';
            }
        }
        unset($item);

        Router::json([
            'success' => true,
            'count' => count($list),
            'data' => $list
        ]);
    }

    public function allVolunteers(): void
    {
        AuthService::requireAuth(['admin', 'coordinator']);
        $list = VolunteerProfile::getAllWithDetails();
        Router::json([
            'success' => true,
            'count' => count($list),
            'data' => $list
        ]);
    }

    public function verifyVolunteer(string $id): void
    {
        $admin = AuthService::requireAuth(['admin', 'coordinator']);
        $uid = (int)$id;
        $vp = VolunteerProfile::find($uid);
        if (!$vp) {
            Router::json(['error' => 'Профиль волонтёра не найден'], 404);
        }

        VolunteerProfile::update($uid, [
            'is_verified' => 1,
            'verification_status' => 'VERIFIED'
        ]);
        Logger::info("Volunteer #{$uid} verified by Admin #{$admin['user_id']}");

        Router::json([
            'success' => true,
            'message' => 'Волонтёр успешно верифицирован и допущен к заявкам района.',
            'volunteer_id' => $uid,
            'verification_status' => 'VERIFIED'
        ]);
    }

    public function rejectVolunteer(string $id): void
    {
        $admin = AuthService::requireAuth(['admin', 'coordinator']);
        $uid = (int)$id;
        $vp = VolunteerProfile::find($uid);
        if (!$vp) {
            Router::json(['error' => 'Профиль волонтёра не найден'], 404);
        }

        $input = Router::getJsonInput();
        $reason = (string)($input['reason'] ?? 'Отклонено координатором по результатам проверки документов');

        VolunteerProfile::update($uid, [
            'is_verified' => -1,
            'verification_status' => 'REJECTED',
            'badge_token' => null,
            'badge_expires_at' => null
        ]);
        Logger::info("Volunteer #{$uid} rejected/revoked by Admin #{$admin['user_id']}. Reason: {$reason}");

        Router::json([
            'success' => true,
            'message' => 'Статус волонтёра изменён на «Отклонён». Доступ к заявкам заблокирован.',
            'volunteer_id' => $uid,
            'verification_status' => 'REJECTED',
            'reason' => $reason
        ]);
    }

    public function allRequests(): void
    {
        AuthService::requireAuth(['admin', 'coordinator']);
        $status = $_GET['status'] ?? null;
        $requests = HelpRequest::getAllWithDetails($status);

        Router::json([
            'success' => true,
            'count' => count($requests),
            'data' => $requests
        ]);
    }

    public function updateRequestStatus(string $id): void
    {
        $admin = AuthService::requireAuth(['admin', 'coordinator']);
        $reqId = (int)$id;
        $req = HelpRequest::find($reqId);
        if (!$req) {
            Router::json(['error' => 'Заявка не найдена'], 404);
        }

        $input = Router::getJsonInput();
        $status = $input['status'] ?? '';
        $allowed = ['open', 'in_progress', 'completed', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            Router::json(['error' => 'Недопустимый статус заявки'], 400);
        }

        HelpRequest::updateStatus($reqId, $status);
        Logger::info("Request #{$reqId} status changed to {$status} by Admin #{$admin['user_id']}");

        Router::json([
            'success' => true,
            'message' => "Статус заявки #{$reqId} успешно обновлён на «{$status}»"
        ]);
    }

    public function createAlert(): void
    {
        $admin = AuthService::requireAuth(['admin', 'coordinator']);
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

        Logger::info("New security alert published: '{$title}' by Admin #{$admin['user_id']}");

        Router::json([
            'success' => true,
            'message' => 'Предупреждение опубликовано на главных экранах подопечных',
            'alert_id' => $id
        ], 201);
    }

    public function deleteAlert(string $id): void
    {
        $admin = AuthService::requireAuth(['admin', 'coordinator']);
        $alertId = (int)$id;
        $alert = FraudAlert::find($alertId);
        if (!$alert) {
            Router::json(['error' => 'Оповещение не найдено'], 404);
        }

        FraudAlert::deactivate($alertId);
        Logger::info("Security alert #{$alertId} deactivated by Admin #{$admin['user_id']}");

        Router::json([
            'success' => true,
            'message' => 'Предупреждение деактивировано и скрыто с экранов граждан'
        ]);
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

    /**
     * Active security & SOS alerts for coordinator
     * Route: GET /api/coordinator/alerts
     */
    public function alerts(): void
    {
        AuthService::requireAuth(['admin', 'coordinator']);
        $fraudAlerts = FraudAlert::getActiveAlerts();
        $sosAlerts = SosAlert::getActiveAlerts();

        Router::json([
            'success' => true,
            'count' => count($fraudAlerts) + count($sosAlerts),
            'fraud_alerts' => $fraudAlerts,
            'sos_alerts' => $sosAlerts,
            'data' => array_merge($sosAlerts, $fraudAlerts)
        ]);
    }

    /**
     * Watchdog overdue tasks feed
     * Routes: GET /api/coordinator/watchdog, GET /api/coordinator/tasks/overdue, GET /api/requests/overdue
     */
    public function watchdog(): void
    {
        AuthService::requireAuth(['admin', 'coordinator']);
        TaskWatchdogService::checkOverdueTasks();
        $overdue = TaskWatchdogService::getOverdueTasks();

        Router::json([
            'success' => true,
            'count' => count($overdue),
            'data' => $overdue,
            'overdue_tasks' => $overdue
        ]);
    }

    /**
     * Manually trigger watchdog scan
     * Route: POST /api/tasks/watchdog/run
     */
    public function runWatchdog(): void
    {
        AuthService::requireAuth(['admin', 'coordinator']);
        $result = TaskWatchdogService::checkOverdueTasks();
        Router::json($result);
    }

    /**
     * Reassign overdue/stalled request back to neighborhood feed
     * Route: POST /api/coordinator/requests/{id}/reassign
     */
    public function reassignRequest(string $id): void
    {
        $admin = AuthService::requireAuth(['admin', 'coordinator']);
        $reqId = (int)$id;
        $req = HelpRequest::find($reqId);

        if (!$req) {
            Router::json(['error' => 'Заявка не найдена'], 404);
        }

        $res = TaskWatchdogService::reassignTask($reqId, (int)$admin['user_id']);
        Router::json($res);
    }
}
