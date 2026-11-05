<?php

declare(strict_types=1);

/**
 * Milestone 5 Round 2 Adversarial Verification Suite
 * Agent: Challenger M5-r2-1
 *
 * Exhaustively stress-tests:
 * 1. Universal GPS coordinate enforcement on POST /api/requests/{id}/complete
 *    - Generic requests without ADV5 title and without custom headers
 *    - Omitted, null, empty, non-numeric, out-of-range, and geofence boundary coordinates
 *    - Injection payloads in coordinate fields (SQL injection, objects, arrays)
 *    - Valid coordinates <= 150m, DB state verification, karma point increment
 *    - Completed task idempotency / replay prevention
 * 2. Repository-wide strict Zero-Emoji compliance via standard regex
 * 3. Database WAL mode and concurrency configuration
 * 4. Authentic assertion integrity in Tier 2 SOS Priority 0 test
 */

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/RateLimiter.php';

$testCount = 0;
$passCount = 0;
$failCount = 0;
$failures = [];

function assertTest(string $code, string $description, bool $condition, string $details = ''): void {
    global $testCount, $passCount, $failCount, $failures;
    $testCount++;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$code}: {$description}" . ($details !== '' ? " :: {$details}" : '') . PHP_EOL;
    } else {
        $failCount++;
        $failures[] = "{$code}: {$description} :: {$details}";
        echo "  [FAIL] {$code}: {$description} :: {$details}" . PHP_EOL;
    }
}

function curlRequest(string $method, string $path, array $data = [], array $headers = []): array {
    $baseUrl = 'http://127.0.0.1:8000';
    $url = $baseUrl . $path;
    $ch = curl_init($url);

    $defaultHeaders = ['Content-Type: application/json', 'Accept: application/json'];
    $finalHeaders = array_merge($defaultHeaders, $headers);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $finalHeaders);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    if (!empty($data) || in_array($method, ['POST', 'PUT', 'PATCH'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string)$response, true);
    return [
        'code' => $httpCode,
        'data' => is_array($decoded) ? $decoded : [],
        'raw' => (string)$response
    ];
}

function createGenericRequest(string $title, float $lat = 53.9168, float $lon = 27.5862): array {
    App\Core\RateLimiter::clear("create_request:127.0.0.1");
    App\Core\RateLimiter::clear("create_request:");

    $create = curlRequest('POST', '/api/requests', [
        'elderly_id' => 1,
        'category_id' => 1,
        'title' => $title,
        'description' => 'Challenger M5-r2 empirical verification request',
        'address' => 'г. Минск, пр-т Независимости, 45, кв. 12',
        'latitude' => $lat,
        'longitude' => $lon
    ]);
    $reqId = (int)($create['data']['request_id'] ?? 0);
    $secretCode = (string)($create['data']['secret_code'] ?? '');

    $accept = curlRequest('POST', "/api/requests/{$reqId}/accept", ['volunteer_id' => 2]);
    return [$reqId, $secretCode];
}

echo "================================================================================" . PHP_EOL;
echo "  CHALLENGER M5-R2-1: ADVERSARIAL STRESS & EMPIRICAL VERIFICATION HARNESS" . PHP_EOL;
echo "================================================================================" . PHP_EOL;

// -----------------------------------------------------------------------------
// PART 1: Universal GPS Coordinate Enforcement (NO ADV5, NO custom headers)
// -----------------------------------------------------------------------------
echo PHP_EOL . "--- [PART 1] Universal GPS Coordinate Enforcement on Generic Requests ---" . PHP_EOL;

// 1.1 Omit coordinates entirely
[$req1, $sec1] = createGenericRequest('Обычная просьба о доставке хлеба');
$res1 = curlRequest('POST', "/api/requests/{$req1}/complete", [
    'secret_code' => $sec1
]);
assertTest(
    'R2-CH-COORD-01',
    'Omitted coordinates on generic request rejects with HTTP 400',
    $res1['code'] === 400 && str_contains($res1['data']['error'] ?? '', 'GPS-координаты'),
    "HTTP {$res1['code']}, Error: " . ($res1['data']['error'] ?? '')
);

// 1.2 Omit latitude only
[$req2, $sec2] = createGenericRequest('Купить молоко в магазине');
$res2 = curlRequest('POST', "/api/requests/{$req2}/complete", [
    'secret_code' => $sec2,
    'longitude' => 27.5862
]);
assertTest(
    'R2-CH-COORD-02',
    'Omitted latitude on generic request rejects with HTTP 400',
    $res2['code'] === 400 && str_contains($res2['data']['error'] ?? '', 'GPS-координаты'),
    "HTTP {$res2['code']}, Error: " . ($res2['data']['error'] ?? '')
);

// 1.3 Omit longitude only
[$req3, $sec3] = createGenericRequest('Забрать посылку на почте');
$res3 = curlRequest('POST', "/api/requests/{$req3}/complete", [
    'secret_code' => $sec3,
    'latitude' => 53.9168
]);
assertTest(
    'R2-CH-COORD-03',
    'Omitted longitude on generic request rejects with HTTP 400',
    $res3['code'] === 400 && str_contains($res3['data']['error'] ?? '', 'GPS-координаты'),
    "HTTP {$res3['code']}, Error: " . ($res3['data']['error'] ?? '')
);

// 1.4 Passing null coordinates (both)
[$req4, $sec4] = createGenericRequest('Полить цветы дома');
$res4 = curlRequest('POST', "/api/requests/{$req4}/complete", [
    'secret_code' => $sec4,
    'latitude' => null,
    'longitude' => null
]);
assertTest(
    'R2-CH-COORD-04',
    'Passing latitude: null, longitude: null rejects with HTTP 400',
    $res4['code'] === 400 && str_contains($res4['data']['error'] ?? '', 'GPS-координаты'),
    "HTTP {$res4['code']}, Error: " . ($res4['data']['error'] ?? '')
);

// 1.5 Passing null latitude with valid longitude
[$req5, $sec5] = createGenericRequest('Помощь в поликлинике');
$res5 = curlRequest('POST', "/api/requests/{$req5}/complete", [
    'secret_code' => $sec5,
    'latitude' => null,
    'longitude' => 27.5862
]);
assertTest(
    'R2-CH-COORD-05',
    'Passing latitude: null with valid longitude rejects with HTTP 400',
    $res5['code'] === 400 && str_contains($res5['data']['error'] ?? '', 'GPS-координаты'),
    "HTTP {$res5['code']}, Error: " . ($res5['data']['error'] ?? '')
);

// 1.6 Passing valid latitude with null longitude
[$req6, $sec6] = createGenericRequest('Настроить телевизор');
$res6 = curlRequest('POST', "/api/requests/{$req6}/complete", [
    'secret_code' => $sec6,
    'latitude' => 53.9168,
    'longitude' => null
]);
assertTest(
    'R2-CH-COORD-06',
    'Passing valid latitude with null longitude rejects with HTTP 400',
    $res6['code'] === 400 && str_contains($res6['data']['error'] ?? '', 'GPS-координаты'),
    "HTTP {$res6['code']}, Error: " . ($res6['data']['error'] ?? '')
);

// 1.7 Non-numeric coordinates: strings "lat" / "lon"
[$req7, $sec7] = createGenericRequest('Помыть окно на кухне');
$res7 = curlRequest('POST', "/api/requests/{$req7}/complete", [
    'secret_code' => $sec7,
    'latitude' => 'somewhere_in_minsk',
    'longitude' => 'near_center'
]);
assertTest(
    'R2-CH-COORD-07',
    'Passing non-numeric string coordinates rejects with HTTP 400',
    $res7['code'] === 400 && str_contains($res7['data']['error'] ?? '', 'GPS-координаты'),
    "HTTP {$res7['code']}, Error: " . ($res7['data']['error'] ?? '')
);

// 1.8 Empty string coordinates
[$req8, $sec8] = createGenericRequest('Вынести мусор');
$res8 = curlRequest('POST', "/api/requests/{$req8}/complete", [
    'secret_code' => $sec8,
    'latitude' => '',
    'longitude' => ''
]);
assertTest(
    'R2-CH-COORD-08',
    'Passing empty string coordinates rejects with HTTP 400',
    $res8['code'] === 400 && str_contains($res8['data']['error'] ?? '', 'GPS-координаты'),
    "HTTP {$res8['code']}, Error: " . ($res8['data']['error'] ?? '')
);

// 1.9 Boolean coordinates
[$req9, $sec9] = createGenericRequest('Погулять с собакой');
$res9 = curlRequest('POST', "/api/requests/{$req9}/complete", [
    'secret_code' => $sec9,
    'latitude' => true,
    'longitude' => false
]);
assertTest(
    'R2-CH-COORD-09',
    'Passing boolean coordinates rejects with HTTP 400',
    $res9['code'] === 400 && str_contains($res9['data']['error'] ?? '', 'GPS-координаты'),
    "HTTP {$res9['code']}, Error: " . ($res9['data']['error'] ?? '')
);

// 1.10 Array coordinates
[$reqArr, $secArr] = createGenericRequest('Купить корм коту');
$resArr = curlRequest('POST', "/api/requests/{$reqArr}/complete", [
    'secret_code' => $secArr,
    'latitude' => [53.9168],
    'longitude' => [27.5862]
]);
assertTest(
    'R2-CH-COORD-09B',
    'Passing array coordinates rejects with HTTP 400',
    $resArr['code'] === 400 && str_contains($resArr['data']['error'] ?? '', 'GPS-координаты'),
    "HTTP {$resArr['code']}, Error: " . ($resArr['data']['error'] ?? '')
);

// 1.11 SQL Injection payload in coordinates
$resSqlInj = curlRequest('POST', "/api/requests/{$reqArr}/complete", [
    'secret_code' => $secArr,
    'latitude' => '53.9168; DROP TABLE users;--',
    'longitude' => '27.5862'
]);
assertTest(
    'R2-CH-COORD-09C',
    'SQL injection string in coordinates rejects with HTTP 400',
    $resSqlInj['code'] === 400 && str_contains($resSqlInj['data']['error'] ?? '', 'GPS-координаты'),
    "HTTP {$resSqlInj['code']}, Error: " . ($resSqlInj['data']['error'] ?? '')
);

// 1.12 Out of range coordinates: Latitude = 90.0001
[$req10, $sec10] = createGenericRequest('Доставка лекарств из аптеки');
$res10 = curlRequest('POST', "/api/requests/{$req10}/complete", [
    'secret_code' => $sec10,
    'latitude' => 90.0001,
    'longitude' => 27.5862
]);
assertTest(
    'R2-CH-COORD-10',
    'Passing latitude > 90.0 (90.0001) rejects with HTTP 400 (Некорректные GPS-координаты)',
    $res10['code'] === 400 && str_contains($res10['data']['error'] ?? '', 'Некорректные GPS-координаты'),
    "HTTP {$res10['code']}, Error: " . ($res10['data']['error'] ?? '')
);

// 1.13 Out of range coordinates: Latitude = -90.0001
$res11 = curlRequest('POST', "/api/requests/{$req10}/complete", [
    'secret_code' => $sec10,
    'latitude' => -90.0001,
    'longitude' => 27.5862
]);
assertTest(
    'R2-CH-COORD-11',
    'Passing latitude < -90.0 (-90.0001) rejects with HTTP 400 (Некорректные GPS-координаты)',
    $res11['code'] === 400 && str_contains($res11['data']['error'] ?? '', 'Некорректные GPS-координаты'),
    "HTTP {$res11['code']}, Error: " . ($res11['data']['error'] ?? '')
);

// 1.14 Out of range coordinates: Longitude = 180.0001
$res12 = curlRequest('POST', "/api/requests/{$req10}/complete", [
    'secret_code' => $sec10,
    'latitude' => 53.9168,
    'longitude' => 180.0001
]);
assertTest(
    'R2-CH-COORD-12',
    'Passing longitude > 180.0 (180.0001) rejects with HTTP 400 (Некорректные GPS-координаты)',
    $res12['code'] === 400 && str_contains($res12['data']['error'] ?? '', 'Некорректные GPS-координаты'),
    "HTTP {$res12['code']}, Error: " . ($res12['data']['error'] ?? '')
);

// 1.15 Out of range coordinates: Longitude = -180.0001
$res13 = curlRequest('POST', "/api/requests/{$req10}/complete", [
    'secret_code' => $sec10,
    'latitude' => 53.9168,
    'longitude' => -180.0001
]);
assertTest(
    'R2-CH-COORD-13',
    'Passing longitude < -180.0 (-180.0001) rejects with HTTP 400 (Некорректные GPS-координаты)',
    $res13['code'] === 400 && str_contains($res13['data']['error'] ?? '', 'Некорректные GPS-координаты'),
    "HTTP {$res13['code']}, Error: " . ($res13['data']['error'] ?? '')
);

// 1.16 Coordinates > 150m away (150.05m boundary breach)
[$req14, $sec14] = createGenericRequest('Привезти питьевую воду');
$res14 = curlRequest('POST', "/api/requests/{$req14}/complete", [
    'secret_code' => $sec14,
    'latitude' => 53.9168 + 0.00134943,
    'longitude' => 27.5862
]);
assertTest(
    'R2-CH-COORD-14',
    'Coordinates 150.05m away reject with HTTP 400 (вне допустимой зоны)',
    $res14['code'] === 400 && str_contains($res14['data']['error'] ?? '', 'вне допустимой зоны'),
    "HTTP {$res14['code']}, Error: " . ($res14['data']['error'] ?? '')
);

// 1.17 Coordinates > 150m away (250m distance)
$deltaLat250 = (250.0 / 6371000.0) * (180.0 / M_PI);
$res15 = curlRequest('POST', "/api/requests/{$req14}/complete", [
    'secret_code' => $sec14,
    'latitude' => 53.9168 + $deltaLat250,
    'longitude' => 27.5862
]);
assertTest(
    'R2-CH-COORD-15',
    'Coordinates 250m away reject with HTTP 400 (вне допустимой зоны)',
    $res15['code'] === 400 && str_contains($res15['data']['error'] ?? '', 'вне допустимой зоны'),
    "HTTP {$res15['code']}, Error: " . ($res15['data']['error'] ?? '')
);

// 1.18 Coordinates far away (Null Island: 0.0, 0.0, ~6000km away)
$resNullIsland = curlRequest('POST', "/api/requests/{$req14}/complete", [
    'secret_code' => $sec14,
    'latitude' => 0.0,
    'longitude' => 0.0
]);
assertTest(
    'R2-CH-COORD-15B',
    'Remote coordinates (0.0, 0.0) reject with HTTP 400 (вне допустимой зоны)',
    $resNullIsland['code'] === 400 && str_contains($resNullIsland['data']['error'] ?? '', 'вне допустимой зоны'),
    "HTTP {$resNullIsland['code']}, Error: " . ($resNullIsland['data']['error'] ?? '')
);

// 1.19 Verify request remained in_progress after rejected coordinate attempts
$checkPending = curlRequest('GET', "/api/requests/{$req14}");
$isPending = ($checkPending['data']['data']['status'] ?? '') === 'in_progress';
assertTest(
    'R2-CH-COORD-16',
    'Request remains in_progress after rejected coordinate attempts',
    $isPending,
    "Status: " . ($checkPending['data']['data']['status'] ?? 'unknown')
);

// 1.20 Passing coordinates <= 150m with correct secret code -> assert HTTP 200 (Exact 0m match)
// Get initial volunteer points
$vpBefore = App\Core\Database::getConnection()->query("SELECT points, completed_tasks_count FROM volunteer_profiles WHERE user_id = 2")->fetch(PDO::FETCH_ASSOC);
$pointsBefore = (int)($vpBefore['points'] ?? 0);
$completedBefore = (int)($vpBefore['completed_tasks_count'] ?? 0);

[$req17, $sec17] = createGenericRequest('Купить газету и журнал');
$res17 = curlRequest('POST', "/api/requests/{$req17}/complete", [
    'secret_code' => $sec17,
    'latitude' => 53.9168,
    'longitude' => 27.5862
]);
assertTest(
    'R2-CH-COORD-17',
    'Exact target coordinates (0m) with valid secret code succeeds with HTTP 200',
    $res17['code'] === 200 && ($res17['data']['success'] ?? false) === true && ($res17['data']['distance_meters'] ?? null) !== null,
    "HTTP {$res17['code']}, distance_meters: " . ($res17['data']['distance_meters'] ?? 'null')
);

// Check volunteer points increment
$vpAfter = App\Core\Database::getConnection()->query("SELECT points, completed_tasks_count FROM volunteer_profiles WHERE user_id = 2")->fetch(PDO::FETCH_ASSOC);
$pointsAfter = (int)($vpAfter['points'] ?? 0);
$completedAfter = (int)($vpAfter['completed_tasks_count'] ?? 0);
assertTest(
    'R2-CH-COORD-17B',
    'Successful completion increments volunteer points (+50) and completed_tasks_count (+1)',
    ($pointsAfter === $pointsBefore + 50) && ($completedAfter === $completedBefore + 1),
    "Points: {$pointsBefore} -> {$pointsAfter}, Tasks: {$completedBefore} -> {$completedAfter}"
);

// 1.21 Replay attack prevention: completing already completed request must fail
$resReplay = curlRequest('POST', "/api/requests/{$req17}/complete", [
    'secret_code' => $sec17,
    'latitude' => 53.9168,
    'longitude' => 27.5862
]);
assertTest(
    'R2-CH-COORD-17C',
    'Replay attack: completing an already completed request is rejected with HTTP 400',
    $resReplay['code'] === 400 && str_contains($resReplay['data']['error'] ?? '', 'Завершить можно только заявку'),
    "HTTP {$resReplay['code']}, Error: " . ($resReplay['data']['error'] ?? '')
);

// 1.22 Passing coordinates <= 150m: 75m distance -> assert HTTP 200
[$req18, $sec18] = createGenericRequest('Помощь в уборке квартиры');
$deltaLat75 = (75.0 / 6371000.0) * (180.0 / M_PI);
$res18 = curlRequest('POST', "/api/requests/{$req18}/complete", [
    'secret_code' => $sec18,
    'latitude' => 53.9168 + $deltaLat75,
    'longitude' => 27.5862
]);
assertTest(
    'R2-CH-COORD-18',
    'Coordinates 75m away with valid secret code succeeds with HTTP 200',
    $res18['code'] === 200 && ($res18['data']['success'] ?? false) === true && ($res18['data']['distance_meters'] ?? 0) <= 80,
    "HTTP {$res18['code']}, distance_meters: " . ($res18['data']['distance_meters'] ?? 'null')
);

// 1.23 Passing coordinates <= 150m: exact 150.0m boundary step -> assert HTTP 200
[$req19, $sec19] = createGenericRequest('Доставка продуктов на дом');
$res19 = curlRequest('POST', "/api/requests/{$req19}/complete", [
    'secret_code' => $sec19,
    'latitude' => 53.9168 + 0.00134898,
    'longitude' => 27.5862
]);
assertTest(
    'R2-CH-COORD-19',
    'Coordinates at 150.0m boundary with valid secret code succeeds with HTTP 200',
    $res19['code'] === 200 && ($res19['data']['success'] ?? false) === true && ($res19['data']['distance_meters'] ?? 0) <= 150.0,
    "HTTP {$res19['code']}, distance_meters: " . ($res19['data']['distance_meters'] ?? 'null')
);

// 1.24 Passing numeric string coordinates in JSON (e.g. "53.9168", "27.5862") -> assert HTTP 200
[$req20, $sec20] = createGenericRequest('Купить батарейки для тонометра');
$res20 = curlRequest('POST', "/api/requests/{$req20}/complete", [
    'secret_code' => $sec20,
    'latitude' => '53.9168',
    'longitude' => '27.5862'
]);
assertTest(
    'R2-CH-COORD-20',
    'Numeric string coordinates in JSON successfully parsed and accepted with HTTP 200',
    $res20['code'] === 200 && ($res20['data']['success'] ?? false) === true,
    "HTTP {$res20['code']}"
);

// 1.25 Persistent DB verification of completed request coordinates and distance
$dbCheckReq = curlRequest('GET', "/api/requests/{$req17}");
$dbReqData = $dbCheckReq['data']['data'] ?? [];
$isDbPersisted = ($dbReqData['status'] ?? '') === 'completed'
    && !empty($dbReqData['completed_at'])
    && isset($dbReqData['completed_lat']) && abs((float)$dbReqData['completed_lat'] - 53.9168) < 0.0001
    && isset($dbReqData['completed_lon']) && abs((float)$dbReqData['completed_lon'] - 27.5862) < 0.0001
    && isset($dbReqData['distance_to_target']);
assertTest(
    'R2-CH-COORD-21',
    'Completed request correctly persists status, completed_lat, completed_lon, distance_to_target in DB',
    $isDbPersisted,
    "status: " . ($dbReqData['status'] ?? 'null') . ", lat: " . ($dbReqData['completed_lat'] ?? 'null') . ", lon: " . ($dbReqData['completed_lon'] ?? 'null') . ", dist: " . ($dbReqData['distance_to_target'] ?? 'null')
);

// -----------------------------------------------------------------------------
// PART 2: Codebase-wide Unicode Emoji Verification
// Regex: [\x{1F000}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{2300}-\x{23FF}\x{2B00}-\x{2BFF}\x{FE0F}]
// -----------------------------------------------------------------------------
echo PHP_EOL . "--- [PART 2] Exhaustive Programmatic Zero-Emoji Verification ---" . PHP_EOL;

$standardEmojiRegex = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{2300}-\x{23FF}\x{2B00}-\x{2BFF}\x{FE0F}]/u';

function scanDirectoryForEmojis(string $dir, string $regex, array $excludeFiles = []): array {
    $hits = [];
    if (!is_dir($dir)) {
        return $hits;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $path = $file->getPathname();
        $filename = $file->getFilename();
        if (in_array($filename, $excludeFiles, true) || in_array($path, $excludeFiles, true)) {
            continue;
        }
        $content = (string)file_get_contents($path);
        if (preg_match_all($regex, $content, $matches)) {
            $hits[$path] = array_unique($matches[0]);
        }
    }
    return $hits;
}

// 2.1 Backend MVC (app/)
$appHits = scanDirectoryForEmojis('/var/www/html/app', $standardEmojiRegex);
assertTest(
    'R2-CH-EMOJI-APP',
    'Strict Zero-Emoji compliance across all files in app/ MVC core',
    empty($appHits),
    empty($appHits) ? 'app/ is 100% emoji-free' : 'DEFECT: Found emojis in: ' . json_encode($appHits, JSON_UNESCAPED_UNICODE)
);

// 2.2 Frontend assets (public/)
$publicHits = scanDirectoryForEmojis('/var/www/html/public', $standardEmojiRegex);
assertTest(
    'R2-CH-EMOJI-PUBLIC',
    'Strict Zero-Emoji compliance across all files in public/ (WCAG 2.1 AAA)',
    empty($publicHits),
    empty($publicHits) ? 'public/ is 100% SVG inline clean' : 'DEFECT: Found emojis in: ' . json_encode($publicHits, JSON_UNESCAPED_UNICODE)
);

// 2.3 Database schema and seed files (database/)
$dbHits = scanDirectoryForEmojis('/var/www/html/database', $standardEmojiRegex, ['database.sqlite', 'database.sqlite-wal', 'database.sqlite-shm']);
assertTest(
    'R2-CH-EMOJI-DBFILES',
    'Strict Zero-Emoji compliance across all database/ source scripts',
    empty($dbHits),
    empty($dbHits) ? 'database/ scripts are 100% emoji-free' : 'DEFECT: Found emojis in: ' . json_encode($dbHits, JSON_UNESCAPED_UNICODE)
);

// 2.4 Database SQLite table contents
$pdo = App\Core\Database::getConnection();
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
$dbContentHits = [];
foreach ($tables as $tbl) {
    $rows = $pdo->query("SELECT * FROM {$tbl}")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $rIdx => $row) {
        foreach ($row as $col => $val) {
            if (is_string($val) && preg_match_all($standardEmojiRegex, $val, $m)) {
                $dbContentHits[] = "{$tbl}.{$col} (row {$rIdx}): " . implode(',', array_unique($m[0]));
            }
        }
    }
}
assertTest(
    'R2-CH-EMOJI-SQLITE',
    'Strict Zero-Emoji compliance across all SQLite tables and columns in database.sqlite',
    empty($dbContentHits),
    empty($dbContentHits) ? 'database.sqlite contains exactly 0 emojis' : 'DEFECT: Found emojis in DB: ' . implode('; ', $dbContentHits)
);

// 2.5 Tests suite (tests/)
$testHits = scanDirectoryForEmojis('/var/www/html/tests', $standardEmojiRegex, [
    'test_challenger_m5_r2.php',
    'test_challenger_m5_1.php'
]);
assertTest(
    'R2-CH-EMOJI-TESTS',
    'Strict Zero-Emoji compliance across all test suites in tests/',
    empty($testHits),
    empty($testHits) ? 'tests/ directory is 100% emoji-free' : 'DEFECT: Found emojis in test files: ' . json_encode($testHits, JSON_UNESCAPED_UNICODE)
);

// 2.6 Architecture documentation (plan/)
$planHits = scanDirectoryForEmojis('/var/www/html/plan', $standardEmojiRegex);
assertTest(
    'R2-CH-EMOJI-PLAN',
    'Strict Zero-Emoji compliance across all architectural documentation in plan/',
    empty($planHits),
    empty($planHits) ? 'plan/ documentation is 100% emoji-free' : 'DEFECT: Found emojis in plan: ' . json_encode($planHits, JSON_UNESCAPED_UNICODE)
);

// 2.7 Root Markdown Documentation
$rootDocs = [
    '/var/www/html/README.md',
    '/var/www/html/PROJECT.md',
    '/var/www/html/TEST_INFRA.md',
    '/var/www/html/TEST_READY.md'
];
$rootDocHits = [];
foreach ($rootDocs as $doc) {
    if (file_exists($doc)) {
        $c = (string)file_get_contents($doc);
        if (preg_match_all($standardEmojiRegex, $c, $m)) {
            $rootDocHits[$doc] = array_unique($m[0]);
        }
    }
}
assertTest(
    'R2-CH-EMOJI-ROOTDOCS',
    'Strict Zero-Emoji compliance across all root markdown documents',
    empty($rootDocHits),
    empty($rootDocHits) ? 'Root markdown documentation is 100% emoji-free' : 'DEFECT: Found emojis in docs: ' . json_encode($rootDocHits, JSON_UNESCAPED_UNICODE)
);

// -----------------------------------------------------------------------------
// PART 3: SQLite WAL Mode & Concurrency Verification
// -----------------------------------------------------------------------------
echo PHP_EOL . "--- [PART 3] SQLite WAL Mode & Concurrency Verification ---" . PHP_EOL;

$appDb = App\Core\Database::getConnection();
$journalMode = (string)$appDb->query('PRAGMA journal_mode')->fetchColumn();
$busyTimeout = (int)$appDb->query('PRAGMA busy_timeout')->fetchColumn();
$synchronous = (int)$appDb->query('PRAGMA synchronous')->fetchColumn();

assertTest(
    'R2-CH-DB-WAL',
    'SQLite journal_mode is configured to WAL (Write-Ahead Logging)',
    strtolower($journalMode) === 'wal',
    "journal_mode: {$journalMode}"
);

assertTest(
    'R2-CH-DB-TIMEOUT',
    'SQLite busy_timeout is configured to >= 5000ms',
    $busyTimeout >= 5000,
    "busy_timeout: {$busyTimeout}ms"
);

assertTest(
    'R2-CH-DB-SYNC',
    'SQLite synchronous is configured to NORMAL (1)',
    $synchronous === 1,
    "synchronous: {$synchronous}"
);

// -----------------------------------------------------------------------------
// PART 4: Real Priority 0 Assertion Verification in Tier 2 SOS Test
// -----------------------------------------------------------------------------
echo PHP_EOL . "--- [PART 4] Authentic SOS Priority 0 Assertion in Tier 2 ---" . PHP_EOL;

$tier2Path = '/var/www/html/tests/e2e_suite/Tier2_BoundaryCornerTest.php';
$tier2Content = file_exists($tier2Path) ? (string)file_get_contents($tier2Path) : '';
$hasStaticPass = str_contains($tier2Content, "'pass' => true, 'details' => 'Priority 0 default configured'");
$hasDynamicSosPost = str_contains($tier2Content, "POST', '/api/sos'") && str_contains($tier2Content, "sos_alerts");
$hasApiPriorityCheck = str_contains($tier2Content, '$apiPriority === 0');
$hasDbPriorityCheck = str_contains($tier2Content, '$dbPriority === 0');

assertTest(
    'R2-CH-T2-SOS-08',
    'T2-SOS-08 in Tier2_BoundaryCornerTest.php performs authentic API call and DB check (no static pass)',
    !$hasStaticPass && $hasDynamicSosPost && $hasApiPriorityCheck && $hasDbPriorityCheck,
    "Static pass eliminated: " . (!$hasStaticPass ? 'YES' : 'NO') . ", Dynamic call: " . ($hasDynamicSosPost ? 'YES' : 'NO') . ", API priority: " . ($hasApiPriorityCheck ? 'YES' : 'NO') . ", DB priority: " . ($hasDbPriorityCheck ? 'YES' : 'NO')
);

// -----------------------------------------------------------------------------
// SUMMARY & VERDICT
// -----------------------------------------------------------------------------
echo PHP_EOL . "================================================================================" . PHP_EOL;
echo "  CHALLENGER M5-R2-1 FINAL HARNESS SUMMARY" . PHP_EOL;
echo "================================================================================" . PHP_EOL;
echo "  Total Executed Tests: {$testCount}" . PHP_EOL;
echo "  Passed:               {$passCount}" . PHP_EOL;
echo "  Failed:               {$failCount}" . PHP_EOL;

$verdict = ($failCount === 0) ? 'CONFIRM' : 'DEFECT_FOUND';
echo "  VERDICT:              {$verdict}" . PHP_EOL;
echo "================================================================================" . PHP_EOL;

if ($failCount > 0) {
    echo "Failures / Defects Discovered:" . PHP_EOL;
    foreach ($failures as $f) {
        echo "  - {$f}" . PHP_EOL;
    }
    exit(1);
}

exit(0);
