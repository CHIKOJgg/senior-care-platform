<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Model.php';
require_once __DIR__ . '/../app/Core/Logger.php';
require_once __DIR__ . '/../app/Core/RateLimiter.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/ElderlyProfile.php';
require_once __DIR__ . '/../app/Models/VolunteerProfile.php';
require_once __DIR__ . '/../app/Models/HelpRequest.php';
require_once __DIR__ . '/../app/Models/SosAlert.php';
require_once __DIR__ . '/../app/Services/CryptoService.php';
require_once __DIR__ . '/../app/Services/DataMaskingService.php';
require_once __DIR__ . '/../app/Services/PrivacyService.php';
require_once __DIR__ . '/../app/Services/GeoMatchingService.php';
require_once __DIR__ . '/../app/Services/BadgeService.php';
require_once __DIR__ . '/../app/Services/Emergency/EmergencyAlertAdapterInterface.php';
require_once __DIR__ . '/../app/Services/Emergency/MchsEmergencyAdapter.php';
require_once __DIR__ . '/../app/Services/AuthService.php';
require_once __DIR__ . '/e2e_suite/TestHarness.php';

use App\Core\Database;
use App\Models\User;
use App\Models\ElderlyProfile;
use App\Models\VolunteerProfile;
use App\Models\HelpRequest;
use App\Models\SosAlert;
use App\Services\CryptoService;
use App\Services\DataMaskingService;
use App\Services\PrivacyService;
use App\Services\GeoMatchingService;
use App\Services\BadgeService;
use App\Services\Emergency\MchsEmergencyAdapter;
use App\Services\AuthService;
use Tests\E2ESuite\TestHarness;

$harness = TestHarness::getInstance();
$passed = 0;
$failed = 0;
$total = 0;

function assertAdv(string $id, string $title, bool $condition, string $details = ''): bool
{
    global $passed, $failed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$id}: {$title}\n";
        return true;
    } else {
        $failed++;
        echo "  [FAIL] {$id}: {$title} - {$details}\n";
        return false;
    }
}

echo "\n========================================================\n";
echo "   MILESTONE 5: TIER 5 ADVERSARIAL STRESS SUITE\n";
echo "========================================================\n\n";

// =========================================================================
// Category 1: Crypto & Privacy Adversarial Tests (ADV5-CP-01 .. ADV5-CP-07)
// =========================================================================
echo "[Category 1] Cryptography & Privacy Edge Cases (7 tests):\n";

// ADV5-CP-01: Read profile with tampered mobility_notes ciphertext
$pdo = Database::getConnection();
$resUserCP1 = $harness->registerUser(1, 'Тест Шифрования Пожилой');
$uidCP1 = (int)$resUserCP1['user']['id'];
$stmt = $pdo->prepare("UPDATE elderly_profiles SET mobility_notes = :notes WHERE user_id = :id");
$stmt->execute(['notes' => 'enc:v1:corrupted_tampered_payload_xyz', 'id' => $uidCP1]);

$profileCP1 = ElderlyProfile::find($uidCP1);
assertAdv(
    'ADV5-CP-01',
    'Read profile with tampered mobility_notes ciphertext handled gracefully without crash',
    $profileCP1 !== null && $profileCP1['mobility_notes'] === null,
    'Returned: ' . var_export($profileCP1['mobility_notes'] ?? 'not_null', true)
);

// ADV5-CP-02: Double encryption protection: re-encrypting enc:v1:... string
$originalText = 'Инсулин 2 раза в день перед едой';
$enc1 = CryptoService::encrypt($originalText);
$enc2 = CryptoService::encrypt($enc1);
assertAdv(
    'ADV5-CP-02',
    'Double encryption prevented: re-encrypting enc:v1: returns identical string',
    $enc1 === $enc2 && str_starts_with($enc1, 'enc:v1:'),
    "Mismatch: enc1={$enc1}, enc2={$enc2}"
);

// ADV5-CP-03: Concurrent request creation and anonymizeUser() execution
$userCP3 = $harness->registerUser(1, 'Пожилой Активная Заявка');
$uidCP3 = (int)$userCP3['user']['id'];
$reqCP3 = $harness->createHelpRequest($uidCP3, 1, 'ADV5-CP-03 Активная заявка');
$reqCP3Id = (int)$reqCP3['data']['request_id'];

$blocked = false;
$privacy = new PrivacyService();
try {
    $privacy->anonymizeUser($uidCP3);
} catch (\DomainException $e) {
    $blocked = true;
} catch (\Throwable $e) {
    $blocked = false;
}
// Clean up request
$pdo->exec("DELETE FROM help_requests WHERE id = {$reqCP3Id}");
$pdo->exec("DELETE FROM users WHERE id = {$uidCP3}");
assertAdv(
    'ADV5-CP-03',
    'Anonymization blocked atomically when user has active tasks',
    $blocked,
    'Anonymization was not blocked for user with active task'
);

// ADV5-CP-04: Stateless JWT verification / user access after right-to-be-forgotten erasure
$uCP4 = $harness->registerUser(1, 'Пожилой Забвение');
$uidCP4 = (int)$uCP4['user']['id'];
$privacy->anonymizeUser($uidCP4);

$userInDb = User::find($uidCP4);
$isPseudonymized = str_starts_with($userInDb['password_hash'] ?? '', 'ANON_')
    && str_starts_with($userInDb['name'] ?? '', 'Анонимизированный пользователь');
assertAdv(
    'ADV5-CP-04',
    'User credentials invalidated and pseudonymized post-erasure',
    $isPseudonymized,
    "Name: {$userInDb['name']}, Hash: {$userInDb['password_hash']}"
);

// ADV5-CP-05: Anonymized volunteer accreditation revocation check
$volCP5 = $harness->registerUser(2, 'Волонтёр Для Забвения');
$vuidCP5 = (int)$volCP5['user']['id'];
$harness->apiCall('POST', "/api/coordinator/verify-volunteer/{$vuidCP5}");
$badgeCP5 = BadgeService::generateBadgeToken($vuidCP5, 'Волонтёр Для Забвения');

$preCheck = BadgeService::verifyVolunteerBadge($badgeCP5);
$preValid = ($preCheck['valid'] ?? false) === true;

$privacy->anonymizeUser($vuidCP5);

$vProf = VolunteerProfile::find($vuidCP5);
$postCheck = BadgeService::verifyVolunteerBadge($badgeCP5);
$postValid = ($postCheck['valid'] ?? false) === false;

assertAdv(
    'ADV5-CP-05',
    'Anonymized volunteer accreditation revoked immediately; badge invalidated',
    $preValid && $postValid && (int)($vProf['is_verified'] ?? 1) === 0 && ($vProf['verification_status'] ?? '') === 'REVOKED',
    "Pre: " . var_export($preValid, true) . ", Post: " . var_export($postValid, true) . ", Status: " . ($vProf['verification_status'] ?? '')
);

// ADV5-CP-06: Phone generator collision test for user IDs 1..500 and 10001..10500
$phones = [];
$hasCollision = false;
for ($i = 1; $i <= 500; $i++) {
    $p = PrivacyService::generateAnonymousPhone($i);
    if (isset($phones[$p])) {
        $hasCollision = true;
        break;
    }
    $phones[$p] = $i;
}
for ($i = 10001; $i <= 10500; $i++) {
    $p = PrivacyService::generateAnonymousPhone($i);
    if (isset($phones[$p])) {
        $hasCollision = true;
        break;
    }
    $phones[$p] = $i;
}
assertAdv(
    'ADV5-CP-06',
    'Phone generator produces 1000 unique placeholders across 1..500 and 10001..10500',
    !$hasCollision && count($phones) === 1000,
    "Collision={$hasCollision}, Count=" . count($phones)
);

// ADV5-CP-07: Data minimization: verify volunteer cannot see full phone once task is completed
$uSenior = $harness->registerUser(1, 'Бабушка Анна');
$seniorId = (int)$uSenior['user']['id'];
$reqCP7 = $harness->createHelpRequest($seniorId, 1, 'ADV5-CP-07 Минимизация ПД');
$reqCP7Id = (int)$reqCP7['data']['request_id'];
$secretCP7 = $reqCP7['data']['secret_code'];

$harness->apiCall('POST', "/api/requests/{$reqCP7Id}/accept", ['volunteer_id' => 2]);

$inProgressReq = HelpRequest::find($reqCP7Id);
$inProgressReq['elderly_phone'] = '+375291234567';
$canViewWhileActive = DataMaskingService::canViewUnmasked($inProgressReq, ['user_id' => 2, 'role_code' => 'volunteer']);

$harness->apiCall('POST', "/api/requests/{$reqCP7Id}/complete", [
    'secret_code' => $secretCP7,
    'latitude' => 53.9168,
    'longitude' => 27.5862
]);

$completedReq = HelpRequest::find($reqCP7Id);
$completedReq['elderly_phone'] = '+375291234567';
$canViewAfterComplete = DataMaskingService::canViewUnmasked($completedReq, ['user_id' => 2, 'role_code' => 'volunteer']);
$maskedReq = DataMaskingService::maskRequest($completedReq, ['user_id' => 2, 'role_code' => 'volunteer']);
$phoneMasked = str_contains($maskedReq['elderly_phone'] ?? '', '***');

assertAdv(
    'ADV5-CP-07',
    'Data minimization: volunteer unmasked access revokes upon task completion',
    $canViewWhileActive === true && $canViewAfterComplete === false && $phoneMasked,
    "Active: " . var_export($canViewWhileActive, true) . ", Completed: " . var_export($canViewAfterComplete, true) . ", Masked: " . ($maskedReq['elderly_phone'] ?? '')
);

// =========================================================================
// Category 2: Geofence & Requests Adversarial Tests (ADV5-GR-01 .. ADV5-GR-07)
// =========================================================================
echo "\n[Category 2] Geofence & Request Completion Boundaries (7 tests):\n";

// ADV5-GR-01: Request completion without latitude/longitude payload
$rGR1 = $harness->createHelpRequest(1, 1, 'ADV5-GR-01 Omitted Coords Test');
$idGR1 = (int)$rGR1['data']['request_id'];
$secGR1 = $rGR1['data']['secret_code'];
$harness->apiCall('POST', "/api/requests/{$idGR1}/accept", ['volunteer_id' => 2]);
$resGR1 = $harness->apiCall('POST', "/api/requests/{$idGR1}/complete", ['secret_code' => $secGR1]);
assertAdv(
    'ADV5-GR-01',
    'Omitted coordinates on complete returns HTTP 400 Bad Request',
    $resGR1['code'] === 400 && str_contains($resGR1['data']['error'] ?? '', 'GPS-координаты'),
    "HTTP {$resGR1['code']}, Error: " . ($resGR1['data']['error'] ?? '')
);

// ADV5-GR-02: Request completion with latitude: null, longitude: null
$resGR2 = $harness->apiCall('POST', "/api/requests/{$idGR1}/complete", [
    'secret_code' => $secGR1,
    'latitude' => null,
    'longitude' => null
]);
assertAdv(
    'ADV5-GR-02',
    'Completion with latitude: null, longitude: null returns HTTP 400',
    $resGR2['code'] === 400,
    "HTTP {$resGR2['code']}"
);

// ADV5-GR-03: Coordinate validation: latitude: 95.0, longitude: -200.0
$resGR3 = $harness->apiCall('POST', "/api/requests/{$idGR1}/complete", [
    'secret_code' => $secGR1,
    'latitude' => 95.0,
    'longitude' => -200.0
]);
assertAdv(
    'ADV5-GR-03',
    'Coordinates outside [-90..90, -180..180] rejected with HTTP 400',
    $resGR3['code'] === 400 && str_contains($resGR3['data']['error'] ?? '', 'Некорректные GPS-координаты'),
    "HTTP {$resGR3['code']}, Error: " . ($resGR3['data']['error'] ?? '')
);

// ADV5-GR-04: Exact geofence boundary step: 149.95m distance
$resGR4 = $harness->apiCall('POST', "/api/requests/{$idGR1}/complete", [
    'secret_code' => $secGR1,
    'latitude' => 53.9168 + 0.00134853,
    'longitude' => 27.5862
]);
assertAdv(
    'ADV5-GR-04',
    'Exact geofence boundary step 149.95m distance succeeds with HTTP 200',
    $resGR4['code'] === 200 && ($resGR4['data']['success'] ?? false) === true,
    "HTTP {$resGR4['code']}"
);

// ADV5-GR-05: Exact geofence boundary step: 150.00m distance
$rGR5 = $harness->createHelpRequest(1, 1, 'ADV5-GR-05 Exact 150m Test');
$idGR5 = (int)$rGR5['data']['request_id'];
$secGR5 = $rGR5['data']['secret_code'];
$harness->apiCall('POST', "/api/requests/{$idGR5}/accept", ['volunteer_id' => 2]);
$resGR5 = $harness->apiCall('POST', "/api/requests/{$idGR5}/complete", [
    'secret_code' => $secGR5,
    'latitude' => 53.9168 + 0.00134898,
    'longitude' => 27.5862
]);
assertAdv(
    'ADV5-GR-05',
    'Exact geofence boundary step 150.00m distance included (HTTP 200)',
    $resGR5['code'] === 200 && ($resGR5['data']['success'] ?? false) === true,
    "HTTP {$resGR5['code']}"
);

// ADV5-GR-06: Exact geofence boundary step: 150.05m / 150.10m distance
$rGR6 = $harness->createHelpRequest(1, 1, 'ADV5-GR-06 Out of Zone 150.05m Test');
$idGR6 = (int)$rGR6['data']['request_id'];
$secGR6 = $rGR6['data']['secret_code'];
$harness->apiCall('POST', "/api/requests/{$idGR6}/accept", ['volunteer_id' => 2]);
$resGR6 = $harness->apiCall('POST', "/api/requests/{$idGR6}/complete", [
    'secret_code' => $secGR6,
    'latitude' => 53.9168 + 0.00134943,
    'longitude' => 27.5862
]);
assertAdv(
    'ADV5-GR-06',
    'Exact geofence boundary step 150.05m rejected with HTTP 400 out of zone',
    $resGR6['code'] === 400 && str_contains($resGR6['data']['error'] ?? '', 'вне допустимой зоны'),
    "HTTP {$resGR6['code']}, Error: " . ($resGR6['data']['error'] ?? '')
);

// ADV5-GR-07: Brute-force lockout: 5 wrong attempts lock task; 6th attempt with valid code
$rGR7 = $harness->createHelpRequest(1, 1, 'ADV5-GR-07 Lockout Defense Test');
$idGR7 = (int)$rGR7['data']['request_id'];
$secGR7 = $rGR7['data']['secret_code'];
$harness->apiCall('POST', "/api/requests/{$idGR7}/accept", ['volunteer_id' => 2]);

for ($i = 1; $i <= 5; $i++) {
    $harness->apiCall('POST', "/api/requests/{$idGR7}/complete", [
        'secret_code' => "WrongAttempt_{$i}",
        'latitude' => 53.9168,
        'longitude' => 27.5862
    ]);
}

$resGR7 = $harness->apiCall('POST', "/api/requests/{$idGR7}/complete", [
    'secret_code' => $secGR7,
    'latitude' => 53.9168,
    'longitude' => 27.5862
]);

$reqGR7InDb = HelpRequest::find($idGR7);
assertAdv(
    'ADV5-GR-07',
    'Brute-force lockout: locked task permanently rejects 6th valid attempt',
    $resGR7['code'] === 400 && str_contains($resGR7['data']['error'] ?? '', 'заблокирована') && (int)$reqGR7InDb['failed_attempts'] >= 5,
    "HTTP {$resGR7['code']}, Status: " . ($reqGR7InDb['status'] ?? '')
);

// =========================================================================
// Category 3: Badge & Volunteers Adversarial Tests (ADV5-BV-01 .. ADV5-BV-05)
// =========================================================================
echo "\n[Category 3] Dynamic Badge & Identity Security (5 tests):\n";

// ADV5-BV-01: Single bit-flip tamper in dynamic badge HMAC-SHA256 signature
$tokBV1 = BadgeService::generateBadgeToken(2, 'Иван Волонтёр');
$partsBV1 = explode('.', $tokBV1);
$tamperedSig = $partsBV1[1];
$lastChar = substr($tamperedSig, -1);
$replacementChar = ($lastChar === 'A') ? 'B' : 'A';
$tamperedSig = substr($tamperedSig, 0, -1) . $replacementChar;
$tamperedToken = $partsBV1[0] . '.' . $tamperedSig;

$resBV1 = $harness->apiCall('GET', "/api/volunteers/badge/{$tamperedToken}");
assertAdv(
    'ADV5-BV-01',
    'Single bit-flip tamper in dynamic badge signature rejected with HTTP 400',
    $resBV1['code'] === 400 && ($resBV1['data']['valid'] ?? true) === false,
    "HTTP {$resBV1['code']}, Valid: " . var_export($resBV1['data']['valid'] ?? null, true)
);

// ADV5-BV-02: Dynamic badge expiration: token evaluated at exp + 1 second
$expiredPayload = [
    'vid' => 2,
    'user_id' => 2,
    'name' => 'Иван Волонтёр',
    'org' => 'ТЦСОН Советского района',
    'status' => 'VERIFIED',
    'iat' => time() - 3601,
    'exp' => time() - 1
];
$jsonBV2 = json_encode($expiredPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$encBV2 = rtrim(strtr(base64_encode($jsonBV2), '+/', '-_'), '=');
$sigBV2 = rtrim(strtr(base64_encode(hash_hmac('sha256', $encBV2, BadgeService::getSecret(), true)), '+/', '-_'), '=');
$expiredToken = $encBV2 . '.' . $sigBV2;

$resBV2 = $harness->apiCall('GET', "/api/volunteers/badge/{$expiredToken}");
assertAdv(
    'ADV5-BV-02',
    'Dynamic badge evaluated at exp + 1s rejected as expired (HTTP 400)',
    $resBV2['code'] === 400 && ($resBV2['data']['valid'] ?? true) === false,
    "HTTP {$resBV2['code']}"
);

// ADV5-BV-03: Dynamic badge clock skew: token with iat > now + 65 seconds
$skewedPayload = [
    'vid' => 2,
    'user_id' => 2,
    'name' => 'Иван Волонтёр',
    'org' => 'ТЦСОН Советского района',
    'status' => 'VERIFIED',
    'iat' => time() + 70,
    'exp' => time() + 3670
];
$jsonBV3 = json_encode($skewedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$encBV3 = rtrim(strtr(base64_encode($jsonBV3), '+/', '-_'), '=');
$sigBV3 = rtrim(strtr(base64_encode(hash_hmac('sha256', $encBV3, BadgeService::getSecret(), true)), '+/', '-_'), '=');
$skewedToken = $encBV3 . '.' . $sigBV3;

$resBV3 = $harness->apiCall('GET', "/api/volunteers/badge/{$skewedToken}");
assertAdv(
    'ADV5-BV-03',
    'Dynamic badge with future iat > now + 65s rejected (HTTP 400)',
    $resBV3['code'] === 400 && ($resBV3['data']['valid'] ?? true) === false,
    "HTTP {$resBV3['code']}"
);

// ADV5-BV-04: Instant revocation: coordinator rejects volunteer while active badge is unexpired
$volBV4 = $harness->registerUser(2, 'Волонтёр Для Отзыва');
$uidBV4 = (int)$volBV4['user']['id'];
$harness->apiCall('POST', "/api/coordinator/verify-volunteer/{$uidBV4}");
$tokenBV4 = BadgeService::generateBadgeToken($uidBV4, 'Волонтёр Для Отзыва');

$checkPreRevoke = $harness->apiCall('GET', "/api/volunteers/badge/{$tokenBV4}");
$validBefore = ($checkPreRevoke['data']['valid'] ?? false) === true;

$harness->apiCall('POST', "/api/coordinator/reject-volunteer/{$uidBV4}");

$checkPostRevoke = $harness->apiCall('GET', "/api/volunteers/badge/{$tokenBV4}");
$validAfter = ($checkPostRevoke['data']['valid'] ?? false) === false;

assertAdv(
    'ADV5-BV-04',
    'Instant revocation: rejected volunteer badge rejected before TTL expiry',
    $validBefore && $validAfter && $checkPostRevoke['code'] === 400,
    "Pre: " . var_export($validBefore, true) . ", Post: " . var_export($validAfter, true)
);

// ADV5-BV-05: SVG Badge XSS Injection: volunteer name <script>alert(1)</script><svg>
$xssName = '<script>alert(1)</script><svg ontoggle=alert(1)>';
$tokXss = BadgeService::generateBadgeToken(2, $xssName);
$svg = BadgeService::generateBadgeSvg(2, $tokXss);

$noRawScript = !str_contains($svg, '<script>');
$escapedScript = str_contains($svg, '&lt;script&gt;');
$xmlValid = simplexml_load_string($svg) !== false;

assertAdv(
    'ADV5-BV-05',
    'SVG Badge XSS injection escaped via htmlspecialchars() and well-formed XML',
    $noRawScript && $escapedScript && $xmlValid,
    "noRawScript: " . var_export($noRawScript, true) . ", escaped: " . var_export($escapedScript, true) . ", xmlValid: " . var_export($xmlValid, true)
);

// =========================================================================
// Category 4: Watchdog & SOS Adversarial Tests (ADV5-WS-01 .. ADV5-WS-03)
// =========================================================================
echo "\n[Category 4] Watchdog & Emergency SOS Resilience (3 tests):\n";

// ADV5-WS-01: EMERCOM 112 ticket generation entropy (1,000 tickets burst)
$tickets = [];
$allMatch = true;
for ($i = 0; $i < 1000; $i++) {
    $t = MchsEmergencyAdapter::generateTicketId();
    if (!preg_match('/^EMERCOM-112-BY-\d{4}-[A-Z0-9]{8}$/', $t)) {
        $allMatch = false;
    }
    $tickets[$t] = true;
}
assertAdv(
    'ADV5-WS-01',
    'EMERCOM 112 ticket burst generates 1,000 unique 32-bit entropy tickets',
    count($tickets) === 1000 && $allMatch,
    'Count: ' . count($tickets) . ', allMatch: ' . var_export($allMatch, true)
);

// ADV5-WS-02: Rapid SOS trigger burst (10 rapid requests from same senior)
$seniorToken = $harness->getElderlyToken();
$sosSuccessCount = 0;
for ($i = 0; $i < 10; $i++) {
    $resSOS = $harness->apiCall('POST', '/api/sos', [
        'latitude' => 53.9168,
        'longitude' => 27.5862,
        'reason' => "Экстренная тревога (Burst {$i})"
    ], ["Authorization: Bearer {$seniorToken}"]);
    if ($resSOS['code'] === 201 && ($resSOS['data']['success'] ?? false) === true) {
        $sosSuccessCount++;
    }
}
assertAdv(
    'ADV5-WS-02',
    'Rapid SOS trigger burst: all 10 requests safely processed without deadlock',
    $sosSuccessCount === 10,
    "Success count: {$sosSuccessCount} / 10"
);

// ADV5-WS-03: Regression verification of T2-SOS-07 non-existent SOS resolution
$coordToken = $harness->getCoordinatorToken();
$resWS3 = $harness->apiCall('POST', '/api/coordinator/sos-alerts/999999/resolve', null, ["Authorization: Bearer {$coordToken}"]);
assertAdv(
    'ADV5-WS-03',
    'Resolving non-existent SOS alert 999999 returns HTTP 404 without test skip',
    $resWS3['code'] === 404 && ($resWS3['data']['error'] ?? '') === 'Экстренное оповещение не найдено',
    "HTTP {$resWS3['code']}, Error: " . ($resWS3['data']['error'] ?? '')
);

echo "\n--------------------------------------------------------\n";
echo "TIER 5 ADVERSARIAL RESULTS: Total: {$total} | Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================\n";

exit($failed === 0 ? 0 : 1);
