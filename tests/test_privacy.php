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
require_once __DIR__ . '/../app/Models/ElderlyProfile.php';
require_once __DIR__ . '/../app/Models/VolunteerProfile.php';
require_once __DIR__ . '/../app/Models/HelpRequest.php';
require_once __DIR__ . '/../app/Models/UserConsent.php';
require_once __DIR__ . '/../app/Models/SosAlert.php';
require_once __DIR__ . '/../app/Services/CryptoService.php';
require_once __DIR__ . '/../app/Services/DataMaskingService.php';
require_once __DIR__ . '/../app/Services/PrivacyService.php';
require_once __DIR__ . '/../app/Services/AuthService.php';

use App\Core\Database;
use App\Models\User;
use App\Models\ElderlyProfile;
use App\Models\VolunteerProfile;
use App\Models\HelpRequest;
use App\Models\UserConsent;
use App\Services\CryptoService;
use App\Services\DataMaskingService;
use App\Services\PrivacyService;
use App\Services\AuthService;

echo "========================================================\n";
echo "   RUNNING MILESTONE 1 PRIVACY & SECURITY TEST SUITE\n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$name} - {$details}\n";
        $failed++;
    }
}

function apiCall(string $method, string $path, array $data = null, array $headers = []): array {
    $url = 'http://127.0.0.1:8000' . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $reqHeaders = ['Content-Type: application/json'];
    foreach ($headers as $h) {
        $reqHeaders[] = $h;
    }

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);

    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'data' => json_decode($response ?: '', true)];
}

// -------------------------------------------------------------
// SUITE 1: Cryptographic Engine (AES-256-GCM)
// -------------------------------------------------------------
echo "[TEST SUITE 1] AES-256-GCM Cryptographic Service:\n";

$sampleText = "Пациент имеет ограниченную подвижность: перелом шейки бедра, 2 группа инвалидности. Нужен пандус.";
$encrypted = CryptoService::encrypt($sampleText);

assertTest('Encryption produces non-empty string starting with enc:v1:',
    !empty($encrypted) && str_starts_with($encrypted, 'enc:v1:'),
    "Output: {$encrypted}"
);

$decrypted = CryptoService::decrypt($encrypted);
assertTest('Authenticated decryption recovers identical UTF-8 plaintext',
    $decrypted === $sampleText,
    "Got: {$decrypted}"
);

$encrypted2 = CryptoService::encrypt($sampleText);
assertTest('CSPRNG IV ensures two encryptions produce different ciphertexts',
    $encrypted !== $encrypted2,
    "Ciphertexts should be unique due to random IV"
);

// Idempotency: encrypting already encrypted payload does not re-encrypt
$doubleEnc = CryptoService::encrypt($encrypted);
assertTest('Double encryption is prevented (idempotent)',
    $doubleEnc === $encrypted,
    "Re-encrypting returned different result"
);

// Backward compatibility: unencrypted plaintext passed to decrypt() is returned as-is
$legacyPlain = "Обычный текст без шифрования";
assertTest('Legacy unencrypted data passes through decrypt without error',
    CryptoService::decrypt($legacyPlain) === $legacyPlain
);

// Tamper resistance: modifying a single bit in the ciphertext or tag causes decryption failure
$tampered = substr($encrypted, 0, -2) . 'AA';
$tamperDetected = false;
try {
    CryptoService::decrypt($tampered);
} catch (RuntimeException $e) {
    $tamperDetected = true;
}
assertTest('Tampered ciphertext or tag throws DecryptionException', $tamperDetected);

// Key derivation testing
$genKey = CryptoService::generateKey();
assertTest('Key generator creates base64 encoded 256-bit key',
    str_starts_with($genKey, 'base64:') && strlen(CryptoService::normalizeKey($genKey)) === 32
);

// -------------------------------------------------------------
// SUITE 2: Model-Level Transparent Encryption (ElderlyProfile)
// -------------------------------------------------------------
echo "\n[TEST SUITE 2] Transparent Model Encryption (ElderlyProfile):\n";

$testUserPhone = '+37529' . rand(1000000, 9999999);
$tempUserId = (int)User::create([
    'role_id' => 1,
    'phone' => $testUserPhone,
    'name' => 'Тестовая Бабушка Крипто',
    'password_hash' => password_hash('secret123', PASSWORD_DEFAULT)
]);

$medicalNotes = "Сахарный диабет 2 типа, принимает инсулин. Нужна трость.";
ElderlyProfile::create([
    'user_id' => $tempUserId,
    'address' => 'г. Минск, пр-т Независимости, 50, кв. 24',
    'latitude' => 53.9170,
    'longitude' => 27.5870,
    'birth_year' => 1940,
    'mobility_notes' => $medicalNotes,
    'emergency_contact' => '+375291234567'
]);

// Verify database raw value is encrypted
$pdo = Database::getConnection();
$stmtRaw = $pdo->prepare("SELECT mobility_notes FROM elderly_profiles WHERE user_id = :uid");
$stmtRaw->execute(['uid' => $tempUserId]);
$rawDbValue = (string)$stmtRaw->fetchColumn();

assertTest('Raw database record is genuinely encrypted with enc:v1: prefix',
    str_starts_with($rawDbValue, 'enc:v1:'),
    "Raw value in DB: {$rawDbValue}"
);

// Verify ElderlyProfile::find() decrypts transparently
$loadedProfile = ElderlyProfile::find($tempUserId);
assertTest('ElderlyProfile::find transparently decrypts mobility_notes',
    $loadedProfile !== null && $loadedProfile['mobility_notes'] === $medicalNotes,
    "Got: " . ($loadedProfile['mobility_notes'] ?? 'NULL')
);

// Verify ElderlyProfile::update() re-encrypts updated notes
$updatedNotes = "Обновление: состояние улучшилось, ходит самостоятельно.";
ElderlyProfile::update($tempUserId, ['mobility_notes' => $updatedNotes]);

$stmtRaw->execute(['uid' => $tempUserId]);
$rawUpdatedValue = (string)$stmtRaw->fetchColumn();
assertTest('ElderlyProfile::update encrypts newly assigned mobility_notes',
    str_starts_with($rawUpdatedValue, 'enc:v1:') && $rawUpdatedValue !== $rawDbValue
);

$reloadedProfile = ElderlyProfile::find($tempUserId);
assertTest('Reloaded updated profile decrypts to new notes',
    $reloadedProfile['mobility_notes'] === $updatedNotes
);

// -------------------------------------------------------------
// SUITE 3: Data Masking Service (Law 99-Z)
// -------------------------------------------------------------
echo "\n[TEST SUITE 3] PII Data Masking Service (Law 99-Z):\n";

assertTest('Masks Belarusian standard phone (+375291112233)',
    DataMaskingService::maskPhone('+375291112233') === '+375 (29) ***-**-33'
);

assertTest('Masks Belarusian domestic phone (80291112233)',
    DataMaskingService::maskPhone('80291112233') === '+375 (29) ***-**-33'
);

assertTest('Masks formatted Belarusian phone (+375 (44) 777-88-99)',
    DataMaskingService::maskPhone('+375 (44) 777-88-99') === '+375 (44) ***-**-99'
);

assertTest('Masks Russian phone (+79161234567)',
    DataMaskingService::maskPhone('+79161234567') === '+7 (916) ***-**-67'
);

assertTest('Masks International phone (+48123456789)',
    DataMaskingService::maskPhone('+48123456789') === '+48 (12) ***-**-89'
);

assertTest('Masks apartment in full address with entrance & floor',
    DataMaskingService::maskAddress('г. Минск, пр-т Независимости, д. 45, под. 2, эт. 4, кв. 12') === 'г. Минск, пр-т Независимости, д. 45, кв. ***'
);

assertTest('Masks full word квартира',
    DataMaskingService::maskAddress('пр-т Независимости, 45, квартира 105') === 'пр-т Независимости, 45, кв. ***'
);

assertTest('Masks hyphenated house-apartment format (15-42)',
    DataMaskingService::maskAddress('ул. Сурганова, 15-42') === 'ул. Сурганова, 15, кв. ***'
);

assertTest('Preserves private house / address without apartment unchanged',
    DataMaskingService::maskAddress('ул. Орловская, 12') === 'ул. Орловская, 12'
);

// Access control matrix tests
$mockRequest = [
    'elderly_id' => 10,
    'volunteer_id' => 20,
    'status' => 'open',
    'elderly_phone' => '+375291112233',
    'address' => 'пр-т Независимости, 45, кв. 12'
];

assertTest('Unauthenticated guest blocked from unmasked details',
    DataMaskingService::canViewUnmasked($mockRequest, null) === false
);

assertTest('Coordinator can view unmasked details',
    DataMaskingService::canViewUnmasked($mockRequest, ['user_id' => 99, 'role_code' => 'coordinator']) === true
);

assertTest('Author elderly can view unmasked details',
    DataMaskingService::canViewUnmasked($mockRequest, ['user_id' => 10, 'role_code' => 'elderly']) === true
);

assertTest('Volunteer viewing open task sees masked details',
    DataMaskingService::canViewUnmasked($mockRequest, ['user_id' => 20, 'role_code' => 'volunteer']) === false
);

$inProgressReq = $mockRequest;
$inProgressReq['status'] = 'in_progress';
assertTest('Assigned volunteer viewing in_progress task sees unmasked details',
    DataMaskingService::canViewUnmasked($inProgressReq, ['user_id' => 20, 'role_code' => 'volunteer']) === true
);

assertTest('Other volunteer viewing in_progress task sees masked details',
    DataMaskingService::canViewUnmasked($inProgressReq, ['user_id' => 999, 'role_code' => 'volunteer']) === false
);

// -------------------------------------------------------------
// SUITE 4: PrivacyService & Electronic Consent (Law 99-Z)
// -------------------------------------------------------------
echo "\n[TEST SUITE 4] Electronic Consent Ledger (PrivacyService):\n";

$privacyService = new PrivacyService();

// Record consent
$consentResult = $privacyService->recordConsent($tempUserId, PrivacyService::CONSENT_DATA_PROCESSING, '1.0.0');
assertTest('Record valid electronic consent returns active record',
    $consentResult['id'] > 0 && $consentResult['consent_type'] === PrivacyService::CONSENT_DATA_PROCESSING
);

assertTest('hasActiveConsent returns true for recorded consent',
    $privacyService->hasActiveConsent($tempUserId, PrivacyService::CONSENT_DATA_PROCESSING) === true
);

assertTest('Policy version mismatch check (min_version 2.0.0 vs recorded 1.0.0) returns false',
    $privacyService->hasActiveConsent($tempUserId, PrivacyService::CONSENT_DATA_PROCESSING, '2.0.0') === false
);

assertTest('Invalid consent type is rejected with InvalidArgumentException',
    (function () use ($privacyService, $tempUserId) {
        try {
            $privacyService->recordConsent($tempUserId, 'INVALID_CONSENT_TYPE');
            return false;
        } catch (InvalidArgumentException $e) {
            return true;
        }
    })()
);

// Revoke consent
$revoked = $privacyService->revokeConsent($tempUserId, PrivacyService::CONSENT_DATA_PROCESSING);
assertTest('revokeConsent successfully marks record as revoked', $revoked === true);
assertTest('hasActiveConsent returns false after revocation',
    $privacyService->hasActiveConsent($tempUserId, PrivacyService::CONSENT_DATA_PROCESSING) === false
);

// -------------------------------------------------------------
// SUITE 5: Right to be Forgotten (Anonymization & Referential Integrity)
// -------------------------------------------------------------
echo "\n[TEST SUITE 5] Right to be Forgotten (Cryptographic Anonymization):\n";

// Create a second disposable user to test active task blocking
$anonUserPhone = '+37529' . rand(1000000, 9999999);
$anonUserId = (int)User::create([
    'role_id' => 1,
    'phone' => $anonUserPhone,
    'name' => 'Николай Петрович Тестовый',
    'password_hash' => password_hash('secret123', PASSWORD_DEFAULT)
]);
ElderlyProfile::create([
    'user_id' => $anonUserId,
    'address' => 'г. Минск, ул. Куйбышева, 35, кв. 77',
    'latitude' => 53.9185,
    'longitude' => 27.5750,
    'birth_year' => 1938,
    'mobility_notes' => 'Сердечная недостаточность, кардиостимулятор',
    'emergency_contact' => '+375449876543'
]);

// Create an active request for this user
$activeReqId = (int)HelpRequest::create([
    'elderly_id' => $anonUserId,
    'category_id' => 1,
    'title' => 'Срочно купить хлеб',
    'description' => 'Хлеб бородинский',
    'latitude' => 53.9185,
    'longitude' => 27.5750,
    'address' => 'г. Минск, ул. Куйбышева, 35, кв. 77',
    'secret_code' => 'Ромашка',
    'status' => 'open'
]);

// Active task must block anonymization!
$checkBefore = $privacyService->canAnonymizeUser($anonUserId);
assertTest('Active request blocks user anonymization (canAnonymizeUser allowed = false)',
    $checkBefore['allowed'] === false && $checkBefore['active_requests_count'] === 1
);

// Complete the active request
HelpRequest::update($activeReqId, [
    'status' => 'completed',
    'completed_at' => date('Y-m-d H:i:s')
]);

$checkAfter = $privacyService->canAnonymizeUser($anonUserId);
assertTest('Completed request allows user anonymization (canAnonymizeUser allowed = true)',
    $checkAfter['allowed'] === true && $checkAfter['active_requests_count'] === 0
);

// Perform anonymization
$anonResult = $privacyService->anonymizeUser($anonUserId, 'Тестовый аудит права на забвение');
assertTest('anonymizeUser executes successfully without database errors',
    $anonResult['success'] === true && $anonResult['user_id'] === $anonUserId
);

// Inspect database state after anonymization
$anonymizedUser = User::find($anonUserId);
assertTest('User name is replaced with pseudonym',
    $anonymizedUser['name'] === "Анонимизированный пользователь #{$anonUserId}"
);

assertTest('User phone is replaced with unique compliant pattern',
    str_starts_with($anonymizedUser['phone'], '+375 (00) 000-')
);

assertTest('User password_hash is randomized to unmatchable token',
    str_starts_with($anonymizedUser['password_hash'], 'ANON_')
);

$anonymizedProfile = ElderlyProfile::find($anonUserId);
assertTest('Sensitive medical notes and emergency contact wiped to NULL',
    $anonymizedProfile['mobility_notes'] === null && $anonymizedProfile['emergency_contact'] === null
);

assertTest('Profile address coarsened to district level',
    $anonymizedProfile['address'] === PrivacyService::ANONYMIZED_ADDRESS
);

assertTest('Profile coordinates coarsened to district centroid',
    abs((float)$anonymizedProfile['latitude'] - PrivacyService::SOVETSKY_CENTROID_LAT) < 0.0001
);

// Help request sanitization
$sanitizedReq = HelpRequest::find($activeReqId);
assertTest('Historical request address coarsened to district',
    $sanitizedReq['address'] === PrivacyService::ANONYMIZED_ADDRESS
);

assertTest('Historical request secret safety code wiped to [REDACTED]',
    $sanitizedReq['secret_code'] === '[REDACTED]'
);

// Referential integrity: foreign keys and records are preserved
$elderlyRequests = HelpRequest::getByElderly($anonUserId);
assertTest('Referential integrity preserved: request record count retained for state reporting',
    count($elderlyRequests) === 1 && (int)$elderlyRequests[0]['id'] === $activeReqId
);

// Anonymize multiple users sequentially to ensure NO unique phone collision
$user3Phone = '+37529' . rand(1000000, 9999999);
$user3Id = (int)User::create([
    'role_id' => 1,
    'phone' => $user3Phone,
    'name' => 'Третий Пользователь',
    'password_hash' => password_hash('secret123', PASSWORD_DEFAULT)
]);
ElderlyProfile::create([
    'user_id' => $user3Id,
    'address' => 'г. Минск, ул. Козлова, 10, кв. 5',
    'latitude' => 53.9100,
    'longitude' => 27.5800,
    'mobility_notes' => 'Заметки'
]);

$anonResult3 = $privacyService->anonymizeUser($user3Id);
$u2 = User::find($anonUserId);
$u3 = User::find($user3Id);
assertTest('Multiple users anonymized without phone UNIQUE collision',
    $u2['phone'] !== $u3['phone'] && str_starts_with($u3['phone'], '+375 (00) 000-')
);

// -------------------------------------------------------------
// SUITE 6: REST API Privacy Endpoints (E2E Integration)
// -------------------------------------------------------------
echo "\n[TEST SUITE 6] REST API Privacy Endpoints:\n";

// Login as Admin (user #4)
$loginRes = apiCall('POST', '/api/auth/login', [
    'phone' => '+375299999999',
    'password' => 'password123'
]);
$adminToken = $loginRes['data']['token'] ?? '';
assertTest('Admin login generates valid bearer token', !empty($adminToken));

// Create temporary user for API test
$apiTestPhone = '+37529' . rand(1000000, 9999999);
$apiUserId = (int)User::create([
    'role_id' => 1,
    'phone' => $apiTestPhone,
    'name' => 'API Подопечный Тест',
    'password_hash' => password_hash('pass123', PASSWORD_DEFAULT)
]);
ElderlyProfile::create([
    'user_id' => $apiUserId,
    'address' => 'г. Минск, пр-т Независимости, 70, кв. 14',
    'latitude' => 53.9200,
    'longitude' => 27.5900
]);

$apiUserToken = AuthService::createToken([
    'id' => $apiUserId,
    'role_id' => 1,
    'role_code' => 'elderly',
    'phone' => $apiTestPhone,
    'name' => 'API Подопечный Тест'
]);

// 1. POST /api/consent
$consentRes = apiCall('POST', '/api/consent', [
    'consent_type' => 'DATA_PROCESSING_99Z',
    'policy_version' => '1.0.0'
], ["Authorization: Bearer {$apiUserToken}"]);
assertTest('POST /api/consent records consent and returns 201 Created',
    $consentRes['code'] === 201 && ($consentRes['data']['success'] ?? false) === true,
    "Got code {$consentRes['code']}, data: " . json_encode($consentRes['data'], JSON_UNESCAPED_UNICODE)
);

// 2. GET /api/consent
$getConsentRes = apiCall('GET', '/api/consent', null, ["Authorization: Bearer {$apiUserToken}"]);
assertTest('GET /api/consent returns 200 with consent list',
    $getConsentRes['code'] === 200 && count($getConsentRes['data']['data'] ?? []) >= 1,
    "Got code {$getConsentRes['code']}, data: " . json_encode($getConsentRes['data'], JSON_UNESCAPED_UNICODE)
);

// 3. POST /api/privacy/anonymize without confirm -> 400
$anonFailRes = apiCall('POST', '/api/privacy/anonymize', ['confirm' => false], ["Authorization: Bearer {$apiUserToken}"]);
assertTest('POST /api/privacy/anonymize without confirm: true returns 400 Bad Request',
    $anonFailRes['code'] === 400,
    "Got code {$anonFailRes['code']}, data: " . json_encode($anonFailRes['data'], JSON_UNESCAPED_UNICODE)
);

// 4. POST /api/privacy/anonymize with open task -> 409 Conflict
$openTaskRes = apiCall('POST', '/api/requests', [
    'elderly_id' => $apiUserId,
    'category_id' => 1,
    'title' => 'Открытая задача блокировки',
    'description' => 'Описание',
    'latitude' => 53.9200,
    'longitude' => 27.5900,
    'address' => 'г. Минск, пр-т Независимости, 70, кв. 14'
], ["Authorization: Bearer {$apiUserToken}"]);
$openTaskId = $openTaskRes['data']['request_id'] ?? 0;

$anonConflictRes = apiCall('POST', '/api/privacy/anonymize', ['confirm' => true], ["Authorization: Bearer {$apiUserToken}"]);
assertTest('POST /api/privacy/anonymize with open task returns 409 Conflict',
    $anonConflictRes['code'] === 409,
    "Got code {$anonConflictRes['code']}, data: " . json_encode($anonConflictRes['data'], JSON_UNESCAPED_UNICODE)
);

// Cancel the open task
if ($openTaskId > 0) {
    apiCall('POST', "/api/requests/{$openTaskId}/cancel", null, ["Authorization: Bearer {$apiUserToken}"]);
}

// 5. POST /api/elderly/{id}/anonymize by volunteer -> 403 Forbidden
$volToken = AuthService::createToken([
    'id' => 2,
    'role_id' => 2,
    'role_code' => 'volunteer',
    'name' => 'Волонтёр',
    'phone' => '+375292223344'
]);
$forbiddenRes = apiCall('POST', "/api/elderly/{$apiUserId}/anonymize", ['confirm' => true], ["Authorization: Bearer {$volToken}"]);
assertTest('POST /api/elderly/{id}/anonymize by volunteer is blocked with 403 Forbidden',
    $forbiddenRes['code'] === 403
);

// 6. POST /api/elderly/{id}/anonymize by coordinator/admin -> 200 OK
$coordRes = apiCall('POST', "/api/elderly/{$apiUserId}/anonymize", [
    'confirm' => true,
    'reason' => 'Письменное обращение в ТЦСОН'
], ["Authorization: Bearer {$adminToken}"]);
assertTest('POST /api/elderly/{id}/anonymize by admin returns 200 OK and anonymizes profile',
    $coordRes['code'] === 200 && $coordRes['data']['success'] === true
);

// Verify anonymized user state via fresh database query
Database::resetConnection();
$checkApiUser = User::find($apiUserId);
assertTest('Target elderly user successfully anonymized via admin API endpoint',
    $checkApiUser !== null && str_starts_with($checkApiUser['name'] ?? '', 'Анонимизированный пользователь #'),
    "Got user: " . json_encode($checkApiUser, JSON_UNESCAPED_UNICODE)
);

// 7. Verify open feed masking via GET /api/requests
$openFeedRes = apiCall('GET', '/api/requests');
assertTest('GET /api/requests returns 200 OK', $openFeedRes['code'] === 200);

$feedItems = $openFeedRes['data']['data'] ?? [];
$allMasked = true;
foreach ($feedItems as $item) {
    if (!empty($item['elderly_phone']) && !preg_match('/\+375 \(\d{2}\) \*\*\*-\*\*-\d{2}/', $item['elderly_phone'])) {
        $allMasked = false;
        break;
    }
}
assertTest('Open feed masks elderly phone numbers to +375 (XX) ***-**-YY', $allMasked && count($feedItems) > 0);

echo "\n--------------------------------------------------------\n";
echo "PRIVACY TEST RESULTS: Total: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
