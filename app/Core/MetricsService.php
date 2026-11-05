<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Lightweight Prometheus metrics for the PHP-FPM app.
 * HTTP counters are aggregated in a locked JSON file; business gauges come from the DB.
 */
class MetricsService
{
    public static function recordRequest(string $route, int $status, float $duration): void
    {
        if ($route === 'GET /api/metrics') {
            return;
        }

        try {
            $file = self::countersFile();
            $fp = fopen($file, 'c+');
            if (!$fp) {
                return;
            }
            flock($fp, LOCK_EX);
            $raw = stream_get_contents($fp);
            $data = json_decode((string)$raw, true);
            if (!is_array($data)) {
                $data = ['started_at' => time(), 'total' => 0, 'routes' => []];
            }

            $data['total'] = (int)($data['total'] ?? 0) + 1;
            $data['last_request_at'] = time();
            $key = $route;
            if (!isset($data['routes'][$key])) {
                $data['routes'][$key] = [
                    'method' => explode(' ', $route, 2)[0] ?? 'GET',
                    'duration_sum' => 0.0,
                    'statuses' => []
                ];
            }
            $data['routes'][$key]['duration_sum'] = round((float)$data['routes'][$key]['duration_sum'] + $duration, 6);
            $data['routes'][$key]['statuses'][(string)$status] =
                (int)($data['routes'][$key]['statuses'][(string)$status] ?? 0) + 1;

            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        } catch (Throwable $e) {
            // Metrics must never break a request
        }
    }

    public static function render(): string
    {
        $lines = [];
        $lines[] = '# HELP app_info Application build information';
        $lines[] = '# TYPE app_info gauge';
        $lines[] = 'app_info{version="1.0.0",php="' . PHP_VERSION . '"} 1';
        $lines[] = '# HELP app_memory_usage_bytes Current PHP memory usage';
        $lines[] = '# TYPE app_memory_usage_bytes gauge';
        $lines[] = 'app_memory_usage_bytes ' . memory_get_usage(true);

        $data = self::readCounters();
        $startedAt = (int)($data['started_at'] ?? time());
        $lines[] = '# HELP app_metrics_started_timestamp_seconds Unix time when the first request was recorded';
        $lines[] = '# TYPE app_metrics_started_timestamp_seconds gauge';
        $lines[] = 'app_metrics_started_timestamp_seconds ' . $startedAt;
        $lines[] = '# HELP app_http_requests_total Total HTTP requests';
        $lines[] = '# TYPE app_http_requests_total counter';
        $lines[] = '# HELP app_http_request_duration_seconds Request duration';
        $lines[] = '# TYPE app_http_request_duration_seconds summary';

        $routes = is_array($data['routes'] ?? null) ? $data['routes'] : [];
        foreach ($routes as $route => $info) {
            $method = $info['method'] ?? 'GET';
            $sum = (float)($info['duration_sum'] ?? 0);
            $statuses = is_array($info['statuses'] ?? null) ? $info['statuses'] : [];
            $count = array_sum(array_map('intval', $statuses));
            foreach ($statuses as $status => $value) {
                $lines[] = sprintf(
                    'app_http_requests_total{route="%s",method="%s",status="%s"} %d',
                    self::escapeLabel((string)$route),
                    self::escapeLabel((string)$method),
                    self::escapeLabel((string)$status),
                    (int)$value
                );
            }
            $lines[] = sprintf(
                'app_http_request_duration_seconds_sum{route="%s",method="%s"} %s',
                self::escapeLabel((string)$route),
                self::escapeLabel((string)$method),
                $sum
            );
            $lines[] = sprintf(
                'app_http_request_duration_seconds_count{route="%s",method="%s"} %d',
                self::escapeLabel((string)$route),
                self::escapeLabel((string)$method),
                $count
            );
        }

        try {
            $pdo = Database::getConnection();
            $t0 = microtime(true);
            $pdo->query('SELECT 1');
            $lines[] = '# HELP app_db_up Database availability';
            $lines[] = '# TYPE app_db_up gauge';
            $lines[] = 'app_db_up 1';
            $lines[] = '# HELP app_db_latency_seconds Database ping latency';
            $lines[] = '# TYPE app_db_latency_seconds gauge';
            $lines[] = 'app_db_latency_seconds ' . round(microtime(true) - $t0, 6);

            $lines[] = '# HELP app_help_requests Help requests by status';
            $lines[] = '# TYPE app_help_requests gauge';
            foreach (self::countBy($pdo, 'help_requests', 'status') as $status => $count) {
                $lines[] = 'app_help_requests{status="' . self::escapeLabel($status) . '"} ' . $count;
            }

            $lines[] = '# HELP app_sos_alerts SOS alerts by status';
            $lines[] = '# TYPE app_sos_alerts gauge';
            foreach (self::countBy($pdo, 'sos_alerts', 'status') as $status => $count) {
                $lines[] = 'app_sos_alerts{status="' . self::escapeLabel($status) . '"} ' . $count;
            }

            $lines[] = '# HELP app_volunteers Volunteers by verification state';
            $lines[] = '# TYPE app_volunteers gauge';
            $volunteers = $pdo->query("
                SELECT CASE
                    WHEN is_verified = 1 THEN 'verified'
                    WHEN is_verified = -1 THEN 'rejected'
                    ELSE 'pending'
                END AS state, COUNT(*) AS c
                FROM volunteer_profiles GROUP BY state
            ")->fetchAll();
            foreach ($volunteers as $row) {
                $lines[] = 'app_volunteers{state="' . self::escapeLabel((string)$row['state']) . '"} ' . (int)$row['c'];
            }

            $lines[] = '# HELP app_users_total Users by role';
            $lines[] = '# TYPE app_users_total gauge';
            $users = $pdo->query("
                SELECT r.code AS role, COUNT(u.id) AS c
                FROM users u JOIN roles r ON r.id = u.role_id
                GROUP BY r.code
            ")->fetchAll();
            foreach ($users as $row) {
                $lines[] = 'app_users_total{role="' . self::escapeLabel((string)$row['role']) . '"} ' . (int)$row['c'];
            }

            $overdue = (int)$pdo->query("
                SELECT COUNT(*) FROM help_requests WHERE is_overdue = 1 AND status = 'in_progress'
            ")->fetchColumn();
            $lines[] = '# HELP app_overdue_tasks Overdue in-progress tasks';
            $lines[] = '# TYPE app_overdue_tasks gauge';
            $lines[] = 'app_overdue_tasks ' . $overdue;
        } catch (Throwable $e) {
            $lines[] = '# HELP app_db_up Database availability';
            $lines[] = '# TYPE app_db_up gauge';
            $lines[] = 'app_db_up 0';
        }

        return implode("\n", $lines) . "\n";
    }

    private static function countBy(\PDO $pdo, string $table, string $column): array
    {
        $allowed = [
            'help_requests' => ['status'],
            'sos_alerts' => ['status']
        ];
        if (!isset($allowed[$table]) || !in_array($column, $allowed[$table], true)) {
            return [];
        }

        $rows = $pdo->query("SELECT {$column} AS k, COUNT(*) AS c FROM {$table} GROUP BY {$column}")->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[(string)$row['k']] = (int)$row['c'];
        }
        return $out;
    }

    private static function countersFile(): string
    {
        $dir = __DIR__ . '/../../storage/metrics';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/http_counters.json';
    }

    private static function readCounters(): array
    {
        $file = self::countersFile();
        if (!is_file($file)) {
            return ['started_at' => time(), 'total' => 0, 'routes' => []];
        }
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) ? $data : ['started_at' => time(), 'total' => 0, 'routes' => []];
    }

    private static function escapeLabel(string $value): string
    {
        return str_replace(["\\", "\"", "\n"], ["\\\\", "\\\"", "\\n"], $value);
    }
}
