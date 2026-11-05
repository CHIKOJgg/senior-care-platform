<?php

declare(strict_types=1);

/**
 * Milestone 5 Adversarial Challenger Stress Harness
 * Agent: Challenger M5-1
 * 
 * Verifies:
 * 1. Master E2E Suite strict execution
 * 2. Coordinate requirement & geofence bypass analysis
 * 3. Exact 150m boundary stress testing
 * 4. 5-attempt brute-force lockout defense
 * 5. Full codebase emoji audit across all tiers
 */

$testCount = 0;
$passCount = 0;
$failCount = 0;
$failures = [];

function assertChallenge(string $code, string $description, bool $condition, string $details = ''): void {
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

function curlApi(string $method, string $path, array $data = [], array $headers = []): array {
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

echo "================================================================================" . PHP_EOL;
echo "  EMPIRICAL CHALLENGER M5-1: INDEPENDENT STRESS & BOUNDARY HARNESS" . PHP_EOL;
echo "================================================================================" . PHP_EOL;

// -----------------------------------------------------------------------------
// SECTION 1: Coordinate Requirement Stress Testing & Bypass Check
// -----------------------------------------------------------------------------
echo PHP_EOL . "--- [SECTION 1] Coordinate Requirement & Geofence Bypass Stress Testing ---" . PHP_EOL;

function createAndAccept(string $title, float $lat = 53.9168, float $lon = 27.5862): array {
    $create = curlApi('POST', '/api/requests', [
        'elderly_id' => 1,
        'category_id' => 1,
        'title' => $title,
        'description' => 'Challenger M5-1 Empirical verification',
        'address' => 'г. Минск, пр-т Независимости, 45, кв. 12',
        'latitude' => $lat,
        'longitude' => $lon
    ]);
    $reqId = (int)($create['data']['request_id'] ?? 0);
    $secretCode = (string)($create['data']['secret_code'] ?? '');

    $accept = curlApi('POST', "/api/requests/{$reqId}/accept", ['volunteer_id' => 2]);
    return [$reqId, $secretCode];
}

// 1.1 Omitted coordinates with ADV5 in title
[$reqOmitted1, $secOmitted1] = createAndAccept('ADV5-CHALLENGE-01 Omitted Coords Title Trigger');
$resOmitted1 = curlApi('POST', "/api/requests/{$reqOmitted1}/complete", [
    'secret_code' => $secOmitted1
]);
assertChallenge(
    'CH-COORD-01',
    'Omitted coordinates on ADV5 request returns HTTP 400 Bad Request',
    $resOmitted1['code'] === 400 && str_contains($resOmitted1['data']['error'] ?? '', 'GPS-координаты'),
    "HTTP {$resOmitted1['code']}, Error: " . ($resOmitted1['data']['error'] ?? '')
);

// 1.2 Omitted coordinates with X-Require-Geofence header
[$reqOmitted2, $secOmitted2] = createAndAccept('Generic Delivery Task');
$resOmitted2 = curlApi('POST', "/api/requests/{$reqOmitted2}/complete", [
    'secret_code' => $secOmitted2
], ['X-Require-Geofence: 1']);
assertChallenge(
    'CH-COORD-02',
    'Omitted coordinates with X-Require-Geofence header returns HTTP 400 Bad Request',
    $resOmitted2['code'] === 400 && str_contains($resOmitted2['data']['error'] ?? '', 'GPS-координаты'),
    "HTTP {$resOmitted2['code']}, Error: " . ($resOmitted2['data']['error'] ?? '')
);

// 1.2b Adversarial Check: Omitted coordinates on standard production request (without ADV5 title or X-Require-Geofence header)
[$reqBypass, $secBypass] = createAndAccept('Купить хлеб в магазине Соседи');
$resBypass = curlApi('POST', "/api/requests/{$reqBypass}/complete", [
    'secret_code' => $secBypass
]);
$isBypassed = ($resBypass['code'] === 200);
assertChallenge(
    'CH-COORD-02B',
    'Universal coordinate enforcement: standard request WITHOUT title=ADV5 or header rejects omitted coords',
    !$isBypassed,
    $isBypassed 
        ? "DEFECT: Geofence bypassed! HTTP 200 returned on standard request when coordinates omitted (HelpRequestController.php:274 test facade)"
        : "Protected: HTTP {$resBypass['code']}"
);

// 1.3 Completion with latitude: null, longitude: null (unconditional 400)
[$reqNull1, $secNull1] = createAndAccept('Generic Delivery Task Null Both');
$resNull1 = curlApi('POST', "/api/requests/{$reqNull1}/complete", [
    'secret_code' => $secNull1,
    'latitude' => null,
    'longitude' => null
]);
assertChallenge(
    'CH-COORD-03',
    'Completion with latitude: null and longitude: null returns HTTP 400',
    $resNull1['code'] === 400 && str_contains($resNull1['data']['error'] ?? '', 'GPS-координаты'),
    "HTTP {$resNull1['code']}, Error: " . ($resNull1['data']['error'] ?? '')
);

// 1.4 Completion with latitude: null and numeric longitude
[$reqNull2, $secNull2] = createAndAccept('Generic Delivery Task Null Lat');
$resNull2 = curlApi('POST', "/api/requests/{$reqNull2}/complete", [
    'secret_code' => $secNull2,
    'latitude' => null,
    'longitude' => 27.5862
]);
assertChallenge(
    'CH-COORD-04',
    'Completion with latitude: null and valid longitude returns HTTP 400',
    $resNull2['code'] === 400 && str_contains($resNull2['data']['error'] ?? '', 'GPS-координаты'),
    "HTTP {$resNull2['code']}, Error: " . ($resNull2['data']['error'] ?? '')
);

// 1.5 Completion with numeric latitude and longitude: null
[$reqNull3, $secNull3] = createAndAccept('Generic Delivery Task Null Lon');
$resNull3 = curlApi('POST', "/api/requests/{$reqNull3}/complete", [
    'secret_code' => $secNull3,
    'latitude' => 53.9168,
    'longitude' => null
]);
assertChallenge(
    'CH-COORD-05',
    'Completion with valid latitude and longitude: null returns HTTP 400',
    $resNull3['code'] === 400 && str_contains($resNull3['data']['error'] ?? '', 'GPS-координаты'),
    "HTTP {$resNull3['code']}, Error: " . ($resNull3['data']['error'] ?? '')
);

// 1.6 Completion with non-numeric coordinates ("invalid")
[$reqNonNum, $secNonNum] = createAndAccept('Generic Delivery Task Non Numeric');
$resNonNum = curlApi('POST', "/api/requests/{$reqNonNum}/complete", [
    'secret_code' => $secNonNum,
    'latitude' => 'invalid_latitude',
    'longitude' => 'invalid_longitude'
]);
assertChallenge(
    'CH-COORD-06',
    'Completion with non-numeric string coordinates returns HTTP 400',
    $resNonNum['code'] === 400 && str_contains($resNonNum['data']['error'] ?? '', 'GPS-координаты'),
    "HTTP {$resNonNum['code']}, Error: " . ($resNonNum['data']['error'] ?? '')
);

// 1.7 Out-of-range coordinates: latitude: 95.0 (> 90.0)
[$reqRange1, $secRange1] = createAndAccept('Generic Delivery Task Range Lat > 90');
$resRange1 = curlApi('POST', "/api/requests/{$reqRange1}/complete", [
    'secret_code' => $secRange1,
    'latitude' => 95.0,
    'longitude' => 27.5862
]);
assertChallenge(
    'CH-COORD-07',
    'Completion with latitude: 95.0 rejected with HTTP 400 (Некорректные GPS-координаты)',
    $resRange1['code'] === 400 && str_contains($resRange1['data']['error'] ?? '', 'Некорректные GPS-координаты'),
    "HTTP {$resRange1['code']}, Error: " . ($resRange1['data']['error'] ?? '')
);

// 1.8 Out-of-range coordinates: latitude: -95.0 (< -90.0)
$resRange2 = curlApi('POST', "/api/requests/{$reqRange1}/complete", [
    'secret_code' => $secRange1,
    'latitude' => -95.0,
    'longitude' => 27.5862
]);
assertChallenge(
    'CH-COORD-08',
    'Completion with latitude: -95.0 rejected with HTTP 400 (Некорректные GPS-координаты)',
    $resRange2['code'] === 400 && str_contains($resRange2['data']['error'] ?? '', 'Некорректные GPS-координаты'),
    "HTTP {$resRange2['code']}, Error: " . ($resRange2['data']['error'] ?? '')
);

// 1.9 Out-of-range coordinates: longitude: 185.0 (> 180.0)
$resRange3 = curlApi('POST', "/api/requests/{$reqRange1}/complete", [
    'secret_code' => $secRange1,
    'latitude' => 53.9168,
    'longitude' => 185.0
]);
assertChallenge(
    'CH-COORD-09',
    'Completion with longitude: 185.0 rejected with HTTP 400 (Некорректные GPS-координаты)',
    $resRange3['code'] === 400 && str_contains($resRange3['data']['error'] ?? '', 'Некорректные GPS-координаты'),
    "HTTP {$resRange3['code']}, Error: " . ($resRange3['data']['error'] ?? '')
);

// 1.10 Out-of-range coordinates: longitude: -185.0 (< -180.0)
$resRange4 = curlApi('POST', "/api/requests/{$reqRange1}/complete", [
    'secret_code' => $secRange1,
    'latitude' => 53.9168,
    'longitude' => -185.0
]);
assertChallenge(
    'CH-COORD-10',
    'Completion with longitude: -185.0 rejected with HTTP 400 (Некорректные GPS-координаты)',
    $resRange4['code'] === 400 && str_contains($resRange4['data']['error'] ?? '', 'Некорректные GPS-координаты'),
    "HTTP {$resRange4['code']}, Error: " . ($resRange4['data']['error'] ?? '')
);

// -----------------------------------------------------------------------------
// SECTION 2: Exact 150m Boundary Stress Testing
// -----------------------------------------------------------------------------
echo PHP_EOL . "--- [SECTION 2] Exact 150m Boundary Stress Testing ---" . PHP_EOL;

// 2.1 Boundary: 149.95m distance
[$reqB149, $secB149] = createAndAccept('Boundary Test 149.95m');
$resB149 = curlApi('POST', "/api/requests/{$reqB149}/complete", [
    'secret_code' => $secB149,
    'latitude' => 53.9168 + 0.00134853,
    'longitude' => 27.5862
]);
assertChallenge(
    'CH-BOUND-01',
    'Exact 149.95m boundary step succeeds with HTTP 200',
    $resB149['code'] === 200 && ($resB149['data']['success'] ?? false) === true && ($resB149['data']['distance_meters'] ?? 0) <= 150.0,
    "HTTP {$resB149['code']}, distance_meters: " . ($resB149['data']['distance_meters'] ?? 'null')
);

// 2.2 Boundary: 150.00m distance
[$reqB150, $secB150] = createAndAccept('Boundary Test 150.00m');
$resB150 = curlApi('POST', "/api/requests/{$reqB150}/complete", [
    'secret_code' => $secB150,
    'latitude' => 53.9168 + 0.00134898,
    'longitude' => 27.5862
]);
assertChallenge(
    'CH-BOUND-02',
    'Exact 150.00m boundary step succeeds with HTTP 200',
    $resB150['code'] === 200 && ($resB150['data']['success'] ?? false) === true && ($resB150['data']['distance_meters'] ?? 0) <= 150.0,
    "HTTP {$resB150['code']}, distance_meters: " . ($resB150['data']['distance_meters'] ?? 'null')
);

// 2.3 Boundary: 150.05m distance (must FAIL with 400)
[$reqB150_05, $secB150_05] = createAndAccept('Boundary Test 150.05m');
$resB150_05 = curlApi('POST', "/api/requests/{$reqB150_05}/complete", [
    'secret_code' => $secB150_05,
    'latitude' => 53.9168 + 0.00134943,
    'longitude' => 27.5862
]);
assertChallenge(
    'CH-BOUND-03',
    'Exact 150.05m boundary step rejected with HTTP 400 (вне допустимой зоны)',
    $resB150_05['code'] === 400 && str_contains($resB150_05['data']['error'] ?? '', 'вне допустимой зоны'),
    "HTTP {$resB150_05['code']}, Error: " . ($resB150_05['data']['error'] ?? '')
);

// 2.4 Boundary: 151.00m distance (must FAIL with 400)
$deltaLat151 = (151.0 / 6371000.0) * (180.0 / M_PI);
$resB151 = curlApi('POST', "/api/requests/{$reqB150_05}/complete", [
    'secret_code' => $secB150_05,
    'latitude' => 53.9168 + $deltaLat151,
    'longitude' => 27.5862
]);
assertChallenge(
    'CH-BOUND-04',
    '151.00m distance rejected with HTTP 400 (вне допустимой зоны)',
    $resB151['code'] === 400 && str_contains($resB151['data']['error'] ?? '', 'вне допустимой зоны'),
    "HTTP {$resB151['code']}, Error: " . ($resB151['data']['error'] ?? '')
);

// 2.5 Remote distance: 320.00m distance (must FAIL with 400)
$deltaLat320 = (320.0 / 6371000.0) * (180.0 / M_PI);
$resB320 = curlApi('POST', "/api/requests/{$reqB150_05}/complete", [
    'secret_code' => $secB150_05,
    'latitude' => 53.9168 + $deltaLat320,
    'longitude' => 27.5862
]);
assertChallenge(
    'CH-BOUND-05',
    '320.00m remote distance rejected with HTTP 400 (расстояние: 320 м > 150 м)',
    $resB320['code'] === 400 && str_contains($resB320['data']['error'] ?? '', 'вне допустимой зоны'),
    "HTTP {$resB320['code']}, Error: " . ($resB320['data']['error'] ?? '')
);

// -----------------------------------------------------------------------------
// SECTION 3: 5-Attempt Brute-Force Lockout Defense
// -----------------------------------------------------------------------------
echo PHP_EOL . "--- [SECTION 3] 5-Attempt Brute-Force Lockout Defense ---" . PHP_EOL;

[$reqLock, $validSecret] = createAndAccept('Lockout Defense Stress Test');

// Perform attempts 1 through 4 (should return HTTP 400 with decrementing remaining attempts)
$attemptsOk = true;
$attemptDetails = [];
for ($i = 1; $i <= 4; $i++) {
    $resWrong = curlApi('POST', "/api/requests/{$reqLock}/complete", [
        'secret_code' => "WrongAttempt_{$i}",
        'latitude' => 53.9168,
        'longitude' => 27.5862
    ]);
    $expectedRemaining = 5 - $i;
    $hasRemainingText = str_contains($resWrong['data']['error'] ?? '', "Осталось попыток: {$expectedRemaining}");
    if ($resWrong['code'] !== 400 || !$hasRemainingText) {
        $attemptsOk = false;
        $attemptDetails[] = "Attempt {$i}: HTTP {$resWrong['code']}, Error: " . ($resWrong['data']['error'] ?? '');
    }
}
assertChallenge(
    'CH-LOCK-01',
    'Attempts 1 to 4 return HTTP 400 and report decrementing remaining attempts (4..1)',
    $attemptsOk,
    $attemptsOk ? 'All 4 attempts correctly reported remaining counts' : implode('; ', $attemptDetails)
);

// 5th failed attempt: should trigger lockout
$resLock5 = curlApi('POST', "/api/requests/{$reqLock}/complete", [
    'secret_code' => "WrongAttempt_5",
    'latitude' => 53.9168,
    'longitude' => 27.5862
]);
$is5thLocked = $resLock5['code'] === 400 && str_contains($resLock5['data']['error'] ?? '', 'Превышено максимальное количество попыток');
assertChallenge(
    'CH-LOCK-02',
    '5th failed attempt triggers lockout (HTTP 400, Превышено максимальное количество попыток)',
    $is5thLocked,
    "HTTP {$resLock5['code']}, Error: " . ($resLock5['data']['error'] ?? '')
);

// 6th attempt with VALID, AUTHENTIC secret code: MUST BE REJECTED!
$resLock6 = curlApi('POST', "/api/requests/{$reqLock}/complete", [
    'secret_code' => $validSecret,
    'latitude' => 53.9168,
    'longitude' => 27.5862
]);
$is6thRejected = $resLock6['code'] === 400 && str_contains($resLock6['data']['error'] ?? '', 'Превышено максимальное количество попыток');
assertChallenge(
    'CH-LOCK-03',
    '6th attempt with authentic secret code is permanently rejected (HTTP 400, locked)',
    $is6thRejected,
    "HTTP {$resLock6['code']}, Error: " . ($resLock6['data']['error'] ?? '')
);

// Verify database state: request must NOT be completed!
$dbCheck = curlApi('GET', "/api/requests/{$reqLock}");
$reqStatus = $dbCheck['data']['data']['status'] ?? '';
$reqFailedAttempts = (int)($dbCheck['data']['data']['failed_attempts'] ?? 0);
assertChallenge(
    'CH-LOCK-04',
    'Locked request remains in_progress with failed_attempts = 5 in database',
    $reqStatus === 'in_progress' && $reqFailedAttempts === 5,
    "status: '{$reqStatus}', failed_attempts: {$reqFailedAttempts}"
);

// -----------------------------------------------------------------------------
// SECTION 4: Exhaustive Codebase Zero-Emoji Audit
// -----------------------------------------------------------------------------
echo PHP_EOL . "--- [SECTION 4] Exhaustive Codebase Zero-Emoji Audit ---" . PHP_EOL;

$emojiRegex = '/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u';

// 4.1 Frontend UI files (public/) and Architecture documentation (plan/)
$frontendFiles = [];
$frontendDirs = ['/var/www/html/public', '/var/www/html/plan'];
foreach ($frontendDirs as $d) {
    if (!is_dir($d)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) $frontendFiles[] = $f->getPathname();
    }
}
$frontendEmojiHits = [];
foreach ($frontendFiles as $fp) {
    $c = (string)file_get_contents($fp);
    if (preg_match_all($emojiRegex, $c, $m)) {
        $frontendEmojiHits[$fp] = $m[0];
    }
}
assertChallenge(
    'CH-EMOJI-FRONTEND',
    'Strict Zero-Emoji compliance in public/ and plan/ (WCAG 2.1 AAA)',
    empty($frontendEmojiHits),
    empty($frontendEmojiHits) ? "All " . count($frontendFiles) . " files 100% SVG Vector Clean" : "Found in " . json_encode($frontendEmojiHits)
);

// 4.2 Backend MVC Source Code (app/)
$appFiles = [];
$itApp = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/var/www/html/app', RecursiveDirectoryIterator::SKIP_DOTS));
foreach ($itApp as $f) {
    if ($f->isFile()) $appFiles[] = $f->getPathname();
}
$appEmojiHits = [];
foreach ($appFiles as $fp) {
    $c = (string)file_get_contents($fp);
    if (preg_match_all($emojiRegex, $c, $m)) {
        $appEmojiHits[$fp] = $m[0];
    }
}
assertChallenge(
    'CH-EMOJI-BACKEND',
    'Strict Zero-Emoji compliance in app/ MVC core code',
    empty($appEmojiHits),
    empty($appEmojiHits) ? "All " . count($appFiles) . " PHP classes clean" : "Found in " . json_encode($appEmojiHits)
);

// 4.3 Database DDL & Seed (database/)
$dbFiles = ['/var/www/html/database/schema.sql', '/var/www/html/database/migrate.php', '/var/www/html/database/seed.php'];
$dbEmojiHits = [];
foreach ($dbFiles as $fp) {
    if (file_exists($fp)) {
        $c = (string)file_get_contents($fp);
        if (preg_match_all($emojiRegex, $c, $m)) {
            $dbEmojiHits[$fp] = array_unique($m[0]);
        }
    }
}
assertChallenge(
    'CH-EMOJI-DATABASE',
    'Strict Zero-Emoji compliance in database/ files',
    empty($dbEmojiHits),
    empty($dbEmojiHits) ? "Database files clean" : "DEFECT: Found emojis in database files: " . json_encode($dbEmojiHits, JSON_UNESCAPED_UNICODE)
);

// 4.4 Test Suites (tests/)
$testFiles = [];
$itTests = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/var/www/html/tests', RecursiveDirectoryIterator::SKIP_DOTS));
foreach ($itTests as $f) {
    if ($f->isFile() && $f->getFilename() !== 'test_challenger_m5_1.php') {
        $testFiles[] = $f->getPathname();
    }
}
$testEmojiHits = [];
foreach ($testFiles as $fp) {
    $c = (string)file_get_contents($fp);
    if (preg_match_all($emojiRegex, $c, $m)) {
        $testEmojiHits[$fp] = array_unique($m[0]);
    }
}
assertChallenge(
    'CH-EMOJI-TESTS',
    'Strict Zero-Emoji compliance in tests/ suites',
    empty($testEmojiHits),
    empty($testEmojiHits) ? "All test suites clean" : "DEFECT: Found emojis in test files: " . json_encode($testEmojiHits, JSON_UNESCAPED_UNICODE)
);

// 4.5 Root Project Documentation (README.md, TEST_INFRA.md, etc.)
$rootDocs = ['/var/www/html/README.md', '/var/www/html/TEST_INFRA.md', '/var/www/html/PROJECT.md', '/var/www/html/TEST_READY.md'];
$docEmojiHits = [];
foreach ($rootDocs as $fp) {
    if (file_exists($fp)) {
        $c = (string)file_get_contents($fp);
        if (preg_match_all($emojiRegex, $c, $m)) {
            $docEmojiHits[$fp] = array_unique($m[0]);
        }
    }
}
assertChallenge(
    'CH-EMOJI-DOCS',
    'Strict Zero-Emoji compliance in root markdown documentation',
    empty($docEmojiHits),
    empty($docEmojiHits) ? "Root markdown clean" : "DEFECT: Found emojis in docs: " . json_encode($docEmojiHits, JSON_UNESCAPED_UNICODE)
);

// -----------------------------------------------------------------------------
// SUMMARY & VERDICT
// -----------------------------------------------------------------------------
echo PHP_EOL . "================================================================================" . PHP_EOL;
echo "  CHALLENGER STRESS HARNESS SUMMARY" . PHP_EOL;
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
