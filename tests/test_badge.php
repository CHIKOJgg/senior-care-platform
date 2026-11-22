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
require_once __DIR__ . '/../app/Models/VolunteerProfile.php';
require_once __DIR__ . '/../app/Services/BadgeService.php';
require_once __DIR__ . '/../app/Services/AuthService.php';
require_once __DIR__ . '/../app/Services/Identity/IdentityProviderInterface.php';
require_once __DIR__ . '/../app/Services/Identity/MsiIdentityAdapter.php';
require_once __DIR__ . '/../app/Services/Identity/GosuslugiIdentityAdapter.php';
require_once __DIR__ . '/../app/Services/Identity/IdentityProviderFactory.php';
require_once __DIR__ . '/e2e_suite/TestHarness.php';

use App\Services\BadgeService;
use App\Services\Identity\IdentityProviderFactory;
use App\Services\Identity\MsiIdentityAdapter;
use App\Services\Identity\GosuslugiIdentityAdapter;
use App\Models\VolunteerProfile;
use App\Models\User;
use Tests\E2ESuite\TestHarness;

echo "========================================================\n";
echo "   MILESTONE 2: VOLUNTEER VERIFICATION & BADGE TESTS\n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assertBadge(string $name, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$name} - {$details}\n";
        $failed++;
    }
}

$harness = TestHarness::getInstance('http://127.0.0.1:8000');

// =========================================================================
// SUITE 1: BadgeService Token Cryptography & Lifecycle
// =========================================================================
echo "[SUITE 1] BadgeService Token Cryptography & Lifecycle:\n";

$token = BadgeService::generateBadgeToken(2, 'Алексей Сергеевич Смирнов', 'ТЦСОН Советского района г. Минска');
assertBadge('T1-01: Generates dynamic signed token with dot separator', !empty($token) && strpos($token, '.') !== false, "Token: {$token}");

$payload = BadgeService::verifyBadgeToken($token);
assertBadge('T1-02: Verifies valid token and decodes payload with vid, org, name, exp',
    is_array($payload) && ($payload['vid'] ?? 0) === 2 && !empty($payload['exp']) && !empty($payload['org']),
    'Failed to decode payload'
);

$ttl = (int)$payload['exp'] - (int)$payload['iat'];
assertBadge('T1-03: Token TTL is exactly 3600 seconds (1 hour)', $ttl === 3600, "TTL: {$ttl}");

$tampered = $token . 'tamper';
assertBadge('T1-04: Tampered token signature fails verification (returns null)', BadgeService::verifyBadgeToken($tampered) === null);

// Expired token test
$now = time();
$expiredPayload = [
    'vid' => 2,
    'user_id' => 2,
    'bid' => 'BY-MSK-SOV-2026-0002',
    'name' => 'Алексей С.',
    'org' => 'ТЦСОН',
    'status' => 'VERIFIED',
    'iat' => $now - 7200,
    'exp' => $now - 3600
];
$jsonExpired = json_encode($expiredPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$b64Expired = rtrim(strtr(base64_encode($jsonExpired), '+/', '-_'), '=');
$sigExpired = rtrim(strtr(base64_encode(hash_hmac('sha256', $b64Expired, BadgeService::getSecret(), true)), '+/', '-_'), '=');
$expiredToken = $b64Expired . '.' . $sigExpired;

assertBadge('T1-05: Expired token fails verification (returns null)', BadgeService::verifyBadgeToken($expiredToken) === null);

assertBadge('T1-06: Malformed arbitrary string rejected without exceptions', BadgeService::verifyBadgeToken('invalid_garbage_token') === null);

$token2 = BadgeService::generateBadgeToken(3, 'Дмитрий Новиков', 'ТЦСОН');
assertBadge('T1-07: Different volunteers receive distinct cryptographic tokens', $token !== $token2);

// =========================================================================
// SUITE 2: Zero-Emoji Vector SVG QR Code & Card Generation
// =========================================================================
echo "\n[SUITE 2] Zero-Emoji Vector SVG QR Code & Visual Badge Card:\n";

$qrSvg = BadgeService::generateBadgeQrSvg($token, ['size' => 240]);
assertBadge('T2-01: Generates valid standalone SVG element with viewBox',
    str_starts_with(trim($qrSvg), '<svg') && str_ends_with(trim($qrSvg), '</svg>') && str_contains($qrSvg, 'viewBox="0 0'),
    'Invalid SVG format'
);

$hasEmoji = (bool)preg_match('/[\x{1F300}-\x{1F64F}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', $qrSvg);
assertBadge('T2-02: QR SVG complies with strict Zero-Emoji policy', !$hasEmoji, 'Emoji detected in SVG output');

$cardSvg = BadgeService::generateBadgeSvg(2, $token);
assertBadge('T2-03: Visual badge card renders 420x640 vector SVG template',
    str_contains($cardSvg, 'width="420"') && str_contains($cardSvg, 'height="640"'),
    'Card SVG missing dimensions'
);

$cardXml = simplexml_load_string($cardSvg);
assertBadge('T2-04: Visual badge card is 100% well-formed valid XML', $cardXml !== false, 'XML parsing failed');

$cardHasEmoji = (bool)preg_match('/[\x{1F300}-\x{1F64F}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', $cardSvg);
assertBadge('T2-05: Visual badge card contains zero emojis (100% inline SVG icons)', !$cardHasEmoji, 'Emoji in card SVG');

// =========================================================================
// SUITE 3: Public Badge Verification API (GET /api/volunteers/badge/{token})
// =========================================================================
echo "\n[SUITE 3] Public Badge Verification API & Privacy Protection (Law 99-Z):\n";

$publicRes = $harness->apiCall('GET', "/api/volunteers/badge/{$token}");
assertBadge('T3-01: Public verification accessible without Bearer Authorization header (200 OK)',
    $publicRes['code'] === 200 && ($publicRes['data']['valid'] ?? false) === true,
    "HTTP {$publicRes['code']}"
);

$volData = $publicRes['data']['volunteer'] ?? [];
$hasSensitivePii = isset($volData['passport']) || isset($volData['passport_masked']) ||
                   isset($volData['phone']) || isset($volData['address']) ||
                   isset($volData['password_hash']) || isset($volData['email']);
assertBadge('T3-02: Public response strictly suppresses sensitive PII (Law 99-Z)',
    !$hasSensitivePii && !empty($volData['first_name']),
    'Sensitive PII leaked in public endpoint'
);

assertBadge('T3-03: Public response extracts safe Slavic name (first_name, patronymic, last_initial)',
    !empty($volData['first_name']) && !empty($volData['last_initial']),
    'Failed to format safe public name'
);

$tamperedRes = $harness->apiCall('GET', "/api/volunteers/badge/{$tampered}");
assertBadge('T3-04: Tampered token returns HTTP 400 Bad Request',
    $tamperedRes['code'] === 400 && ($tamperedRes['data']['valid'] ?? true) === false,
    "HTTP {$tamperedRes['code']}"
);

$expiredRes = $harness->apiCall('GET', "/api/volunteers/badge/{$expiredToken}");
assertBadge('T3-05: Expired token returns HTTP 400 with expiration error',
    $expiredRes['code'] === 400 && ($expiredRes['data']['valid'] ?? true) === false,
    "HTTP {$expiredRes['code']}"
);

// Real-time revocation check
$coordToken = $harness->getCoordinatorToken();
$regRevoked = $harness->registerUser(2, 'Василий На Отзыв');
$revokedId = (int)$regRevoked['user']['id'];
$revokedToken = BadgeService::generateBadgeToken($revokedId, 'Василий О.', 'ТЦСОН');

// Coordinator rejects volunteer
$harness->apiCall('POST', "/api/coordinator/volunteers/{$revokedId}/reject", ['reason' => 'Тестовый отзыв'], ["Authorization: Bearer {$coordToken}"]);
$checkRevoked = $harness->apiCall('GET', "/api/volunteers/badge/{$revokedToken}");
assertBadge('T3-06: Instant revocation check: suspended volunteer badge rejected even with valid HMAC',
    $checkRevoked['code'] === 400 && ($checkRevoked['data']['valid'] ?? true) === false,
    "HTTP {$checkRevoked['code']}"
);

// Non-existent volunteer token check
$fakeToken = BadgeService::generateBadgeToken(99999, 'Несуществующий В.', 'ТЦСОН');
$fakeRes = $harness->apiCall('GET', "/api/volunteers/badge/{$fakeToken}");
assertBadge('T3-07: Non-existent volunteer profile rejected with 400/404',
    in_array($fakeRes['code'], [400, 404]) && ($fakeRes['data']['valid'] ?? true) === false,
    "HTTP {$fakeRes['code']}"
);

// Direct SVG badge rendering check
$svgRes = $harness->apiCall('GET', "/api/volunteers/badge/{$token}/svg");
assertBadge('T3-08: Direct badge SVG route GET /api/volunteers/badge/{token}/svg returns valid SVG',
    $svgRes['code'] === 200 && str_contains($svgRes['raw'] ?? '', '<svg'),
    "HTTP {$svgRes['code']}"
);

// =========================================================================
// SUITE 4: Volunteer Self-Service API (GET /api/volunteers/my-badge)
// =========================================================================
echo "\n[SUITE 4] Volunteer Self-Service Dynamic Badge API (RBAC Guard):\n";

$guestRes = $harness->apiCall('GET', '/api/volunteers/my-badge');
assertBadge('T4-01: Unauthenticated guest blocked with HTTP 401 Unauthorized', $guestRes['code'] === 401, "HTTP {$guestRes['code']}");

$elderlyToken = $harness->getElderlyToken();
$elderlyRes = $harness->apiCall('GET', '/api/volunteers/my-badge', null, ["Authorization: Bearer {$elderlyToken}"]);
assertBadge('T4-02: Elderly citizen blocked from volunteer badge with HTTP 403 Forbidden', $elderlyRes['code'] === 403, "HTTP {$elderlyRes['code']}");

$unverifiedReg = $harness->registerUser(2, 'Новый Неверифицированный Волонтёр');
$unverifiedToken = $unverifiedReg['token'];
$unverifiedRes = $harness->apiCall('GET', '/api/volunteers/my-badge', null, ["Authorization: Bearer {$unverifiedToken}"]);
assertBadge('T4-03: Unverified volunteer blocked with HTTP 403 Forbidden', $unverifiedRes['code'] === 403, "HTTP {$unverifiedRes['code']}");

$suspendedRes = $harness->apiCall('GET', '/api/volunteers/my-badge', null, ["Authorization: Bearer {$regRevoked['token']}"]);
assertBadge('T4-04: Suspended volunteer blocked with HTTP 403 Forbidden', $suspendedRes['code'] === 403, "HTTP {$suspendedRes['code']}");

$volToken = $harness->getVolunteerToken();
$myBadgeRes = $harness->apiCall('GET', '/api/volunteers/my-badge', null, ["Authorization: Bearer {$volToken}"]);
assertBadge('T4-05: Verified volunteer successfully retrieves dynamic badge and SVG QR',
    $myBadgeRes['code'] === 200 && !empty($myBadgeRes['data']['badge_token']) && !empty($myBadgeRes['data']['qr_svg']),
    "HTTP {$myBadgeRes['code']}"
);

// =========================================================================
// SUITE 5: Coordinator Volunteer Moderation & Dual-Route Compatibility
// =========================================================================
echo "\n[SUITE 5] Coordinator Volunteer Moderation & Dual-Route Backward Compatibility:\n";

$forbiddenQueue = $harness->apiCall('GET', '/api/coordinator/volunteers/pending', null, ["Authorization: Bearer {$volToken}"]);
assertBadge('T5-01: Non-coordinator user blocked from moderation queue with HTTP 403 Forbidden',
    $forbiddenQueue['code'] === 403,
    "HTTP {$forbiddenQueue['code']}"
);

$pendingCanonical = $harness->apiCall('GET', '/api/coordinator/volunteers/pending', null, ["Authorization: Bearer {$coordToken}"]);
assertBadge('T5-02: Coordinator can retrieve pending queue via canonical RESTful route',
    $pendingCanonical['code'] === 200 && is_array($pendingCanonical['data']['data'] ?? null),
    "HTTP {$pendingCanonical['code']}"
);

$pendingLegacy = $harness->apiCall('GET', '/api/coordinator/pending-volunteers', null, ["Authorization: Bearer {$coordToken}"]);
assertBadge('T5-03: Backward compatibility alias /api/coordinator/pending-volunteers returns identical result',
    $pendingLegacy['code'] === 200 && count($pendingLegacy['data']['data'] ?? []) === count($pendingCanonical['data']['data'] ?? []),
    "HTTP {$pendingLegacy['code']}"
);

// Register fresh volunteer for verification test
$cand = $harness->registerUser(2, 'Кандидат На Одобрение');
$candId = (int)$cand['user']['id'];

$verifyRes = $harness->apiCall('POST', "/api/coordinator/volunteers/{$candId}/verify", null, ["Authorization: Bearer {$coordToken}"]);
assertBadge('T5-04: Coordinator verifies candidate via canonical RESTful route',
    $verifyRes['code'] === 200 && ($verifyRes['data']['success'] ?? false) === true,
    "HTTP {$verifyRes['code']}"
);

// Reject with custom reason
$cand2 = $harness->registerUser(2, 'Кандидат На Отклонение');
$cand2Id = (int)$cand2['user']['id'];
$rejectRes = $harness->apiCall('POST', "/api/coordinator/volunteers/{$cand2Id}/reject", [
    'reason' => 'Несоответствие паспортных данных'
], ["Authorization: Bearer {$coordToken}"]);
assertBadge('T5-05: Coordinator rejects candidate with audit reason',
    $rejectRes['code'] === 200 && ($rejectRes['data']['verification_status'] ?? '') === 'REJECTED' && str_contains($rejectRes['data']['reason'] ?? '', 'Несоответствие'),
    "HTTP {$rejectRes['code']}"
);

$nonExistentVerify = $harness->apiCall('POST', '/api/coordinator/volunteers/99999/verify', null, ["Authorization: Bearer {$coordToken}"]);
assertBadge('T5-06: Moderation of non-existent profile returns HTTP 404 Not Found',
    $nonExistentVerify['code'] === 404,
    "HTTP {$nonExistentVerify['code']}"
);

$cand3 = $harness->registerUser(2, 'Кандидат Алиас');
$cand3Id = (int)$cand3['user']['id'];
$aliasVerifyRes = $harness->apiCall('POST', "/api/coordinator/verify-volunteer/{$cand3Id}", null, ["Authorization: Bearer {$coordToken}"]);
assertBadge('T5-07: Backward compatibility alias /api/coordinator/verify-volunteer/{id} succeeds',
    $aliasVerifyRes['code'] === 200 && ($aliasVerifyRes['data']['success'] ?? false) === true,
    "HTTP {$aliasVerifyRes['code']}"
);

// =========================================================================
// SUITE 6: Modular Identity Adapters (MSI Belarus & Gosuslugi Mock)
// =========================================================================
echo "\n[SUITE 6] Modular Identity Adapters (MSI Belarus & Gosuslugi ESIA Mock Drivers):\n";

$msi = IdentityProviderFactory::get('msi');
assertBadge('T6-01: Factory creates MsiIdentityAdapter implementing IdentityProviderInterface',
    $msi instanceof MsiIdentityAdapter && $msi->getProviderName() === 'msi'
);

$msiValid = $msi->verifyIdentity('3010190A001PB5');
assertBadge('T6-02: MSI adapter verifies valid 14-char personal ID and masks per Law 99-Z',
    ($msiValid['verified'] ?? false) === true && ($msiValid['personal_number_masked'] ?? '') === '3010190A***PB5',
    'MSI verification failed'
);

$msiInvalid = $msi->verifyIdentity('12345_INVALID');
assertBadge('T6-03: MSI adapter rejects invalid personal ID format',
    ($msiInvalid['verified'] ?? true) === false && ($msiInvalid['error'] ?? '') === 'INVALID_FORMAT'
);

$msiBlocked = $msi->verifyIdentity('3010170A999PB0');
assertBadge('T6-04: MSI adapter detects security blocked citizen (MSI_SECURITY_ALERT)',
    ($msiBlocked['verified'] ?? true) === false && ($msiBlocked['error'] ?? '') === 'MSI_SECURITY_ALERT'
);

$esia = IdentityProviderFactory::get('gosuslugi');
assertBadge('T6-05: Factory creates GosuslugiIdentityAdapter implementing IdentityProviderInterface',
    $esia instanceof GosuslugiIdentityAdapter && $esia->getProviderName() === 'gosuslugi'
);

$esiaValid = $esia->verifyIdentity('112-233-445 95');
assertBadge('T6-06: Gosuslugi adapter validates 11-digit SNILS checksum and masks output',
    ($esiaValid['verified'] ?? false) === true && ($esiaValid['personal_number_masked'] ?? '') === '***-***-445 95',
    'Gosuslugi verification failed'
);

// Endpoints for volunteer self-verification via external provider
$selfVerifyRes = $harness->apiCall('POST', '/api/volunteers/verify-identity', [
    'provider' => 'msi',
    'personal_number' => '3010190A001PB5'
], ["Authorization: Bearer {$cand['token']}"]);
assertBadge('T6-07: Volunteer self-verification endpoint POST /api/volunteers/verify-identity succeeds',
    $selfVerifyRes['code'] === 200 && ($selfVerifyRes['data']['success'] ?? false) === true,
    "HTTP {$selfVerifyRes['code']}"
);

// Summary
echo "\n--------------------------------------------------------\n";
echo "TEST RESULTS: Total: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
