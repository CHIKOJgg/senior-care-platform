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
use App\Models\EducationalMaterial;
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

        // Category breakdown: single query to prevent N+1 overhead
        $categoryStats = Category::query(
            "SELECT c.id, c.code, c.title, c.icon, COUNT(r.id) AS count
             FROM categories c
             LEFT JOIN help_requests r ON r.category_id = c.id
             GROUP BY c.id, c.code, c.title, c.icon
             ORDER BY c.title ASC"
        );
        foreach ($categoryStats as &$cs) {
            $cs['id'] = (int)$cs['id'];
            $cs['count'] = (int)$cs['count'];
        }
        unset($cs);

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
        $minRating = (isset($_GET['min_rating']) && is_numeric($_GET['min_rating'])) ? (float)$_GET['min_rating'] : null;
        $list = VolunteerProfile::getAllWithDetails();
        if ($minRating !== null) {
            $list = array_values(array_filter($list, function ($v) use ($minRating) {
                return (float)($v['rating'] ?? 0) >= $minRating;
            }));
        }
        Router::json([
            'success' => true,
            'count' => count($list),
            'data' => $list,
            'filter' => ['min_rating' => $minRating]
        ]);
    }

    // --- Category management (admin adds types of help requests) ---
    /**
     * Public catalogue of help-request types, used by the request form and admin UI.
     */
    public function categories(): void
    {
        $list = Category::query(
            "SELECT c.*, (SELECT COUNT(*) FROM help_requests r WHERE r.category_id = c.id) AS requests_count
             FROM categories c
             ORDER BY c.title ASC"
        );
        Router::json([
            'success' => true,
            'count' => count($list),
            'data' => $list
        ]);
    }

    public function createCategory(): void
    {
        $admin = AuthService::requireAuth(['admin', 'coordinator']);
        $input = Router::getJsonInput();
        $code = trim((string)($input['code'] ?? ''));
        $title = trim((string)($input['title'] ?? ''));
        $icon = trim((string)($input['icon'] ?? 'help-circle'));
        $description = trim((string)($input['description'] ?? ''));

        if ($code === '' || $title === '') {
            Router::json(['error' => 'Укажите code и title категории'], 400);
        }
        if (Category::where('code', $code)) {
            Router::json(['error' => 'Категория с таким кодом уже существует'], 409);
        }

        $id = Category::create([
            'code' => $code,
            'title' => $title,
            'icon' => $icon,
            'description' => $description
        ]);
        Logger::info("Category '{$code}' created by Admin #{$admin['user_id']}");
        Router::json(['success' => true, 'category_id' => $id], 201);
    }

    public function updateCategory(string $id): void
    {
        AuthService::requireAuth(['admin', 'coordinator']);
        $catId = (int)$id;
        $cat = Category::find($catId);
        if (!$cat) {
            Router::json(['error' => 'Категория не найдена'], 404);
        }
        $input = Router::getJsonInput();
        $data = [];
        foreach (['code', 'title', 'icon', 'description'] as $f) {
            if (isset($input[$f])) {
                $data[$f] = trim((string)$input[$f]);
            }
        }
        if (empty($data)) {
            Router::json(['error' => 'Нет данных для обновления'], 400);
        }
        Category::update($catId, $data);
        Router::json(['success' => true, 'message' => 'Категория обновлена']);
    }

    public function deleteCategory(string $id): void
    {
        AuthService::requireAuth(['admin', 'coordinator']);
        $catId = (int)$id;
        $cat = Category::find($catId);
        if (!$cat) {
            Router::json(['error' => 'Категория не найдена'], 404);
        }
        // Prevent deleting categories in use (efficient single-row check)
        $inUse = HelpRequest::query("SELECT 1 FROM help_requests WHERE category_id = :cat_id LIMIT 1", ['cat_id' => $catId]);
        if (!empty($inUse)) {
            Router::json(['error' => 'Нельзя удалить категорию, используемую в заявках'], 409);
        }
        Category::delete($catId);
        Router::json(['success' => true, 'message' => 'Категория удалена']);
    }

    // --- Rehabilitation for low-rated volunteers ---
    public function rehabilitateVolunteer(string $id): void
    {
        $admin = AuthService::requireAuth(['admin', 'coordinator']);
        $uid = (int)$id;
        $vp = VolunteerProfile::find($uid);
        if (!$vp) {
            Router::json(['error' => 'Профиль волонтёра не найден'], 404);
        }
        $threshold = 3.5;
        $currentRating = (float)($vp['rating'] ?? 5.0);
        $needsRehab = $currentRating < $threshold;

        // Assign educational materials set for retraining
        $materials = EducationalMaterial::all();
        $rehabMaterials = array_slice($materials, 0, 5);

        // Reset rating to baseline and mark as pending re-verification if low
        if ($needsRehab) {
            VolunteerProfile::update($uid, [
                'rating' => 4.0,
                'verification_status' => 'REHABILITATION',
                'is_verified' => 0
            ]);
        } else {
            VolunteerProfile::update($uid, [
                'verification_status' => 'ACTIVE'
            ]);
        }

        Logger::info("Volunteer #{$uid} rehabilitation initiated by Admin #{$admin['user_id']}. Rating was {$currentRating}");
        Router::json([
            'success' => true,
            'volunteer_id' => $uid,
            'previous_rating' => $currentRating,
            'needs_rehabilitation' => $needsRehab,
            'new_status' => $needsRehab ? 'REHABILITATION' : 'ACTIVE',
            'training_materials' => $rehabMaterials,
            'message' => $needsRehab
                ? 'Волонтёр направлен на переобучение (реабилитация). Назначены обучающие материалы.'
                : 'Рейтинг в норме. Волонтёр остаётся активным.'
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

        $region = isset($_GET['region']) ? strtoupper(trim((string)$_GET['region'])) : '';
        if ($region !== '' && \App\Services\RegionService::exists($region)) {
            $requests = array_values(array_filter(
                $requests,
                fn($r) => \App\Services\RegionService::normalize($r['region_code'] ?? null) === $region
            ));
        }
        foreach ($requests as &$req) {
            $req['region_name'] = \App\Services\RegionService::name($req['region_code'] ?? null);
        }
        unset($req);

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
