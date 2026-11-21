<?php

declare(strict_types=1);




































spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/../app/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Model.php';
require_once __DIR__ . '/../app/Core/Logger.php';
require_once __DIR__ . '/../app/Core/RateLimiter.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/HelpRequest.php';
require_once __DIR__ . '/../app/Models/ElderlyProfile.php';
require_once __DIR__ . '/../app/Models/VolunteerProfile.php';
require_once __DIR__ . '/../app/Models/SosAlert.php';
require_once __DIR__ . '/../app/Services/GeoMatchingService.php';
require_once __DIR__ . '/../app/Services/SecurityService.php';
require_once __DIR__ . '/../app/Services/AuthService.php';
require_once __DIR__ . '/e2e_suite/TestHarness.php';

use App\Core\Database;
use App\Core\RateLimiter;
use App\Models\HelpRequest;
use App\Models\ElderlyProfile;
use App\Models\VolunteerProfile;
use App\Services\GeoMatchingService;
use App\Services\SecurityService;
use Tests\E2ESuite\TestHarness;

$startTime = microtime(true);
$totalAssertions = 0;
$passedAssertions = 0;
$failedAssertions = 0;
$findings = [];

function assertAdv(string $id, string $description, bool $condition, string $details = ''): void
{
    global $totalAssertions, $passedAssertions, $failedAssertions, $findings;
    $totalAssertions++;
    if ($condition) {
        $passedAssertions++;
        echo "  \033[32m[PASS]\033[0m {$id}: {$description}\n";
    } else {
        $failedAssertions++;
        $findings[] = "{$id}: {$description} - Details: {$details}";
        echo "  \033[31m[FAIL]\033[0m {$id}: {$description} - Details: {$details}\n";
    }
}

echo "================================================================================\n";
echo "   ADVERSARIAL STRESS TEST SUITE: MILESTONE 3 (GEOFENCING & SECRET WORDS)       \n";
echo "================================================================================\n\n";

$harness = TestHarness::getInstance('http://127.0.0.1:8000');
$createdRequestIds = [];

try {




    echo "--- [SECTION 1] Haversine Numerical Stability & Clamping Stress ---\n";


    $zeroKm = GeoMatchingService::calculateDistance(53.9168, 27.5862, 53.9168, 27.5862);
    $zeroM = GeoMatchingService::calculateDistanceMeters(53.9168, 27.5862, 53.9168, 27.5862);
    assertAdv('ADV-GEO-01', 'Identical coordinates yield exactly 0.0 km', $zeroKm === 0.0, "Got: {$zeroKm}");
    assertAdv('ADV-GEO-02', 'Identical coordinates yield exactly 0.0 meters', $zeroM === 0.0, "Got: {$zeroM}");


    $latA = 53.916800;
    $latB = 53.916810;
    $lonA = 27.586200;
    $microKm = GeoMatchingService::calculateDistance($latA, $lonA, $latB, $lonA);
    $microM = GeoMatchingService::calculateDistanceMeters($latA, $lonA, $latB, $lonA);
    assertAdv('ADV-GEO-03', 'Micro-distance 0.00001 deg latitude resolves to ~1.11 meters',
        $microM >= 1.10 && $microM <= 1.13, "Got: {$microM}m");
    assertAdv('ADV-GEO-04', 'Micro-distance kilometers resolution retains 4 decimals (~0.0011 km)',
        $microKm === 0.0011, "Got: {$microKm} km");


    $microMRev = GeoMatchingService::calculateDistanceMeters($latB, $lonA, $latA, $lonA);
    assertAdv('ADV-GEO-05', 'Micro-distance Haversine calculation is perfectly symmetric',
        $microM === $microMRev, "A->B: {$microM}m vs B->A: {$microMRev}m");



    $dAntipodal1 = GeoMatchingService::calculateDistance(0.0, 0.0, 0.0, 180.0);
    $dAntipodal1M = GeoMatchingService::calculateDistanceMeters(0.0, 0.0, 0.0, 180.0);
    $halfCircumferenceKm = round(GeoMatchingService::EARTH_RADIUS_KM * M_PI, 4);
    $halfCircumferenceM = round(GeoMatchingService::EARTH_RADIUS_METERS * M_PI, 2);
    assertAdv('ADV-GEO-06', 'Antipodal (0,0) to (0,180) produces no NaN and matches half-circumference km',
        !is_nan($dAntipodal1) && !is_infinite($dAntipodal1) && abs($dAntipodal1 - $halfCircumferenceKm) < 0.1,
        "Got: {$dAntipodal1} km (expected ~{$halfCircumferenceKm})");
    assertAdv('ADV-GEO-07', 'Antipodal (0,0) to (0,180) produces no NaN in meters calculation',
        !is_nan($dAntipodal1M) && !is_infinite($dAntipodal1M) && abs($dAntipodal1M - $halfCircumferenceM) < 100.0,
        "Got: {$dAntipodal1M}m");


    $dAntipodal2 = GeoMatchingService::calculateDistance(0.0, 0.0, 0.0, -180.0);
    assertAdv('ADV-GEO-08', 'Antipodal (0,0) to (0,-180) produces valid positive distance without NaN',
        !is_nan($dAntipodal2) && $dAntipodal2 > 20000.0, "Got: {$dAntipodal2} km");


    $dPole = GeoMatchingService::calculateDistance(90.0, 0.0, -90.0, 0.0);
    $dPoleM = GeoMatchingService::calculateDistanceMeters(90.0, 0.0, -90.0, 0.0);
    assertAdv('ADV-GEO-09', 'North Pole (90,0) to South Pole (-90,0) produces valid finite distance',
        !is_nan($dPole) && abs($dPole - $halfCircumferenceKm) < 0.1, "Got: {$dPole} km");
    assertAdv('ADV-GEO-10', 'South Pole (-90,0) to North Pole (90,0) produces identical distance',
        GeoMatchingService::calculateDistance(-90.0, 0.0, 90.0, 0.0) === $dPole, "Reverse mismatch");


    $dDiag = GeoMatchingService::calculateDistance(45.0, 90.0, -45.0, -90.0);
    assertAdv('ADV-GEO-11', 'Diagonal antipodal (45, 90) to (-45, -90) clamped cleanly without NaN',
        !is_nan($dDiag) && abs($dDiag - $halfCircumferenceKm) < 0.1, "Got: {$dDiag} km");


    $dExtreme = GeoMatchingService::calculateDistance(-90.0, -180.0, 90.0, 180.0);
    assertAdv('ADV-GEO-12', 'Extreme coordinates (-90, -180) to (90, 180) compute safely',
        !is_nan($dExtreme) && $dExtreme > 0.0, "Got: {$dExtreme} km");





    echo "\n--- [SECTION 2] Geofence Boundary Thresholds (149.9m, 150.0m, 150.1m, 500m, 1km, 10km) ---\n";



    $degPerMeterLat = 180.0 / (GeoMatchingService::EARTH_RADIUS_METERS * M_PI);
    $baseLat = 53.916800;
    $baseLon = 27.586200;


    $lat149_9 = $baseLat + (149.9 * $degPerMeterLat);
    $dist149_9 = GeoMatchingService::calculateDistanceMeters($baseLat, $baseLon, $lat149_9, $baseLon);
    $in149_9 = GeoMatchingService::isWithinGeofence($baseLat, $baseLon, $lat149_9, $baseLon, 150.0);
    $inRadius149_9 = GeoMatchingService::isWithinRadius($baseLat, $baseLon, $lat149_9, $baseLon, 150.0);
    assertAdv('ADV-BND-01', '149.9m distance computes to exactly 149.90m', abs($dist149_9 - 149.9) < 0.05, "Got: {$dist149_9}m");
    assertAdv('ADV-BND-02', 'Exactly 149.9m is accepted by isWithinGeofence() == true', $in149_9 === true, "Got: " . var_export($in149_9, true));
    assertAdv('ADV-BND-03', 'Exactly 149.9m is accepted by isWithinRadius() == true', $inRadius149_9 === true, "Got: " . var_export($inRadius149_9, true));


    $lat150_0 = $baseLat + (150.0 * $degPerMeterLat);
    $dist150_0 = GeoMatchingService::calculateDistanceMeters($baseLat, $baseLon, $lat150_0, $baseLon);
    $in150_0 = GeoMatchingService::isWithinGeofence($baseLat, $baseLon, $lat150_0, $baseLon, 150.0);
    $inRadius150_0 = GeoMatchingService::isWithinRadius($baseLat, $baseLon, $lat150_0, $baseLon, 150.0);
    assertAdv('ADV-BND-04', '150.0m distance computes to exactly 150.00m', abs($dist150_0 - 150.0) < 0.05, "Got: {$dist150_0}m");
    assertAdv('ADV-BND-05', 'Exact boundary 150.0m MUST be within geofence (isWithinGeofence() == true)', $in150_0 === true, "Got: " . var_export($in150_0, true));
    assertAdv('ADV-BND-06', 'Exact boundary 150.0m MUST be within geofence (isWithinRadius() == true)', $inRadius150_0 === true, "Got: " . var_export($inRadius150_0, true));


    $lat150_1 = $baseLat + (150.1 * $degPerMeterLat);
    $dist150_1 = GeoMatchingService::calculateDistanceMeters($baseLat, $baseLon, $lat150_1, $baseLon);
    $in150_1 = GeoMatchingService::isWithinGeofence($baseLat, $baseLon, $lat150_1, $baseLon, 150.0);
    $inRadius150_1 = GeoMatchingService::isWithinRadius($baseLat, $baseLon, $lat150_1, $baseLon, 150.0);
    assertAdv('ADV-BND-07', '150.1m distance computes to exactly 150.10m', abs($dist150_1 - 150.1) < 0.05, "Got: {$dist150_1}m");
    assertAdv('ADV-BND-08', 'Exactly 150.1m MUST be rejected as outside geofence (isWithinGeofence() == false)', $in150_1 === false, "Got: " . var_export($in150_1, true));
    assertAdv('ADV-BND-09', 'Exactly 150.1m MUST be rejected as outside geofence (isWithinRadius() == false)', $inRadius150_1 === false, "Got: " . var_export($inRadius150_1, true));


    $lat500m = $baseLat + (500.0 * $degPerMeterLat);
    $lat1000m = $baseLat + (1000.0 * $degPerMeterLat);
    $lat10000m = $baseLat + (10000.0 * $degPerMeterLat);
    assertAdv('ADV-BND-10', '500 meters is strictly rejected by geofence',
        GeoMatchingService::isWithinGeofence($baseLat, $baseLon, $lat500m, $baseLon, 150.0) === false);
    assertAdv('ADV-BND-11', '1000 meters is strictly rejected by geofence',
        GeoMatchingService::isWithinGeofence($baseLat, $baseLon, $lat1000m, $baseLon, 150.0) === false);
    assertAdv('ADV-BND-12', '10000 meters is strictly rejected by geofence',
        GeoMatchingService::isWithinGeofence($baseLat, $baseLon, $lat10000m, $baseLon, 150.0) === false);





    echo "\n--- [SECTION 3] Cyrillic Secret Word Hardening & Normalization Pipeline ---\n";


    assertAdv('ADV-SEC-01', 'Guillemets quotes «МЁД» normalize to мед', SecurityService::verifyCode('«МЁД»', 'мед'));
    assertAdv('ADV-SEC-02', 'Unicode double quotes “мед” normalize to мед', SecurityService::verifyCode('“мед”', 'мед'));
    assertAdv('ADV-SEC-03', 'Unicode single quotes ‘мёд’ normalize to мед', SecurityService::verifyCode('‘мёд’', 'мед'));
    assertAdv('ADV-SEC-04', 'ASCII double quotes "Мёд" normalize to мед', SecurityService::verifyCode('"Мёд"', 'мед'));
    assertAdv('ADV-SEC-05', 'ASCII single quotes \'мед\' normalize to мед', SecurityService::verifyCode('\'мед\'', 'мед'));
    assertAdv('ADV-SEC-06', 'Backtick `мед` normalize to мед', SecurityService::verifyCode('`мед`', 'мед'));
    assertAdv('ADV-SEC-07', 'Nested quotes «“‘МЁД’”» normalize to мед', SecurityService::verifyCode('«“‘МЁД’”»', 'мед'));


    assertAdv('ADV-SEC-08', 'Word подъезд matches подъёзд', SecurityService::verifyCode('подъезд', 'подъёзд'));
    assertAdv('ADV-SEC-09', 'Word подъёзд matches подъезд', SecurityService::verifyCode('подъёзд', 'подъезд'));
    assertAdv('ADV-SEC-10', 'Word ёлка matches елка', SecurityService::verifyCode('ёлка', 'елка'));
    assertAdv('ADV-SEC-11', 'Word елка matches ёлка', SecurityService::verifyCode('елка', 'ёлка'));
    assertAdv('ADV-SEC-12', 'Word берёза matches береза', SecurityService::verifyCode('берёза', 'береза'));
    assertAdv('ADV-SEC-13', 'Word береза matches берёза', SecurityService::verifyCode('береза', 'берёза'));


    assertAdv('ADV-SEC-14', 'Leading whitespace is trimmed', SecurityService::verifyCode('   мед', 'мед'));
    assertAdv('ADV-SEC-15', 'Trailing whitespace is trimmed', SecurityService::verifyCode('мед   ', 'мед'));
    assertAdv('ADV-SEC-16', 'Both leading and trailing multiple whitespace trimmed', SecurityService::verifyCode('     мед     ', 'мед'));
    assertAdv('ADV-SEC-17', 'Whitespace around quotes trimmed ("   «МЁД»   ")', SecurityService::verifyCode('   «МЁД»   ', 'мед'));
    assertAdv('ADV-SEC-18', 'Whitespace inside quotes trimmed ("«   МЁД   »")', SecurityService::verifyCode('«   МЁД   »', 'мед'));


    assertAdv('ADV-SEC-19', 'Empty string provided code is strictly rejected', !SecurityService::verifyCode('', 'мед'));
    assertAdv('ADV-SEC-20', 'Whitespace-only provided code ("   ") is strictly rejected', !SecurityService::verifyCode('   ', 'мед'));
    assertAdv('ADV-SEC-21', 'Tabs and newlines only ("\\t\\r\\n") is strictly rejected', !SecurityService::verifyCode("\t\r\n", 'мед'));
    assertAdv('ADV-SEC-22', 'Empty expected code rejects provided input', !SecurityService::verifyCode('мед', ''));
    assertAdv('ADV-SEC-23', 'Both empty strings return false (no trivial zero-length collision)', !SecurityService::verifyCode('', ''));
    assertAdv('ADV-SEC-24', 'Both whitespace strings return false', !SecurityService::verifyCode('   ', '   '));


    assertAdv('ADV-SEC-25', 'GeoMatchingService::validateSecretWord proxies SecurityService::verifyCode',
        GeoMatchingService::validateSecretWord('«МЁД»', 'мед') === true &&
        GeoMatchingService::validateSecretWord('Неверно', 'мед') === false);



    $singleWordInnerSpace = SecurityService::verifyCode('под   ъезд', 'подъезд');
    assertAdv('ADV-SEC-26', 'Accidental space injection inside single word (под   ъезд) is safely rejected',
        $singleWordInnerSpace === false, "Got: " . var_export($singleWordInnerSpace, true));





    echo "\n--- [SECTION 4] Brute-force Lockout Defense on HelpRequestController::complete ---\n";

    RateLimiter::clear("create_request:127.0.0.1");
    RateLimiter::clear("create_request:");


    $reqA = $harness->createHelpRequest(
        1, 1, 'ADV_M3_Lockout_Task', 'Adversarial lockout testing', 'пр-т Независимости 45', 53.9168, 27.5862
    );
    $reqAId = (int)($reqA['data']['request_id'] ?? 0);
    $createdRequestIds[] = $reqAId;
    $secretCodeA = $reqA['data']['secret_code'] ?? '';
    assertAdv('ADV-LCK-01', "Request #{$reqAId} created with secret code '{$secretCodeA}'", $reqAId > 0 && !empty($secretCodeA));


    $acceptResA = $harness->apiCall('POST', "/api/requests/{$reqAId}/accept", ['volunteer_id' => 2]);
    assertAdv('ADV-LCK-02', "Request #{$reqAId} accepted by volunteer #2", $acceptResA['code'] === 200);


    $initialDbReqA = HelpRequest::find($reqAId);
    assertAdv('ADV-LCK-03', 'Initial failed_attempts in database is 0', (int)($initialDbReqA['failed_attempts'] ?? 0) === 0);


    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $resWrong = $harness->apiCall('POST', "/api/requests/{$reqAId}/complete", [
            'secret_code' => "WrongWord{$attempt}",
            'latitude' => 53.9168,
            'longitude' => 27.5862
        ]);
        $dbReq = HelpRequest::find($reqAId);
        $remainingExpected = 5 - $attempt;
        $hasRemainingMsg = strpos($resWrong['data']['error'] ?? '', (string)$remainingExpected) !== false;
        assertAdv("ADV-LCK-04-{$attempt}", "Attempt {$attempt}/5 fails (HTTP 400), counter = {$attempt}, remaining = {$remainingExpected}",
            $resWrong['code'] === 400 && (int)($dbReq['failed_attempts'] ?? 0) === $attempt && $hasRemainingMsg,
            "HTTP {$resWrong['code']}, DB count: " . ($dbReq['failed_attempts'] ?? 'null')
        );
    }


    $res5 = $harness->apiCall('POST', "/api/requests/{$reqAId}/complete", [
        'secret_code' => "WrongWord5",
        'latitude' => 53.9168,
        'longitude' => 27.5862
    ]);
    $dbReq5 = HelpRequest::find($reqAId);
    $hasLockoutMsg5 = strpos($res5['data']['error'] ?? '', 'Превышено максимальное количество') !== false;
    assertAdv('ADV-LCK-05', '5th failed attempt triggers brute-force lockout message (HTTP 400)',
        $res5['code'] === 400 && (int)($dbReq5['failed_attempts'] ?? 0) === 5 && $hasLockoutMsg5,
        "HTTP {$res5['code']}, Error: " . ($res5['data']['error'] ?? '')
    );


    $res6 = $harness->apiCall('POST', "/api/requests/{$reqAId}/complete", [
        'secret_code' => $secretCodeA,
        'latitude' => 53.9168,
        'longitude' => 27.5862
    ]);
    $dbReq6 = HelpRequest::find($reqAId);
    $hasLockedMsg6 = strpos($res6['data']['error'] ?? '', 'заблокирована') !== false;
    assertAdv('ADV-LCK-06', 'Attempt 6 with CORRECT secret code is strictly BLOCKED by lockout guard',
        $res6['code'] === 400 && $dbReq6['status'] === 'in_progress' && $hasLockedMsg6,
        "HTTP {$res6['code']}, Status: {$dbReq6['status']}, Error: " . ($res6['data']['error'] ?? '')
    );


    RateLimiter::clear("create_request:127.0.0.1");
    RateLimiter::clear("create_request:");
    $reqB = $harness->createHelpRequest(
        1, 1, 'ADV_M3_Recovery_Task', 'Adversarial recovery testing', 'пр-т Независимости 45', 53.9168, 27.5862
    );
    $reqBId = (int)($reqB['data']['request_id'] ?? 0);
    $createdRequestIds[] = $reqBId;
    $secretCodeB = $reqB['data']['secret_code'] ?? '';
    assertAdv('ADV-LCK-07', "Request #{$reqBId} created with secret code '{$secretCodeB}'", $reqBId > 0 && !empty($secretCodeB));


    $harness->apiCall('POST', "/api/requests/{$reqBId}/accept", ['volunteer_id' => 2]);


    $harness->apiCall('POST', "/api/requests/{$reqBId}/complete", [
        'secret_code' => 'Bad1',
        'latitude' => 53.9168,
        'longitude' => 27.5862
    ]);
    $harness->apiCall('POST', "/api/requests/{$reqBId}/complete", [
        'secret_code' => 'Bad2',
        'latitude' => 53.9168,
        'longitude' => 27.5862
    ]);
    $dbReqBPre = HelpRequest::find($reqBId);
    assertAdv('ADV-LCK-08', 'Request B accumulated exactly 2 failed attempts', (int)($dbReqBPre['failed_attempts'] ?? 0) === 2);


    $normalizedInput = '«' . mb_strtoupper($secretCodeB, 'UTF-8') . '»';
    $resBSuccess = $harness->apiCall('POST', "/api/requests/{$reqBId}/complete", [
        'secret_code' => $normalizedInput,
        'latitude' => 53.9170,
        'longitude' => 27.5863
    ]);
    $dbReqBPost = HelpRequest::find($reqBId);
    assertAdv('ADV-LCK-09', 'Submission of valid code within geofence returns HTTP 200', $resBSuccess['code'] === 200);
    assertAdv('ADV-LCK-10', 'Completion resets failed_attempts to 0 and updates status to completed',
        $dbReqBPost['status'] === 'completed' && (int)($dbReqBPost['failed_attempts'] ?? -1) === 0,
        "Status: {$dbReqBPost['status']}, failed_attempts: {$dbReqBPost['failed_attempts']}"
    );
    assertAdv('ADV-LCK-11', 'Database records telemetry (completed_lat, completed_lon, distance_to_target)',
        $dbReqBPost['completed_lat'] !== null && $dbReqBPost['completed_lon'] !== null && $dbReqBPost['distance_to_target'] !== null,
        "Distance recorded: " . ($dbReqBPost['distance_to_target'] ?? 'null')
    );





    echo "\n--- [SECTION 5] End-to-End HTTP API Geofence Boundary Enforcement ---\n";


    RateLimiter::clear("create_request:127.0.0.1");
    RateLimiter::clear("create_request:");
    $req149 = $harness->createHelpRequest(1, 1, 'ADV_M3_Boundary_149_9', 'Testing 149.9m boundary', 'пр-т Независимости 45', 53.9168, 27.5862);
    $id149 = (int)($req149['data']['request_id'] ?? 0);
    $createdRequestIds[] = $id149;
    $sec149 = $req149['data']['secret_code'] ?? '';
    $harness->apiCall('POST', "/api/requests/{$id149}/accept", ['volunteer_id' => 2]);

    $resApi149 = $harness->apiCall('POST', "/api/requests/{$id149}/complete", [
        'secret_code' => $sec149,
        'latitude' => $lat149_9,
        'longitude' => $baseLon
    ]);
    assertAdv('ADV-API-BND-01', 'API completion at 149.9m away succeeds with HTTP 200',
        $resApi149['code'] === 200 && ($resApi149['data']['success'] ?? false) === true,
        "HTTP {$resApi149['code']}"
    );


    RateLimiter::clear("create_request:127.0.0.1");
    RateLimiter::clear("create_request:");
    $req150 = $harness->createHelpRequest(1, 1, 'ADV_M3_Boundary_150_0', 'Testing 150.0m boundary', 'пр-т Независимости 45', 53.9168, 27.5862);
    $id150 = (int)($req150['data']['request_id'] ?? 0);
    $createdRequestIds[] = $id150;
    $sec150 = $req150['data']['secret_code'] ?? '';
    $harness->apiCall('POST', "/api/requests/{$id150}/accept", ['volunteer_id' => 2]);

    $resApi150 = $harness->apiCall('POST', "/api/requests/{$id150}/complete", [
        'secret_code' => $sec150,
        'latitude' => $lat150_0,
        'longitude' => $baseLon
    ]);
    assertAdv('ADV-API-BND-02', 'API completion at exactly 150.0m boundary succeeds with HTTP 200',
        $resApi150['code'] === 200 && ($resApi150['data']['success'] ?? false) === true,
        "HTTP {$resApi150['code']}"
    );


    RateLimiter::clear("create_request:127.0.0.1");
    RateLimiter::clear("create_request:");
    $req151 = $harness->createHelpRequest(1, 1, 'ADV_M3_Boundary_150_1', 'Testing 150.1m boundary', 'пр-т Независимости 45', 53.9168, 27.5862);
    $id151 = (int)($req151['data']['request_id'] ?? 0);
    $createdRequestIds[] = $id151;
    $sec151 = $req151['data']['secret_code'] ?? '';
    $harness->apiCall('POST', "/api/requests/{$id151}/accept", ['volunteer_id' => 2]);

    $resApi151 = $harness->apiCall('POST', "/api/requests/{$id151}/complete", [
        'secret_code' => $sec151,
        'latitude' => $lat150_1,
        'longitude' => $baseLon
    ]);
    $dbReq151 = HelpRequest::find($id151);
    $outOfZoneMsg = strpos($resApi151['data']['error'] ?? '', 'Волонтёр находится вне допустимой зоны') !== false;
    assertAdv('ADV-API-BND-03', 'API completion at 150.1m is rejected with HTTP 400 and out-of-zone error',
        $resApi151['code'] === 400 && $outOfZoneMsg && $dbReq151['status'] === 'in_progress',
        "HTTP {$resApi151['code']}, Status: {$dbReq151['status']}, Msg: " . ($resApi151['data']['error'] ?? '')
    );


    $resApi500 = $harness->apiCall('POST', "/api/requests/{$id151}/complete", [
        'secret_code' => $sec151,
        'latitude' => $lat500m,
        'longitude' => $baseLon
    ]);
    assertAdv('ADV-API-BND-04', 'API completion at 500m is rejected with HTTP 400',
        $resApi500['code'] === 400, "HTTP {$resApi500['code']}");


    $resApi1000 = $harness->apiCall('POST', "/api/requests/{$id151}/complete", [
        'secret_code' => $sec151,
        'latitude' => $lat1000m,
        'longitude' => $baseLon
    ]);
    assertAdv('ADV-API-BND-05', 'API completion at 1000m is rejected with HTTP 400',
        $resApi1000['code'] === 400, "HTTP {$resApi1000['code']}");


    $resApi10000 = $harness->apiCall('POST', "/api/requests/{$id151}/complete", [
        'secret_code' => $sec151,
        'latitude' => $lat10000m,
        'longitude' => $baseLon
    ]);
    assertAdv('ADV-API-BND-06', 'API completion at 10000m is rejected with HTTP 400',
        $resApi10000['code'] === 400, "HTTP {$resApi10000['code']}");

} finally {



    echo "\n--- [SECTION 6] Database Cleanup & Temporary Test Rows Purge ---\n";

    $pdo = Database::getConnection();
    if (!empty($createdRequestIds)) {
        $idList = implode(',', array_map('intval', $createdRequestIds));
        $deletedCount = $pdo->exec("DELETE FROM help_requests WHERE id IN ({$idList})");
        assertAdv('ADV-CLN-01', "Cleaned up {$deletedCount} tracked test request rows (IDs: {$idList})",
            $deletedCount > 0, "Deleted: {$deletedCount}");
    }


    $sweepDeleted = $pdo->exec("DELETE FROM help_requests WHERE title LIKE 'ADV_M3_%'");
    $remCheck = $pdo->query("SELECT COUNT(*) FROM help_requests WHERE title LIKE 'ADV_M3_%'")->fetchColumn();
    assertAdv('ADV-CLN-02', 'Database verified 100% clean of ADV_M3_% test rows', (int)$remCheck === 0, "Remaining: {$remCheck}");
}

$duration = round((microtime(true) - $startTime) * 1000, 2);

echo "\n================================================================================\n";
echo "   ADVERSARIAL STRESS TEST AUDIT SUMMARY                                        \n";
echo "================================================================================\n";
echo "  Total Assertions: {$totalAssertions}\n";
echo "  Passed:           \033[32m{$passedAssertions}\033[0m\n";
echo "  Failed:           " . ($failedAssertions > 0 ? "\033[31m{$failedAssertions}\033[0m" : "0") . "\n";
echo "  Duration:         {$duration} ms\n";
echo "================================================================================\n";

if ($failedAssertions === 0) {
    echo "\n>>> VERDICT: CONFIRM (All adversarial stress tests passed 100%)\n\n";
    exit(0);
} else {
    echo "\n>>> VERDICT: DEFECT_FOUND ({$failedAssertions} assertions failed)\n\n";
    foreach ($findings as $f) {
        echo "  - {$f}\n";
    }
    echo "\n";
    exit(1);
}
