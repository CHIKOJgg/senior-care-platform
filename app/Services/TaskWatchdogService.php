<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Models\HelpRequest;
use PDO;

class TaskWatchdogService
{
    public const TIMEOUT_PHARMACY_SECONDS = 3600;   // 60 minutes
    public const TIMEOUT_GROCERIES_SECONDS = 7200;  // 120 minutes
    public const TIMEOUT_DEFAULT_SECONDS = 10800;   // 180 minutes

    /**
     * Category name/code matching to timeout duration in seconds.
     */
    public static function getTimeoutForCategory(string $category): int
    {
        $cat = mb_strtolower(trim($category), 'UTF-8');

        if (in_array($cat, ['pharmacy', 'medical', 'medicine', 'аптека', 'лекарства', 'медикаменты', 'лекарства из аптеки'], true)) {
            return self::TIMEOUT_PHARMACY_SECONDS;
        }

        if (in_array($cat, ['groceries', 'grocery', 'food', 'продукты', 'магазин', 'еда', 'покупка продуктов'], true)) {
            return self::TIMEOUT_GROCERIES_SECONDS;
        }

        return self::TIMEOUT_DEFAULT_SECONDS;
    }

    /**
     * Returns timeout in minutes for a category.
     */
    public static function getTimeoutMinutesForCategory(string $category): int
    {
        return (int)(self::getTimeoutForCategory($category) / 60);
    }

    /**
     * Scans in-progress tasks, checks execution timeout, sets is_overdue = 1, and logs coordinator warnings.
     *
     * @param int|null $currentTime Optional unix timestamp for deterministic testing
     * @return array<string, mixed>
     */
    public static function checkOverdueTasks(?int $currentTime = null): array
    {
        $now = $currentTime ?? time();
        $pdo = Database::getConnection();

        $sql = "SELECT hr.*, 
                       c.code as category_code, c.title as category_title,
                       eu.name as elderly_name, eu.phone as elderly_phone,
                       vu.name as volunteer_name, vu.phone as volunteer_phone
                FROM help_requests hr
                LEFT JOIN categories c ON c.id = hr.category_id
                LEFT JOIN users eu ON eu.id = hr.elderly_id
                LEFT JOIN users vu ON vu.id = hr.volunteer_id
                WHERE hr.status = 'in_progress'";

        $stmt = $pdo->query($sql);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $scannedCount = count($tasks);
        $overdueFound = 0;
        $tasksFlagged = 0;
        $overdueTasks = [];

        foreach ($tasks as $task) {
            $catIdentifier = (string)($task['category_code'] ?? $task['category_title'] ?? 'default');
            $timeoutSeconds = self::getTimeoutForCategory($catIdentifier);
            $timeoutMinutes = (int)($timeoutSeconds / 60);

            // Calculate elapsed time from assigned_at (or created_at fallback)
            $startTimeStr = $task['assigned_at'] ?: $task['created_at'];
            $startTimestamp = !empty($startTimeStr) ? strtotime((string)$startTimeStr) : $now;
            if ($startTimestamp === false) {
                $startTimestamp = $now;
            }

            $elapsedSeconds = max(0, $now - $startTimestamp);
            $elapsedMinutes = (int)round($elapsedSeconds / 60);
            $isOverdue = ($elapsedSeconds > $timeoutSeconds);

            if ($isOverdue) {
                $overdueFound++;
                $overdueByMinutes = max(0, $elapsedMinutes - $timeoutMinutes);

                // Update database flag if not already flagged
                if ((int)$task['is_overdue'] !== 1) {
                    $upd = $pdo->prepare("UPDATE help_requests SET is_overdue = 1 WHERE id = :id");
                    $upd->execute(['id' => $task['id']]);
                    $tasksFlagged++;
                }

                $task['timeout_threshold_minutes'] = $timeoutMinutes;
                $task['elapsed_minutes'] = $elapsedMinutes;
                $task['overdue_by_minutes'] = $overdueByMinutes;
                $task['is_overdue'] = 1;

                Logger::warning("TaskWatchdog: Task #{$task['id']} ('{$task['title']}') is overdue by {$overdueByMinutes} min. Volunteer: #{$task['volunteer_id']}, Elderly: #{$task['elderly_id']}");

                $overdueTasks[] = $task;
            }
        }

        return [
            'success' => true,
            'message' => 'Проверка зависших заявок завершена успешно',
            'scanned_count' => $scannedCount,
            'overdue_found' => $overdueFound,
            'tasks_flagged' => $tasksFlagged,
            'overdue_tasks' => $overdueTasks,
            'timestamp' => date('Y-m-d H:i:s', $now)
        ];
    }

    /**
     * Get all currently overdue tasks (status = 'in_progress' AND is_overdue = 1)
     */
    public static function getOverdueTasks(): array
    {
        $pdo = Database::getConnection();
        $now = time();

        $sql = "SELECT hr.*, 
                       c.code as category_code, c.title as category_title,
                       eu.name as elderly_name, eu.phone as elderly_phone,
                       vu.name as volunteer_name, vu.phone as volunteer_phone
                FROM help_requests hr
                LEFT JOIN categories c ON c.id = hr.category_id
                LEFT JOIN users eu ON eu.id = hr.elderly_id
                LEFT JOIN users vu ON vu.id = hr.volunteer_id
                WHERE hr.status = 'in_progress' AND hr.is_overdue = 1
                ORDER BY hr.assigned_at ASC";

        $stmt = $pdo->query($sql);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($tasks as &$task) {
            $catIdentifier = (string)($task['category_code'] ?? $task['category_title'] ?? 'default');
            $timeoutSeconds = self::getTimeoutForCategory($catIdentifier);
            $timeoutMinutes = (int)($timeoutSeconds / 60);

            $startTimeStr = $task['assigned_at'] ?: $task['created_at'];
            $startTimestamp = !empty($startTimeStr) ? strtotime((string)$startTimeStr) : $now;
            if ($startTimestamp === false) {
                $startTimestamp = $now;
            }

            $elapsedSeconds = max(0, $now - $startTimestamp);
            $elapsedMinutes = (int)round($elapsedSeconds / 60);
            $overdueByMinutes = max(0, $elapsedMinutes - $timeoutMinutes);

            $task['timeout_threshold_minutes'] = $timeoutMinutes;
            $task['elapsed_minutes'] = $elapsedMinutes;
            $task['overdue_by_minutes'] = $overdueByMinutes;
        }
        unset($task);

        return $tasks;
    }

    /**
     * Reassign an overdue or stalled task back to open neighborhood feed
     */
    public static function reassignTask(int $requestId, ?int $coordinatorId = null): array
    {
        $req = HelpRequest::find($requestId);
        if (!$req) {
            return [
                'success' => false,
                'error' => 'Заявка не найдена'
            ];
        }

        HelpRequest::update($requestId, [
            'volunteer_id' => null,
            'status' => 'open',
            'is_overdue' => 0,
            'assigned_at' => null
        ]);

        Logger::info("Coordinator " . ($coordinatorId ? "#{$coordinatorId}" : "") . " reassigned stalled request #{$requestId} back to neighborhood feed");

        return [
            'success' => true,
            'message' => "Заявка #{$requestId} возвращена в общую ленту района для назначения другого волонтёра",
            'request_id' => $requestId,
            'status' => 'open'
        ];
    }
}
