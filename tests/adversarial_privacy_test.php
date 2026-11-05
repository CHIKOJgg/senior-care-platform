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

echo "======================================================================\n";
echo "   ADVERSARIAL STRESS TEST SUITE: PRIVACY, MASKING & ANONYMIZATION\n";
echo "   Challenger M1-2: Empirical Verification Harness\n";
echo "======================================================================\n\n";

$passed = 0;
$failed = 0;
$results = [];

function assertAdversarial(string $category, string $name, bool $condition, string $details = ''): void {
    global $passed, $failed, $results;
    if ($condition) {
        echo "  [PASS] [{$category}] {$name}\n";
        $passed++;
        $results[] = ['category' => $category, 'name' => $name, 'status' => 'PASS', 'details' => ''];
    } else {
        echo "  [FAIL] [{$category}] {$name} - {$details}\n";
        $failed++;
        $results[] = ['category' => $category, 'name' => $name, 'status' => 'FAIL', 'details' => $details];
    }
}

function apiAdversarialCall(string $method, string $path, array $data = null, array $headers = []): array {
    $url = 'http://127.0.0.1:8000' . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

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
    return ['code' => $code, 'data' => json_decode($response ?: '', true), 'raw' => $response];
}

// ====================================================================
// SECTION 1: Phone Masking Tricky Formats & SQLi/XSS Injection
// ====================================================================
echo "----------------------------------------------------------------------\n";
echo "SECTION 1: Phone Masking Stress-Testing\n";
echo "----------------------------------------------------------------------\n";

$phoneCases = [
    // [input, expected_pattern_or_exact, description]
    [null, '', 'Null input returns empty string'],
    ['', '', 'Empty string returns empty string'],
    ['   ', '', 'Whitespace only returns empty string'],
    ['1', '***', 'Single digit returns ***'],
    ['12', '***', 'Two digits returns ***'],
    ['102', '***', 'Three digits (emergency 102) returns ***'],
    ['103', '***', 'Three digits (emergency 103) returns ***'],
    ['112', '***', 'Three digits (emergency 112) returns ***'],
    ['999', '***', 'Three digits 999 returns ***'],
    ['1234', '12***34', 'Four digits returns 12***34'],
    ['12345', '12***45', 'Five digits returns 12***45'],
    ['123456', '12***56', 'Six digits returns 12***56'],
    ['+375291234567', '+375 (29) ***-**-67', 'Standard E.164 Belarusian mobile'],
    ['80291234567', '+375 (29) ***-**-67', 'Domestic 80 Belarusian mobile prefix'],
    ['8-029-123-45-67', '+375 (29) ***-**-67', 'Domestic 8-029 with dashes'],
    ['(8029) 123-45-67', '+375 (29) ***-**-67', 'Domestic (8029) with parentheses and spaces'],
    ['+375.29.123.45.67', '+375 (29) ***-**-67', 'Dot delimiters in BY mobile'],
    ['+375/29/123/45/67', '+375 (29) ***-**-67', 'Slash delimiters in BY mobile'],
    ['+375_29_123_45_67', '+375 (29) ***-**-67', 'Underscore delimiters in BY mobile'],
    ['+375~29~123~45~67', '+375 (29) ***-**-67', 'Tilde delimiters in BY mobile'],
    ['+375 29 123 45 67', '+375 (29) ***-**-67', 'Space delimiters in BY mobile'],
    ['291234567', '+375 (29) ***-**-67', '9-digit BY mobile without prefix (29)'],
    ['331234567', '+375 (33) ***-**-67', '9-digit BY mobile without prefix (33)'],
    ['441234567', '+375 (44) ***-**-67', '9-digit BY mobile without prefix (44)'],
    ['251234567', '+375 (25) ***-**-67', '9-digit BY mobile without prefix (25)'],
    ['172001122', '+375 (17) ***-**-22', '9-digit BY Minsk landline without prefix (17)'],
    ['+375172001122', '+375 (17) ***-**-22', 'E.164 BY Minsk landline (+37517)'],
    ['+79161234567', '+7 (916) ***-**-67', 'Russian mobile +7'],
    ['89161234567', '+7 (916) ***-**-67', 'Russian mobile domestic 8 prefix'],
    ['+77011234567', '+7 (701) ***-**-67', 'Kazakhstan mobile +7'],
    ['+12025550123', '+1 (20) ***-**-23', 'North American +1'],
    ['+48123456789', '+48 (12) ***-**-89', 'Polish number +48'],
    // Malformed & injections
    ['invalid-phone', '***', 'Alphabetical string returns ***'],
    ['+375-invalid', '***', 'Country code with invalid string returns ***'],
    ["'+OR+1=1--", '***', 'SQL injection basic tautology returns ***'],
    ["' OR '1'='1", '***', 'SQL injection quoted tautology returns ***'],
    ["1; DROP TABLE users; --", '***', 'SQL injection DROP TABLE returns ***'],
    ["' UNION SELECT password_hash FROM users --", '***', 'SQL injection UNION SELECT returns ***'],
    ["+375291234567' AND 1=1--", '+375 (29) ***-**-11', 'SQL injection appended to phone: stripped to digits and masked safely'],
    ["+375291234567; DROP TABLE users;--", '+375 (29) ***-**-67', 'SQL injection appended without extra digits'],
    ["<script>alert(1)</script>", '***', 'XSS tag payload returns ***'],
    ["+375291234567<script>alert(1)</script>", '+375 (29) ***-**-71', 'XSS appended to phone: stripped to digits safely'],
];

foreach ($phoneCases as $tc) {
    [$input, $expected, $desc] = $tc;
    $actual = DataMaskingService::maskPhone($input);
    $ok = ($actual === $expected);
    assertAdversarial('PHONE', $desc, $ok, "Input: '{$input}' | Expected: '{$expected}' | Actual: '{$actual}'");
}

// Verify that in NO case does maskPhone reveal sensitive middle digits of a 12-digit BY phone
$rawByPhone = '+375299876543';
$maskedByPhone = DataMaskingService::maskPhone($rawByPhone);
$middleDigitsExposed = str_contains($maskedByPhone, '98765');
assertAdversarial('PHONE', 'Belarusian phone middle 5 digits strictly masked', !$middleDigitsExposed && $maskedByPhone === '+375 (29) ***-**-43');


// ====================================================================
// SECTION 2: Address Masking on Obscure Belarusian/Russian Formats
// ====================================================================
echo "\n----------------------------------------------------------------------\n";
echo "SECTION 2: Address Masking Obscure Formats & Edge Cases\n";
echo "----------------------------------------------------------------------\n";

$addressCases = [
    // Standard and variants
    ['г. Минск, пр-т Независимости, 45, кв. 12', 'г. Минск, пр-т Независимости, 45, кв. ***', 'Standard street, house, apartment'],
    ['пр-т Независимости, 45, квартира 105', 'пр-т Независимости, 45, кв. ***', 'Full word квартира'],
    ['ул. Сурганова, 15-42', 'ул. Сурганова, 15, кв. ***', 'Hyphenated house-flat format (15-42)'],
    ['г. Минск, ул. Сурганова, д. 15, под. 2, эт. 4, кв. 42', 'г. Минск, ул. Сурганова, д. 15, кв. ***', 'Full entrance and floor details masked'],
    ['ул. Куйбышева, 35, под. 1, кв. 14, домофон 14В', 'ул. Куйбышева, 35, кв. ***', 'Entrance, apartment and intercom masked'],
    
    // Obscure: Complex multi-building numbers (slashes, buildings, корпусы)
    ['г. Минск, ул. Якуба Коласа, д. 28/2, кв. 15', 'г. Минск, ул. Якуба Коласа, д. 28/2, кв. ***', 'Slash building number with apartment'],
    ['г. Минск, пр-т Независимости, 45 корп. 3, кв. 12', 'г. Минск, пр-т Независимости, 45 корп. 3, кв. ***', 'Building with корпус and apartment'],
    ['г. Минск, ул. Притыцкого, д. 60 корп. 2, под. 3, эт. 5, кв. 89', 'г. Минск, ул. Притыцкого, д. 60 корп. 2, кв. ***', 'Complex building with корпус, entrance, floor, apartment'],
    ['ул. Некрасова, 28/2-45', 'ул. Некрасова, 28/2, кв. ***', 'Slash building with hyphenated apartment (28/2-45)'],
    
    // Obscure: Letters in building numbers (Russian/Belarusian cyrillic and latin)
    ['г. Минск, ул. Максима Богдановича, д. 120Б, кв. 14', 'г. Минск, ул. Максима Богдановича, д. 120Б, кв. ***', 'Cyrillic capital letter in building number (120Б)'],
    ['г. Минск, ул. Максима Богдановича, д. 120б, кв. 14', 'г. Минск, ул. Максима Богдановича, д. 120б, кв. ***', 'Cyrillic lowercase letter in building number (120б)'],
    ['г. Минск, ул. Веры Хоружей, 22А-5', 'г. Минск, ул. Веры Хоружей, 22А, кв. ***', 'Letter in building with hyphenated flat (22А-5)'],
    ['г. Минск, ул. Каховская, д. 37а корп. 1, кв. 9', 'г. Минск, ул. Каховская, д. 37а корп. 1, кв. ***', 'Letter + корпус + apartment'],

    // Obscure: Dormitories / Rooms (комната / комн.)
    ['г. Минск, ул. Академическая, 25, комната 312', 'г. Минск, ул. Академическая, 25, кв. ***', 'Dormitory room (комната 312)'],
    ['г. Минск, ул. Козлова, д. 8, общ. 2, комн. 45', 'г. Минск, ул. Козлова, д. 8, общ. 2, кв. ***', 'Dormitory block with комн. 45'],
    ['г. Минск, пр-т Независимости, 65, комн. 12', 'г. Минск, пр-т Независимости, 65, кв. ***', 'Dormitory room abbreviated (комн. 12)'],

    // Obscure: Private houses without flats (MUST NOT be altered)
    ['г. Минск, ул. Орловская, 12', 'г. Минск, ул. Орловская, 12', 'Private house single number preserved'],
    ['г. Минск, пер. Можайского, д. 45', 'г. Минск, пер. Можайского, д. 45', 'Private house with д. 45 preserved'],
    ['Минский р-н, д. Боровляны, ул. Центральная, д. 15', 'Минский р-н, д. Боровляны, ул. Центральная, д. 15', 'Village / suburban private house preserved'],
    ['г. Минск, пер. 2-й Собинова, 14', 'г. Минск, пер. 2-й Собинова, 14', 'Ordinal numbered lane (2-й Собинова) preserved'],
    ['г. Минск, ул. 1-я Поселковая, д. 7', 'г. Минск, ул. 1-я Поселковая, д. 7', 'Ordinal numbered street (1-я Поселковая) preserved'],
    ['г. Минск, ул. Садовая, 15/2', 'г. Минск, ул. Садовая, 15/2', 'Private house corner lot 15/2 preserved'],
    
    // Edge cases: null, empty, already masked
    [null, '', 'Null address returns empty string'],
    ['', '', 'Empty address returns empty string'],
    ['   ', '', 'Whitespace address returns empty string'],
    ['г. Минск, ул. Якуба Коласа, д. 10, кв. ***', 'г. Минск, ул. Якуба Коласа, д. 10, кв. ***', 'Already masked address is idempotent']
];

foreach ($addressCases as $tc) {
    [$input, $expected, $desc] = $tc;
    $actual = DataMaskingService::maskAddress($input);
    $ok = ($actual === $expected);
    assertAdversarial('ADDRESS', $desc, $ok, "Input: '{$input}' | Expected: '{$expected}' | Actual: '{$actual}'");
}


// ====================================================================
// SECTION 3: Bulk Anonymization Stress Test (50+ Users in Loop)
// ====================================================================
echo "\n----------------------------------------------------------------------\n";
echo "SECTION 3: Bulk Anonymization Stress-Testing (50+ Consecutive Users)\n";
echo "----------------------------------------------------------------------\n";

$privacyService = new PrivacyService();
$pdo = Database::getConnection();

// Create 55 disposable users with elderly profiles
$createdUserIds = [];
$totalBulk = 55;

echo "  Creating {$totalBulk} disposable test users for bulk stress test...\n";

for ($i = 1; $i <= $totalBulk; $i++) {
    $tempPhone = '+375' . (290000000 + $i + rand(100000, 900000));
    $userId = (int)User::create([
        'role_id' => 1,
        'phone' => $tempPhone,
        'name' => "Тестовый Стресс-Пользователь #{$i}",
        'password_hash' => password_hash("pass_{$i}", PASSWORD_DEFAULT)
    ]);
    
    ElderlyProfile::create([
        'user_id' => $userId,
        'address' => "г. Минск, ул. Экспериментальная, д. {$i}, кв. {$i}",
        'latitude' => 53.9100 + ($i * 0.0001),
        'longitude' => 27.5800 + ($i * 0.0001),
        'birth_year' => 1930 + ($i % 30),
        'mobility_notes' => "Диагноз стресс-теста для пользователя #{$i}: артрит, гипертония",
        'emergency_contact' => '+3752911122' . sprintf('%02d', $i % 100)
    ]);
    
    $createdUserIds[] = $userId;
}

assertAdversarial('BULK_ANON', "Created {$totalBulk} disposable users successfully", count($createdUserIds) === $totalBulk);

// Execute consecutive anonymization loop
$anonymizedPhones = [];
$bulkException = null;
$anonymizedCount = 0;

$t0 = microtime(true);

foreach ($createdUserIds as $idx => $uid) {
    try {
        $res = $privacyService->anonymizeUser($uid, "Стресс-тестирование пачки #{$idx}");
        $anonymizedCount++;
        
        // Fetch freshly stored phone to test uniqueness
        $stmtCheck = $pdo->prepare("SELECT phone, name, password_hash FROM users WHERE id = :id");
        $stmtCheck->execute(['id' => $uid]);
        $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        
        $anonymizedPhones[$uid] = $row['phone'];
    } catch (Throwable $e) {
        $bulkException = $e;
        echo "  [EXCEPTION] User #{$uid}: " . $e->getMessage() . "\n";
        break;
    }
}

$elapsed = round(microtime(true) - $t0, 3);
echo "  Completed {$anonymizedCount}/{$totalBulk} anonymizations in {$elapsed}s\n";

assertAdversarial('BULK_ANON', "All {$totalBulk} users anonymized without throwing any exception", $bulkException === null, $bulkException ? $bulkException->getMessage() : '');

// Verify NO UNIQUE constraint collision in the generated phones
$uniquePhoneCount = count(array_unique($anonymizedPhones));
assertAdversarial('BULK_ANON', "All {$totalBulk} generated anonymized phones are 100% unique in SQLite",
    $uniquePhoneCount === $totalBulk,
    "Expected {$totalBulk} unique phones, got {$uniquePhoneCount}"
);

// Verify format of generated phones
$allPhonesCompliant = true;
foreach ($anonymizedPhones as $uid => $p) {
    if (!preg_match('/^\+375 \(00\) 000-\d{2}-\d{2}$/', $p)) {
        $allPhonesCompliant = false;
        break;
    }
}
assertAdversarial('BULK_ANON', "All generated phones match '+375 (00) 000-XX-YY' pattern", $allPhonesCompliant);

// Verify data wiping across random sample of 5 bulk-anonymized users
$sampleIds = array_slice($createdUserIds, 0, 5);
$allSampleWiped = true;
foreach ($sampleIds as $sId) {
    $prof = ElderlyProfile::find($sId);
    $usr = User::find($sId);
    if ($prof['mobility_notes'] !== null || $prof['emergency_contact'] !== null || $prof['address'] !== PrivacyService::ANONYMIZED_ADDRESS) {
        $allSampleWiped = false;
        break;
    }
    if (!str_starts_with($usr['password_hash'], 'ANON_')) {
        $allSampleWiped = false;
        break;
    }
}
assertAdversarial('BULK_ANON', "Bulk elderly profiles have sensitive data completely wiped to NULL", $allSampleWiped);


// ====================================================================
// SECTION 4: Blocking Anonymization on Active In-Progress Tasks (409)
// ====================================================================
echo "\n----------------------------------------------------------------------\n";
echo "SECTION 4: Active Task Conflict Defense (409 Conflict)\n";
echo "----------------------------------------------------------------------\n";

// 4.1 Elderly user with active 'in_progress' task
$elderlyWithActiveTaskPhone = '+37529' . rand(1000000, 9999999);
$elderlyActiveId = (int)User::create([
    'role_id' => 1,
    'phone' => $elderlyWithActiveTaskPhone,
    'name' => 'Подопечный с активной заявкой',
    'password_hash' => password_hash('pass123', PASSWORD_DEFAULT)
]);
ElderlyProfile::create([
    'user_id' => $elderlyActiveId,
    'address' => 'г. Минск, ул. Чернышевского, 10, кв. 3',
    'latitude' => 53.9210,
    'longitude' => 27.5950
]);

$elderlyActiveToken = AuthService::createToken([
    'id' => $elderlyActiveId,
    'role_id' => 1,
    'role_code' => 'elderly',
    'phone' => $elderlyWithActiveTaskPhone,
    'name' => 'Подопечный с активной заявкой'
]);

// Volunteer user
$volunteerTaskPhone = '+37529' . rand(1000000, 9999999);
$volActiveId = (int)User::create([
    'role_id' => 2,
    'phone' => $volunteerTaskPhone,
    'name' => 'Волонтёр с активной задачей',
    'password_hash' => password_hash('pass123', PASSWORD_DEFAULT)
]);
VolunteerProfile::create([
    'user_id' => $volActiveId,
    'is_verified' => 1,
    'latitude' => 53.9200,
    'longitude' => 27.5900
]);
$volActiveToken = AuthService::createToken([
    'id' => $volActiveId,
    'role_id' => 2,
    'role_code' => 'volunteer',
    'phone' => $volunteerTaskPhone,
    'name' => 'Волонтёр с активной задачей'
]);

// Create request and set to 'in_progress'
$activeTaskId = (int)HelpRequest::create([
    'elderly_id' => $elderlyActiveId,
    'volunteer_id' => $volActiveId,
    'category_id' => 1,
    'title' => 'Доставка лекарств срочно',
    'description' => 'Сердечные капли',
    'latitude' => 53.9210,
    'longitude' => 27.5950,
    'address' => 'г. Минск, ул. Чернышевского, 10, кв. 3',
    'secret_code' => 'Вишня',
    'status' => 'in_progress'
]);

// Test 4.1.a: PrivacyService direct call for elderly with in_progress task throws DomainException
$serviceBlockedElderly = false;
$serviceBlockedElderlyMsg = '';
try {
    $privacyService->anonymizeUser($elderlyActiveId);
} catch (DomainException $e) {
    $serviceBlockedElderly = true;
    $serviceBlockedElderlyMsg = $e->getMessage();
}
assertAdversarial('CONFLICT_409', 'PrivacyService blocks elderly with in_progress task (DomainException)',
    $serviceBlockedElderly && str_contains($serviceBlockedElderlyMsg, 'активные заявки'),
    "Got: '{$serviceBlockedElderlyMsg}'"
);

// Test 4.1.b: REST API POST /api/privacy/anonymize returns 409 Conflict
$apiConflictRes = apiAdversarialCall('POST', '/api/privacy/anonymize', ['confirm' => true], [
    "Authorization: Bearer {$elderlyActiveToken}"
]);
assertAdversarial('CONFLICT_409', 'REST API POST /api/privacy/anonymize returns HTTP 409 Conflict',
    $apiConflictRes['code'] === 409 && !empty($apiConflictRes['data']['error']),
    "HTTP {$apiConflictRes['code']}: " . json_encode($apiConflictRes['data'], JSON_UNESCAPED_UNICODE)
);

// Test 4.1.c: Admin REST API POST /api/elderly/{id}/anonymize returns 409 Conflict
$adminLogin = apiAdversarialCall('POST', '/api/auth/login', [
    'phone' => '+375299999999',
    'password' => 'password123'
]);
$adminToken = $adminLogin['data']['token'] ?? '';

$adminCoordConflictRes = apiAdversarialCall('POST', "/api/elderly/{$elderlyActiveId}/anonymize", ['confirm' => true], [
    "Authorization: Bearer {$adminToken}"
]);
assertAdversarial('CONFLICT_409', 'Admin endpoint POST /api/elderly/{id}/anonymize returns HTTP 409 Conflict for in_progress task',
    $adminCoordConflictRes['code'] === 409,
    "HTTP {$adminCoordConflictRes['code']}: " . json_encode($adminCoordConflictRes['data'], JSON_UNESCAPED_UNICODE)
);

// Test 4.2: Volunteer with active in_progress task is also blocked
$serviceBlockedVol = false;
$serviceBlockedVolMsg = '';
try {
    $privacyService->anonymizeUser($volActiveId);
} catch (DomainException $e) {
    $serviceBlockedVol = true;
    $serviceBlockedVolMsg = $e->getMessage();
}
assertAdversarial('CONFLICT_409', 'PrivacyService blocks volunteer assigned to in_progress task (DomainException)',
    $serviceBlockedVol && str_contains($serviceBlockedVolMsg, 'активные заявки'),
    "Got: '{$serviceBlockedVolMsg}'"
);

$volApiConflictRes = apiAdversarialCall('POST', '/api/privacy/anonymize', ['confirm' => true], [
    "Authorization: Bearer {$volActiveToken}"
]);
assertAdversarial('CONFLICT_409', 'REST API blocks volunteer assigned to in_progress task with HTTP 409',
    $volApiConflictRes['code'] === 409,
    "HTTP {$volApiConflictRes['code']}: " . json_encode($volApiConflictRes['data'], JSON_UNESCAPED_UNICODE)
);

// Test 4.3: Open task also blocks anonymization
HelpRequest::update($activeTaskId, ['status' => 'open', 'volunteer_id' => null]);
$openBlocked = false;
try {
    $privacyService->anonymizeUser($elderlyActiveId);
} catch (DomainException $e) {
    $openBlocked = true;
}
assertAdversarial('CONFLICT_409', 'Open task (unassigned) strictly blocks anonymization (DomainException / 409)', $openBlocked);

// Cancel the task and verify unblocking
HelpRequest::update($activeTaskId, ['status' => 'cancelled']);
$cancelledUnblocked = $privacyService->canAnonymizeUser($elderlyActiveId);
assertAdversarial('CONFLICT_409', 'Cancelled task unblocks anonymization (allowed = true)',
    $cancelledUnblocked['allowed'] === true && $cancelledUnblocked['active_requests_count'] === 0
);


// ====================================================================
// SECTION 5: Referential Integrity, Cascade Prevention & Audit Preservation
// ====================================================================
echo "\n----------------------------------------------------------------------\n";
echo "SECTION 5: Foreign Key Cascades & Civic Audit Trail Preservation\n";
echo "----------------------------------------------------------------------\n";

// Create user with multiple historical requests
$auditElderlyPhone = '+37529' . rand(1000000, 9999999);
$auditElderlyId = (int)User::create([
    'role_id' => 1,
    'phone' => $auditElderlyPhone,
    'name' => 'Аудит Подопечный Исторический',
    'password_hash' => password_hash('pass123', PASSWORD_DEFAULT)
]);
ElderlyProfile::create([
    'user_id' => $auditElderlyId,
    'address' => 'г. Минск, ул. Платонова, 15, кв. 88',
    'latitude' => 53.9110,
    'longitude' => 27.5920,
    'birth_year' => 1942,
    'mobility_notes' => 'Конфиденциальные медицинские данные: кардиология',
    'emergency_contact' => '+375293334455'
]);

// Create 3 historical requests with different categories and timestamps
$req1Id = (int)HelpRequest::create([
    'elderly_id' => $auditElderlyId,
    'volunteer_id' => 2, // Volunteer Vera
    'category_id' => 1, // Продукты
    'title' => 'Покупка молока и хлеба',
    'description' => 'Свежий батон',
    'latitude' => 53.9110,
    'longitude' => 27.5920,
    'address' => 'г. Минск, ул. Платонова, 15, кв. 88',
    'secret_code' => 'Берёза',
    'status' => 'completed',
    'completed_at' => '2026-10-15 14:30:00'
]);

$req2Id = (int)HelpRequest::create([
    'elderly_id' => $auditElderlyId,
    'volunteer_id' => 3, // Volunteer Dmitry
    'category_id' => 2, // Лекарства
    'title' => 'Доставка аспирина',
    'description' => 'Аспирин кардио',
    'latitude' => 53.9110,
    'longitude' => 27.5920,
    'address' => 'г. Минск, ул. Платонова, 15, кв. 88',
    'secret_code' => 'Клён',
    'status' => 'completed',
    'completed_at' => '2026-10-20 11:15:00'
]);

$req3Id = (int)HelpRequest::create([
    'elderly_id' => $auditElderlyId,
    'volunteer_id' => null,
    'category_id' => 4, // Уборка
    'title' => 'Помощь с мытьем окон',
    'description' => 'Отменено заявителем',
    'latitude' => 53.9110,
    'longitude' => 27.5920,
    'address' => 'г. Минск, ул. Платонова, 15, кв. 88',
    'secret_code' => 'Сосна',
    'status' => 'cancelled'
]);

// Verify baseline request count
$requestsBefore = HelpRequest::getByElderly($auditElderlyId);
assertAdversarial('AUDIT_PRESERVE', 'Elderly initially has exactly 3 historical requests', count($requestsBefore) === 3);

// Anonymize the user
$anonAuditRes = $privacyService->anonymizeUser($auditElderlyId, 'Проверка целостности внешних ключей и аудита');
assertAdversarial('AUDIT_PRESERVE', 'Anonymization executed successfully', $anonAuditRes['success'] === true);

// 1. Verify user record still exists in users table (NOT deleted via cascade)
$userAfter = User::find($auditElderlyId);
assertAdversarial('AUDIT_PRESERVE', 'User record in users table still exists (NO DELETE FROM users)',
    $userAfter !== null && (int)$userAfter['id'] === $auditElderlyId
);
assertAdversarial('AUDIT_PRESERVE', 'User personal name is pseudonymized',
    $userAfter['name'] === "Анонимизированный пользователь #{$auditElderlyId}"
);
assertAdversarial('AUDIT_PRESERVE', 'User phone is pseudonymized compliant placeholder',
    str_starts_with($userAfter['phone'], '+375 (00) 000-')
);

// 2. Verify all 3 help requests still exist in help_requests with elderly_id intact
$requestsAfter = HelpRequest::getByElderly($auditElderlyId);
assertAdversarial('AUDIT_PRESERVE', 'Foreign key cascade NOT triggered: all 3 requests remain linked to elderly_id',
    count($requestsAfter) === 3,
    "Expected 3 requests, got " . count($requestsAfter)
);

// 3. Verify civic statistics integrity: category_id, status, timestamps remain intact
$r1After = HelpRequest::find($req1Id);
$r2After = HelpRequest::find($req2Id);
$r3After = HelpRequest::find($req3Id);

$statsIntact = (
    (int)$r1After['category_id'] === 1 && $r1After['status'] === 'completed' &&
    (int)$r2After['category_id'] === 2 && $r2After['status'] === 'completed' &&
    (int)$r3After['category_id'] === 4 && $r3After['status'] === 'cancelled'
);
assertAdversarial('AUDIT_PRESERVE', 'Civic service analytics preserved (category_id, status intact across requests)', $statsIntact);

// 4. Verify privacy redaction in historical help_requests
$privacySanitized = (
    $r1After['address'] === PrivacyService::ANONYMIZED_ADDRESS &&
    $r1After['secret_code'] === '[REDACTED]' &&
    $r2After['address'] === PrivacyService::ANONYMIZED_ADDRESS &&
    $r2After['secret_code'] === '[REDACTED]' &&
    $r3After['address'] === PrivacyService::ANONYMIZED_ADDRESS &&
    $r3After['secret_code'] === '[REDACTED]'
);
assertAdversarial('AUDIT_PRESERVE', 'Historical requests sanitized (address coarsened, secret_code redacted)', $privacySanitized);

// 5. Verify SQLite foreign key integrity check
$fkCheckStmt = $pdo->query("PRAGMA foreign_key_check;");
$fkErrors = $fkCheckStmt->fetchAll(PDO::FETCH_ASSOC);
assertAdversarial('AUDIT_PRESERVE', 'SQLite PRAGMA foreign_key_check reports 0 violations across database',
    count($fkErrors) === 0,
    "Foreign key errors: " . json_encode($fkErrors, JSON_UNESCAPED_UNICODE)
);

// 6. Verify municipal reporting aggregate query remains consistent
$stmtReport = $pdo->prepare("
    SELECT c.title as category_name, COUNT(hr.id) as total_completed
    FROM help_requests hr
    JOIN categories c ON hr.category_id = c.id
    WHERE hr.status = 'completed'
    GROUP BY c.id, c.title
");
$stmtReport->execute();
$reportRows = $stmtReport->fetchAll(PDO::FETCH_ASSOC);
assertAdversarial('AUDIT_PRESERVE', 'Municipal category completion reporting query executes cleanly after anonymization',
    count($reportRows) > 0
);


// ====================================================================
// SUMMARY & VERDICT
// ====================================================================
echo "\n======================================================================\n";
echo "ADVERSARIAL STRESS TEST SUMMARY:\n";
echo "Total Tests Executed: " . ($passed + $failed) . "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "VERDICT: " . ($failed === 0 ? "CONFIRM" : "REJECT") . "\n";
echo "======================================================================\n";

if ($failed > 0) {
    exit(1);
}
