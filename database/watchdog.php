<?php

declare(strict_types=1);

/**
 * Periodic background watchdog.
 *
 * The coordinator UI calls the same service on demand, but overdue flags must
 * also advance while nobody has the dashboard open. Started by the production
 * entrypoint (WATCHDOG_ENABLED=true) and safe to run manually.
 */

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Logger.php';
require_once __DIR__ . '/../app/Core/Model.php';
require_once __DIR__ . '/../app/Models/HelpRequest.php';
require_once __DIR__ . '/../app/Services/TaskWatchdogService.php';

use App\Services\TaskWatchdogService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

try {
    $result = TaskWatchdogService::checkOverdueTasks();
    printf(
        "[WATCHDOG] %s scanned=%d overdue=%d flagged=%d\n",
        date('Y-m-d H:i:s'),
        (int)($result['scanned_count'] ?? 0),
        (int)($result['overdue_found'] ?? 0),
        (int)($result['tasks_flagged'] ?? 0)
    );
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[WATCHDOG] failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
