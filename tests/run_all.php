<?php

declare(strict_types=1);

/**
 * Full deterministic test matrix for «ЗаботаРядом».
 *
 * Every integration suite runs against a freshly reset + migrated + seeded
 * MySQL database and a clean rate-limit cache. A PHP built-in server is
 * started automatically when nothing is listening on the configured base URL.
 * If MySQL is not reachable and Docker is available, the development MySQL
 * service is started for the duration of the run.
 *
 * Usage:
 *   php tests/run_all.php
 *   php tests/run_all.php --filter=privacy
 *   php tests/run_all.php --list
 */

$root = dirname(__DIR__);
chdir($root);

$options = getopt('', ['filter::', 'list']);
$filter = isset($options['filter']) ? trim((string)$options['filter']) : '';

$suites = [
    'tests/run_tests.php',
    'tests/test_frontend_wcag.php',
    'tests/test_api_requests.php',
    'tests/test_security_headers.php',
    'tests/test_auth_security.php',
    'tests/test_privacy.php',
    'tests/test_badge.php',
    'tests/test_rehabilitation_materials.php',
    'tests/test_sos_geofence.php',
    'tests/test_race_conditions.php',
    'tests/test_e2e_comprehensive.php',
    'tests/adversarial_privacy_test.php',
    'tests/test_adversarial_badge_qr.php',
    'tests/test_adversarial_m2.php',
    'tests/test_adversarial_m3_geofence.php',
    'tests/test_adversarial_m3_sos.php',
    'tests/test_sos_grouping.php',
    'tests/test_adversarial_m4_ui_plan.php',
    'tests/test_adversarial_m4_wcag.php',
    'tests/test_adversarial_m5_suite.php',
    'tests/test_challenger_m4_r2_independent.php',
    'tests/test_challenger_m5_1.php',
    'tests/test_challenger_m5_r2.php',
    'tests/test_challenger_m5_r2_concurrency.php',
    'tests/test_challenger_m5_stress.php',
];

if (isset($options['list'])) {
    foreach ($suites as $suite) {
        echo $suite . PHP_EOL;
    }
    exit(0);
}

if ($filter !== '') {
    $suites = array_values(array_filter(
        $suites,
        static fn(string $suite): bool => stripos($suite, $filter) !== false
    ));
    if ($suites === []) {
        fwrite(STDERR, "No suites matched filter '{$filter}'." . PHP_EOL);
        exit(2);
    }
}

$baseUrl = rtrim(getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8000', '/');
putenv('TEST_BASE_URL=' . $baseUrl);

$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3307';
$dbName = getenv('DB_DATABASE') ?: 'senior_care';
$dbUser = getenv('DB_USERNAME') ?: 'senior';
$dbPass = getenv('DB_PASSWORD') ?: 'seniorpass';
$dbEnv = [
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => $dbHost,
    'DB_PORT' => $dbPort,
    'DB_DATABASE' => $dbName,
    'DB_USERNAME' => $dbUser,
    'DB_PASSWORD' => $dbPass,
];
foreach ($dbEnv as $key => $value) {
    putenv("{$key}={$value}");
}

// Environment for both the PHP test server and every suite process. The
// dedicated testing environment enables the cross-role integration fixture;
// local development and production keep anonymous requests unauthenticated.
$serverEnv = array_merge(getenv() ?: [], $dbEnv, [
    'APP_ENV' => 'testing',
    'ALLOW_DEMO_AUTH' => 'true',
    'ALLOW_ANONYMOUS_ADMIN' => 'true',
    'ELDERLY_AUTO_LOGIN' => 'false',
    'TEST_BASE_URL' => $baseUrl,
]);

if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "PDO MySQL driver is not enabled. Install/enable pdo_mysql or run tests in Docker:" . PHP_EOL);
    fwrite(STDERR, "  docker compose run --rm app php tests/run_all.php" . PHP_EOL);
    exit(2);
}

$serverProcess = null;
$startedMysql = false;
$serverLog = sys_get_temp_dir() . '/care-test-server.log';

function dbOk(array $dbEnv): bool
{
    try {
        $dsn = "mysql:host={$dbEnv['DB_HOST']};port={$dbEnv['DB_PORT']};dbname={$dbEnv['DB_DATABASE']};charset=utf8mb4";
        new PDO($dsn, $dbEnv['DB_USERNAME'], $dbEnv['DB_PASSWORD'], [PDO::ATTR_TIMEOUT => 2]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function healthOk(string $baseUrl): bool
{
    $ch = curl_init($baseUrl . '/api/health');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200 && is_string($body) && str_contains($body, '"status"');
}

if (!dbOk($dbEnv)) {
    $docker = shell_exec('docker --version 2>&1');
    if (is_string($docker) && str_contains($docker, 'Docker version')) {
        echo "MySQL is not reachable; starting docker compose MySQL service..." . PHP_EOL;
        shell_exec('docker compose up -d mysql 2>&1');
        for ($i = 0; $i < 60; $i++) {
            if (dbOk($dbEnv)) {
                $startedMysql = true;
                break;
            }
            usleep(1000000);
        }
    }
    if (!dbOk($dbEnv)) {
        fwrite(STDERR, "MySQL is not reachable at {$dbHost}:{$dbPort} ({$dbName})." . PHP_EOL);
        fwrite(STDERR, "Start it with: docker compose up -d mysql" . PHP_EOL);
        fwrite(STDERR, "or set DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD." . PHP_EOL);
        exit(2);
    }
}

if (!healthOk($baseUrl)) {
    if (!str_contains($baseUrl, '127.0.0.1:8000') && !str_contains($baseUrl, 'localhost:8000')) {
        fwrite(STDERR, "Server is not reachable at {$baseUrl}. Start it or set TEST_BASE_URL." . PHP_EOL);
        exit(2);
    }

    $descriptors = [
        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['file', $serverLog, 'a'],
        2 => ['file', $serverLog, 'a'],
    ];
    $serverProcess = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:8000', 'router.php'],
        $descriptors,
        $pipes,
        $root,
        $serverEnv
    );

    for ($i = 0; $i < 40; $i++) {
        if (healthOk($baseUrl)) {
            break;
        }
        usleep(250000);
    }

    if (!healthOk($baseUrl)) {
        fwrite(STDERR, "Could not start test server. See {$serverLog}" . PHP_EOL);
        if (file_exists($serverLog)) {
            fwrite(STDERR, file_get_contents($serverLog) . PHP_EOL);
        }
        if (is_resource($serverProcess)) {
            proc_terminate($serverProcess);
        }
        exit(2);
    }
    echo "Started test server at {$baseUrl}" . PHP_EOL;
}

function runCommand(array $command, array $env = []): array
{
    global $root;

    $descriptors = [
        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $root, $env ?: null);
    if (!is_resource($process)) {
        return ['code' => -1, 'output' => 'proc_open failed', 'duration' => 0.0];
    }

    $started = microtime(true);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    return [
        'code' => $code,
        'output' => trim((string)$stdout . "\n" . (string)$stderr),
        'duration' => round(microtime(true) - $started, 2),
    ];
}

function resetDatabase(array $env): array
{
    global $root;

    foreach (glob($root . '/storage/cache/rate_limits/*.json') ?: [] as $file) {
        @unlink($file);
    }

    $reset = runCommand([PHP_BINARY, 'database/reset.php'], $env);
    if ($reset['code'] !== 0) {
        return ['ok' => false, 'output' => $reset['output']];
    }
    $migrate = runCommand([PHP_BINARY, 'database/migrate.php'], $env);
    if ($migrate['code'] !== 0) {
        return ['ok' => false, 'output' => $migrate['output']];
    }
    $seed = runCommand([PHP_BINARY, 'database/seed.php'], $env);
    if ($seed['code'] !== 0) {
        return ['ok' => false, 'output' => $seed['output']];
    }

    return ['ok' => true, 'output' => ''];
}

// Reuse exactly the same environment for the suites as for the test server.
$env = $serverEnv;
$env['PATH'] = getenv('PATH') ?: ($env['PATH'] ?? '');

$results = [];
$startedAt = microtime(true);

echo "============================================================\n";
echo "  FULL TEST MATRIX — «ЗаботаРядом» (MySQL)\n";
echo "============================================================\n";

foreach ($suites as $index => $suite) {
    printf("\n[%02d/%02d] %s\n", $index + 1, count($suites), $suite);

    $reset = resetDatabase($env);
    if (!$reset['ok']) {
        echo "  [FAIL] database reset\n" . $reset['output'] . "\n";
        $results[] = ['suite' => $suite, 'ok' => false, 'duration' => 0.0, 'summary' => 'database reset failed'];
        continue;
    }

    $run = runCommand([PHP_BINARY, $suite], $env);
    $output = $run['output'];
    $ok = $run['code'] === 0;

    $summaryLines = array_values(array_filter(
        preg_split('/\R/', $output) ?: [],
        static fn(string $line): bool => (bool)preg_match('/RESULTS?:|Total( Tests)?:|Passed|Failed|Success Rate|Тесты|ИТОГ/i', $line)
            && !str_contains($line, '[PASS]')
    ));
    $summary = trim(implode(' | ', array_slice($summaryLines, -3)));

    echo ($ok ? '  [PASS]' : '  [FAIL]') . " exit={$run['code']} duration={$run['duration']}s"
        . ($summary !== '' ? " — {$summary}" : '') . "\n";

    if (!$ok) {
        $lines = preg_split('/\R/', $output) ?: [];
        $failureLines = array_values(array_filter(
            $lines,
            static fn(string $line): bool => (bool)preg_match('/\[FAIL\]|Fatal error|Parse error|Uncaught/i', $line)
        ));
        echo "  --- failures ---\n";
        foreach (array_slice($failureLines, 0, 20) as $line) {
            echo '  ' . $line . "\n";
        }
        echo "  --- last output ---\n";
        foreach (array_slice($lines, -15) as $line) {
            echo '  ' . $line . "\n";
        }
    }

    $results[] = [
        'suite' => $suite,
        'ok' => $ok,
        'duration' => $run['duration'],
        'summary' => $summary,
    ];
}

$totalDuration = round(microtime(true) - $startedAt, 2);
$failed = array_values(array_filter($results, static fn(array $r): bool => !$r['ok']));

echo "\n============================================================\n";
echo "  MATRIX SUMMARY\n";
echo "============================================================\n";
echo '  Suites:   ' . count($results) . "\n";
echo '  Passed:   ' . (count($results) - count($failed)) . "\n";
echo '  Failed:   ' . count($failed) . "\n";
echo "  Duration: {$totalDuration}s\n";
foreach ($failed as $failure) {
    echo '  FAILED:   ' . $failure['suite'] . "\n";
}
echo "============================================================\n";

// Leave the development database in a clean, seeded demo state. Stress suites
// intentionally create bursts of SOS/requests; without this reset they leak
// into the local UI (for example 20 active SOS from the same senior).
echo "\nRestoring clean demo database after test run...\n";
$cleanup = resetDatabase($env);
if ($cleanup['ok']) {
    echo "  [OK] Test data removed; demo state restored.\n";
} else {
    echo "  [WARN] Could not restore the demo database: " . $cleanup['output'] . "\n";
}

if (is_resource($serverProcess)) {
    proc_terminate($serverProcess);
    proc_close($serverProcess);
}
if ($startedMysql) {
    shell_exec('docker compose stop mysql 2>&1');
}

exit($failed === [] ? 0 : 1);
