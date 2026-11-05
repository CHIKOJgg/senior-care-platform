<?php

declare(strict_types=1);

/**
 * Challenger M5-2: Independent Empirical Stress Harness
 * 
 * Adversarially challenges:
 * 1. Cryptographic tampering (corrupted mobility_notes ciphertext, double-encryption prevention)
 * 2. Dynamic QR badge HMAC tampering (bit-flips, exp+1s, future iat)
 * 3. Anonymization: volunteer accreditation instant revocation (badge valid: false)
 * 4. EMERCOM ticket burst: 1,000 tickets, verify 0 collisions + boundary validation
 * 5. SOS burst: 10 concurrent requests (curl_multi & parallel processes), verify no SQLite deadlocks
 */

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
require_once __DIR__ . '/../app/Services/BadgeService.php';
require_once __DIR__ . '/../app/Services/Emergency/EmergencyAlertAdapterInterface.php';
require_once __DIR__ . '/../app/Services/Emergency/MchsEmergencyAdapter.php';
require_once __DIR__ . '/../app/Services/AuthService.php';
require_once __DIR__ . '/e2e_suite/TestHarness.php';

use App\Core\Database;
use App\Models\User;
use App\Models\ElderlyProfile;
use App\Models\VolunteerProfile;
use App\Models\SosAlert;
use App\Services\CryptoService;
use App\Services\PrivacyService;
use App\Services\BadgeService;
use App\Services\Emergency\MchsEmergencyAdapter;
use App\Services\AuthService;
use Tests\E2ESuite\TestHarness;

$harness = TestHarness::getInstance();
$passed = 0;
$failed = 0;
$total = 0;

function assertStress(string $id, string $title, bool $condition, string $details = ''): bool
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
echo "   CHALLENGER M5-2: INDEPENDENT EMPIRICAL STRESS HARNESS\n";
echo "========================================================\n\n";

$pdo = Database::getConnection();

// =========================================================================
// VECTOR 1: Cryptographic Tampering & Double-Encryption Prevention
// =========================================================================
echo "[Vector 1] Cryptographic Tampering & Double-Encryption:\n";

// 1.1: Direct CryptoService::decrypt() on corrupted payloads
$validPlain = 'Пациент перенес инсульт, требуется контроль давления';
$validCipher = CryptoService::encrypt($validPlain);
$rawPayload = base64_decode(substr($validCipher, strlen('enc:v1:')));

// Corrupt tag (bytes 12..27)
$corruptedTagBytes = $rawPayload;
$corruptedTagBytes[15] = chr(ord($corruptedTagBytes[15]) ^ 0x01);
$corruptedTagPayload = 'enc:v1:' . base64_encode($corruptedTagBytes);

// Corrupt ciphertext (bytes 28..)
$corruptedCipherBytes = $rawPayload;
$corruptedCipherBytes[29] = chr(ord($corruptedCipherBytes[29]) ^ 0x01);
$corruptedCipherPayload = 'enc:v1:' . base64_encode($corruptedCipherBytes);

$corruptedPayloads = [
    'non_base64' => 'enc:v1:***NOT_BASE64***',
    'truncated' => 'enc:v1:' . base64_encode('short'),
    'corrupted_tag' => $corruptedTagPayload,
    'corrupted_ciphertext' => $corruptedCipherPayload
];

$allDirectThrew = true;
foreach ($corruptedPayloads as $k => $payload) {
    try {
        CryptoService::decrypt($payload);
        $allDirectThrew = false;
        echo "    Failed to throw on {$k}\n";
    } catch (\RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'DecryptionException')) {
            $allDirectThrew = false;
        }
    }
}

assertStress(
    'CHAL-CRYPTO-01',
    'Direct CryptoService::decrypt on 4 tampered payloads strictly throws DecryptionException',
    $allDirectThrew
);

// 1.2: Database record with corrupted ciphertext reads gracefully via Model
$regRes1 = $harness->registerUser(1, 'Стресс Пожилой 1');
$uidSenior1 = (int)$regRes1['user']['id'];
$tokenSenior1 = $regRes1['token'];

// Inject tampered ciphertext into DB
$stmt = $pdo->prepare("UPDATE elderly_profiles SET mobility_notes = :notes WHERE user_id = :id");
$stmt->execute(['notes' => $corruptedTagPayload, 'id' => $uidSenior1]);

$loadedProfile = ElderlyProfile::find($uidSenior1);
assertStress(
    'CHAL-CRYPTO-02',
    'ElderlyProfile::find handles tampered ciphertext gracefully without fatal crash (returns null)',
    $loadedProfile !== null && $loadedProfile['mobility_notes'] === null
);

// 1.3: API endpoint /api/auth/me works without 500 crash when mobility_notes is corrupted
$apiMeRes = $harness->apiCall('GET', '/api/auth/me', null, ["Authorization: Bearer {$tokenSenior1}"]);
$hasProfile = isset($apiMeRes['data']['user']['profile']) && is_array($apiMeRes['data']['user']['profile']);
$profileData = $hasProfile ? $apiMeRes['data']['user']['profile'] : [];
$notesKeyExists = array_key_exists('mobility_notes', $profileData);
$notesIsNull = $notesKeyExists && $profileData['mobility_notes'] === null;

assertStress(
    'CHAL-CRYPTO-03',
    'GET /api/auth/me returns HTTP 200 and mobility_notes = null for tampered database record',
    $apiMeRes['code'] === 200 && $notesIsNull,
    'Code=' . $apiMeRes['code'] . ', Notes=' . var_export($profileData['mobility_notes'] ?? 'undefined', true)
);

// 1.4: Double encryption prevention
$encOnce = CryptoService::encrypt($validPlain);
$encTwice = CryptoService::encrypt($encOnce);
$encThrice = CryptoService::encrypt($encTwice);

assertStress(
    'CHAL-CRYPTO-04',
    'CryptoService::encrypt idempotency: 2nd and 3rd calls return identical single-layer ciphertext',
    $encOnce === $encTwice && $encTwice === $encThrice && substr_count($encThrice, 'enc:v1:') === 1
);

// 1.5: Edge inputs: null, empty string, legacy unencrypted
$nullEnc = CryptoService::encrypt(null);
$emptyEnc = CryptoService::encrypt('');
$legacyDec = CryptoService::decrypt('Обычный текст без шифрования');

assertStress(
    'CHAL-CRYPTO-05',
    'CryptoService handles null, empty string and legacy unencrypted backward compatibility',
    $nullEnc === null && $emptyEnc === '' && $legacyDec === 'Обычный текст без шифрования'
);

// =========================================================================
// VECTOR 2: Dynamic QR Badge HMAC Tampering & Boundaries
// =========================================================================
echo "\n[Vector 2] Dynamic QR Badge HMAC Tampering & Expiration Boundaries:\n";

// Register and verify volunteer
$regVol2 = $harness->registerUser(2, 'Смирнов Дмитрий Иванович');
$uidVol2 = (int)$regVol2['user']['id'];
$harness->apiCall('POST', "/api/coordinator/verify-volunteer/{$uidVol2}");

$validToken = BadgeService::generateBadgeToken($uidVol2, 'Смирнов Дмитрий Иванович');
$parts = explode('.', $validToken);
$b64Payload = $parts[0];
$b64Sig = $parts[1];

// 2.1: Bit-flips across signature positions (first char, middle char, last char)
$tamperedSigStart = chr(ord($b64Sig[0]) ^ 0x01) . substr($b64Sig, 1);
$tamperedSigMid = substr($b64Sig, 0, (int)(strlen($b64Sig) / 2)) . chr(ord($b64Sig[(int)(strlen($b64Sig) / 2)]) ^ 0x01) . substr($b64Sig, (int)(strlen($b64Sig) / 2) + 1);
$tamperedSigEnd = substr($b64Sig, 0, -1) . chr(ord(substr($b64Sig, -1)) ^ 0x01);

$resSigStart = $harness->apiCall('GET', "/api/volunteers/badge/{$b64Payload}.{$tamperedSigStart}");
$resSigMid = $harness->apiCall('GET', "/api/volunteers/badge/{$b64Payload}.{$tamperedSigMid}");
$resSigEnd = $harness->apiCall('GET', "/api/volunteers/badge/{$b64Payload}.{$tamperedSigEnd}");

$allBitFlipsRejected = ($resSigStart['code'] === 400 && ($resSigStart['data']['valid'] ?? true) === false)
    && ($resSigMid['code'] === 400 && ($resSigMid['data']['valid'] ?? true) === false)
    && ($resSigEnd['code'] === 400 && ($resSigEnd['data']['valid'] ?? true) === false);

assertStress(
    'CHAL-BADGE-01',
    'HMAC signature bit-flips at start, middle, and end positions all rejected with HTTP 400',
    $allBitFlipsRejected
);

// 2.2: Payload tampering with untouched signature
$rawJsonPayload = base64_decode(strtr($b64Payload, '-_', '+/'));
$decodedPayload = json_decode($rawJsonPayload, true);
$decodedPayload['name'] = 'Злоумышленник Хакер';
$tamperedJson = json_encode($decodedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$tamperedB64Payload = rtrim(strtr(base64_encode($tamperedJson), '+/', '-_'), '=');
$tamperedPayloadToken = $tamperedB64Payload . '.' . $b64Sig;

$resPayloadTamper = $harness->apiCall('GET', "/api/volunteers/badge/{$tamperedPayloadToken}");
assertStress(
    'CHAL-BADGE-02',
    'Payload tampering (name substitution) rejected by HMAC signature verification (HTTP 400)',
    $resPayloadTamper['code'] === 400 && ($resPayloadTamper['data']['valid'] ?? true) === false
);

// 2.3: Expiration boundary: exp - 1s (expired), exp (exact edge), exp + 10s (valid)
$secret = BadgeService::getSecret();

// Expired by 1 second: exp = time() - 1
$payloadExp1 = $decodedPayload;
$payloadExp1['iat'] = time() - 3601;
$payloadExp1['exp'] = time() - 1;
$jsonExp1 = json_encode($payloadExp1, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$b64Exp1 = rtrim(strtr(base64_encode($jsonExp1), '+/', '-_'), '=');
$sigExp1 = rtrim(strtr(base64_encode(hash_hmac('sha256', $b64Exp1, $secret, true)), '+/', '-_'), '=');
$resExp1 = $harness->apiCall('GET', "/api/volunteers/badge/{$b64Exp1}.{$sigExp1}");

// Unexpired: exp = time() + 30
$payloadValid = $decodedPayload;
$payloadValid['iat'] = time() - 10;
$payloadValid['exp'] = time() + 30;
$jsonValid = json_encode($payloadValid, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$b64Valid = rtrim(strtr(base64_encode($jsonValid), '+/', '-_'), '=');
$sigValid = rtrim(strtr(base64_encode(hash_hmac('sha256', $b64Valid, $secret, true)), '+/', '-_'), '=');
$resValid = $harness->apiCall('GET', "/api/volunteers/badge/{$b64Valid}.{$sigValid}");

assertStress(
    'CHAL-BADGE-03',
    'Expiration boundary: exp - 1s rejected (HTTP 400), exp + 30s accepted (HTTP 200)',
    $resExp1['code'] === 400 && ($resExp1['data']['valid'] ?? true) === false
    && $resValid['code'] === 200 && ($resValid['data']['valid'] ?? false) === true
);

// 2.4: Future iat clock skew testing: iat = now + 50s (tolerated) vs now + 65s (rejected)
// iat + 50s
$payloadSkew50 = $decodedPayload;
$payloadSkew50['iat'] = time() + 50;
$payloadSkew50['exp'] = time() + 3650;
$jsonSkew50 = json_encode($payloadSkew50, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$b64Skew50 = rtrim(strtr(base64_encode($jsonSkew50), '+/', '-_'), '=');
$sigSkew50 = rtrim(strtr(base64_encode(hash_hmac('sha256', $b64Skew50, $secret, true)), '+/', '-_'), '=');
$resSkew50 = $harness->apiCall('GET', "/api/volunteers/badge/{$b64Skew50}.{$sigSkew50}");

// iat + 65s (beyond 60s skew limit)
$payloadSkew65 = $decodedPayload;
$payloadSkew65['iat'] = time() + 65;
$payloadSkew65['exp'] = time() + 3665;
$jsonSkew65 = json_encode($payloadSkew65, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$b64Skew65 = rtrim(strtr(base64_encode($jsonSkew65), '+/', '-_'), '=');
$sigSkew65 = rtrim(strtr(base64_encode(hash_hmac('sha256', $b64Skew65, $secret, true)), '+/', '-_'), '=');
$resSkew65 = $harness->apiCall('GET', "/api/volunteers/badge/{$b64Skew65}.{$sigSkew65}");

assertStress(
    'CHAL-BADGE-04',
    'Future iat boundary: clock skew 50s accepted, 65s rejected with HTTP 400',
    $resSkew50['code'] === 200 && ($resSkew50['data']['valid'] ?? false) === true
    && $resSkew65['code'] === 400 && ($resSkew65['data']['valid'] ?? true) === false
);

// =========================================================================
// VECTOR 3: Anonymization & Instant Volunteer Accreditation Revocation
// =========================================================================
echo "\n[Vector 3] Volunteer Accreditation Instant Revocation on Anonymization:\n";

// Register volunteer 3, approve accreditation, generate dynamic badge
$regVol3 = $harness->registerUser(2, 'Кузнецов Сергей Петрович');
$uidVol3 = (int)$regVol3['user']['id'];
$tokenVol3 = $regVol3['token'];
$harness->apiCall('POST', "/api/coordinator/verify-volunteer/{$uidVol3}");

$activeBadgeToken3 = BadgeService::generateBadgeToken($uidVol3, 'Кузнецов Сергей Петрович');

// Confirm badge is valid initially
$checkBefore = $harness->apiCall('GET', "/api/volunteers/badge/{$activeBadgeToken3}");
$validBefore = ($checkBefore['code'] === 200 && ($checkBefore['data']['valid'] ?? false) === true);

// Exercise Right to be Forgotten via API
$anonRes = $harness->apiCall('POST', '/api/privacy/anonymize', ['confirm' => true], ["Authorization: Bearer {$tokenVol3}"]);
$anonOk = ($anonRes['code'] === 200 && ($anonRes['data']['success'] ?? false) === true);

// Re-verify the active badge token (cryptographically valid, TTL not expired)
$checkAfter = $harness->apiCall('GET', "/api/volunteers/badge/{$activeBadgeToken3}");
$validAfter = ($checkAfter['data']['valid'] ?? true) === false;

// Direct DB verification
$vProf3 = VolunteerProfile::find($uidVol3);
$isRevokedInDb = ((int)($vProf3['is_verified'] ?? 1) === 0)
    && (($vProf3['verification_status'] ?? '') === 'REVOKED')
    && empty($vProf3['badge_token']);

assertStress(
    'CHAL-ANON-01',
    'Instant revocation: anonymized volunteer active badge immediately fails validation (valid: false, HTTP 400)',
    $validBefore && $anonOk && $validAfter && $checkAfter['code'] === 400 && $isRevokedInDb
);

// Also test coordinator rejection instant revocation
$regVol4 = $harness->registerUser(2, 'Васильев Олег Игоревич');
$uidVol4 = (int)$regVol4['user']['id'];
$harness->apiCall('POST', "/api/coordinator/verify-volunteer/{$uidVol4}");
$badgeToken4 = BadgeService::generateBadgeToken($uidVol4, 'Васильев Олег Игоревич');
$check4Pre = $harness->apiCall('GET', "/api/volunteers/badge/{$badgeToken4}");

// Coordinator rejects
$harness->apiCall('POST', "/api/coordinator/reject-volunteer/{$uidVol4}");
$check4Post = $harness->apiCall('GET', "/api/volunteers/badge/{$badgeToken4}");

assertStress(
    'CHAL-ANON-02',
    'Instant revocation: coordinator-rejected volunteer badge immediately invalidated before TTL expiry',
    ($check4Pre['data']['valid'] ?? false) === true
    && ($check4Post['data']['valid'] ?? true) === false
    && $check4Post['code'] === 400
);

// =========================================================================
// VECTOR 4: EMERCOM Ticket Burst & Collision Stress
// =========================================================================
echo "\n[Vector 4] EMERCOM Ticket Burst & Collision Stress:\n";

// Generate 1,000 tickets
$emercomTickets = [];
$regexPattern = '/^EMERCOM-112-BY-\d{4}-[A-Z0-9]{8}$/';
$allValidPattern = true;

for ($i = 0; $i < 1000; $i++) {
    $tid = MchsEmergencyAdapter::generateTicketId();
    if (!preg_match($regexPattern, $tid)) {
        $allValidPattern = false;
    }
    $emercomTickets[$tid] = true;
}

$count1k = count($emercomTickets);
assertStress(
    'CHAL-EMERCOM-01',
    'EMERCOM burst: 1,000 tickets generated with 0 collisions and strict regex format',
    $count1k === 1000 && $allValidPattern,
    "Count={$count1k}, PatternValid=" . var_export($allValidPattern, true)
);

// Extended burst: another 2,000 tickets (total 3,000 unique)
for ($i = 0; $i < 2000; $i++) {
    $tid = MchsEmergencyAdapter::generateTicketId();
    $emercomTickets[$tid] = true;
}
$count3k = count($emercomTickets);
assertStress(
    'CHAL-EMERCOM-02',
    'Extended burst: total 3,000 tickets generated across bursts with exactly 0 collisions',
    $count3k === 3000,
    "Count={$count3k} / 3000"
);

// Structural validation of verifyTicketId
$adapter = new MchsEmergencyAdapter();
$validSampleTicket = array_key_first($emercomTickets);
$v1 = MchsEmergencyAdapter::verifyTicketId($validSampleTicket);
$v2 = MchsEmergencyAdapter::verifyTicketId('EMERCOM-112-BY-2026-lowerhex');
$v3 = MchsEmergencyAdapter::verifyTicketId('EMERCOM-911-BY-2026-ABCD1234');
$v4 = MchsEmergencyAdapter::verifyTicketId('');

assertStress(
    'CHAL-EMERCOM-03',
    'Ticket structure verification: valid ticket passes, malformed tickets rejected',
    $v1 === true && $v2 === false && $v3 === false && $v4 === false
);

// =========================================================================
// VECTOR 5: SOS Burst & Concurrency Resilience
// =========================================================================
echo "\n[Vector 5] SOS Burst & Concurrency Resilience:\n";

// Register elderly citizen for SOS test
$regSeniorSOS = $harness->registerUser(1, 'Бабушка Зинаида');
$uidSeniorSOS = (int)$regSeniorSOS['user']['id'];
$tokenSeniorSOS = $regSeniorSOS['token'];

// Part A: 10 parallel HTTP requests via curl_multi
$mh = curl_multi_init();
$curls = [];
$sosUrl = $harness->getBaseUrl() . '/api/sos';

for ($i = 0; $i < 10; $i++) {
    $ch = curl_init($sosUrl);
    $payload = json_encode([
        'latitude' => 53.9168 + ($i * 0.0001),
        'longitude' => 27.5862 + ($i * 0.0001),
        'reason' => "Параллельный вызов SOS #{$i}"
    ], JSON_UNESCAPED_UNICODE);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        "Authorization: Bearer {$tokenSeniorSOS}"
    ]);

    curl_multi_add_handle($mh, $ch);
    $curls[$i] = $ch;
}

$running = null;
do {
    curl_multi_exec($mh, $running);
    if ($running > 0) {
        curl_multi_select($mh, 0.05);
    }
} while ($running > 0);

$http201Count = 0;
$createdSosIds = [];

foreach ($curls as $i => $ch) {
    $resp = curl_multi_getcontent($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $data = json_decode((string)$resp, true);

    if ($code === 201 && ($data['success'] ?? false) === true && !empty($data['sos_id'])) {
        $http201Count++;
        $createdSosIds[] = (int)$data['sos_id'];
    }
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);

assertStress(
    'CHAL-SOS-01',
    'HTTP curl_multi burst: 10 parallel SOS calls all return HTTP 201 with unique sos_ids',
    $http201Count === 10 && count(array_unique($createdSosIds)) === 10,
    "HTTP 201 count: {$http201Count}/10, Unique IDs: " . count(array_unique($createdSosIds))
);

// Part B: Direct multiprocess concurrency stress on SQLite
// Launch 10 independent background CLI processes simultaneously inserting into database
$scriptPath = __DIR__ . '/_tmp_sos_worker.php';
$workerCode = <<<'PHP'
<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Model.php';
require_once __DIR__ . '/../app/Models/SosAlert.php';

use App\Models\SosAlert;

$elderlyId = (int)($argv[1] ?? 1);
$idx = (int)($argv[2] ?? 0);

try {
    $id = SosAlert::create([
        'elderly_id' => $elderlyId,
        'latitude' => 53.9168,
        'longitude' => 27.5862,
        'address' => 'г. Минск, Советский район',
        'reason' => "Стресс параллельный процесс #{$idx}",
        'status' => 'ACTIVE',
        'priority' => 0,
        'emercom_ticket_id' => "EMERCOM-112-BY-2026-BURST{$idx}",
        'created_at' => date('Y-m-d H:i:s')
    ]);
    echo "OK:{$id}\n";
    exit(0);
} catch (\Throwable $e) {
    echo "ERR:" . $e->getMessage() . "\n";
    exit(1);
}
PHP;

file_put_contents($scriptPath, $workerCode);

$procHandles = [];
$pipes = [];
$cmd = 'php ' . escapeshellarg($scriptPath) . " {$uidSeniorSOS} ";

for ($i = 0; $i < 10; $i++) {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $proc = proc_open($cmd . $i, $descriptors, $pipes[$i]);
    if (is_resource($proc)) {
        $procHandles[$i] = $proc;
    }
}

$procSuccessCount = 0;
$procErrors = [];

foreach ($procHandles as $i => $proc) {
    $stdout = stream_get_contents($pipes[$i][1]);
    $stderr = stream_get_contents($pipes[$i][2]);
    fclose($pipes[$i][0]);
    fclose($pipes[$i][1]);
    fclose($pipes[$i][2]);

    $exitCode = proc_close($proc);
    if ($exitCode === 0 && str_starts_with(trim($stdout), 'OK:')) {
        $procSuccessCount++;
    } else {
        $procErrors[] = "Worker {$i}: exit={$exitCode}, out={$stdout}, err={$stderr}";
    }
}

@unlink($scriptPath);

assertStress(
    'CHAL-SOS-02',
    'Multiprocess SQLite contention: 10 parallel processes insert without database lock exceptions',
    $procSuccessCount === 10,
    "Success count: {$procSuccessCount}/10. Errors: " . implode('; ', $procErrors)
);

// Verify total active alerts created for this user
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM sos_alerts WHERE elderly_id = :id");
$stmtCount->execute(['id' => $uidSeniorSOS]);
$totalAlertsInDb = (int)$stmtCount->fetchColumn();

assertStress(
    'CHAL-SOS-03',
    'Database integrity: exactly 20 active alerts persisted from 10 HTTP + 10 parallel CLI bursts',
    $totalAlertsInDb === 20,
    "Total in DB: {$totalAlertsInDb}/20"
);

echo "\n--------------------------------------------------------\n";
echo "CHALLENGER STRESS RESULTS: Total: {$total} | Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================\n\n";

exit($failed === 0 ? 0 : 1);
