<?php

declare(strict_types=1);

/**
 * ADVERSARIAL STRESS TEST HARNESS — MILESTONE 2
 * Challenger M2-2: Identity Adapters & Moderation Stress Verifier
 *
 * Suites:
 * 1. MSI Identity Adapter: Centuries 1-6, Leap Years, Boundaries, Regions, SQLi, Fuzzing
 * 2. Gosuslugi Identity Adapter: SNILS Zeros, Modulo 100/101 Boundaries, Malformed Strings
 * 3. Real-Time Badge Revocation: In-Flight Active TTL Cancellation & RBAC Enforcement
 * 4. Public Endpoint PII Leakage: Full Name Masking, Zero-Emoji, SVG Validation, Zero PII Leaks
 */

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
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/VolunteerProfile.php';
require_once __DIR__ . '/../app/Services/BadgeService.php';
require_once __DIR__ . '/../app/Services/AuthService.php';
require_once __DIR__ . '/../app/Services/Identity/IdentityProviderInterface.php';
require_once __DIR__ . '/../app/Services/Identity/MsiIdentityAdapter.php';
require_once __DIR__ . '/../app/Services/Identity/GosuslugiIdentityAdapter.php';
require_once __DIR__ . '/../app/Services/Identity/IdentityProviderFactory.php';

use App\Core\Database;
use App\Models\User;
use App\Models\VolunteerProfile;
use App\Services\BadgeService;
use App\Services\AuthService;
use App\Services\Identity\MsiIdentityAdapter;
use App\Services\Identity\GosuslugiIdentityAdapter;
use App\Services\Identity\IdentityProviderFactory;

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;
$findings = [];

function assertAdversarial(string $suite, string $name, bool $condition, string $details = ''): void
{
    global $totalTests, $passedTests, $failedTests, $findings;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] [{$suite}] {$name}\n";
    } else {
        $failedTests++;
        echo "  [FAIL] [{$suite}] {$name} | {$details}\n";
        $findings[] = [
            'suite' => $suite,
            'test' => $name,
            'details' => $details
        ];
    }
}

function httpCall(string $method, string $path, ?array $data = null, array $headers = []): array
{
    $url = 'http://127.0.0.1:8000' . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $reqHeaders = [];
    $hasContentType = false;
    foreach ($headers as $h) {
        $reqHeaders[] = $h;
        if (stripos($h, 'Content-Type:') === 0) {
            $hasContentType = true;
        }
    }
    if (!$hasContentType && $data !== null) {
        $reqHeaders[] = 'Content-Type: application/json';
    }

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);

    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
    curl_close($ch);

    $json = null;
    if ($raw !== false && (str_contains($contentType, 'application/json') || str_starts_with(trim($raw), '{') || str_starts_with(trim($raw), '['))) {
        $json = json_decode($raw, true);
    }

    return [
        'status' => $status,
        'headers' => ['content-type' => $contentType],
        'raw' => $raw,
        'json' => $json,
    ];
}

echo "======================================================================\n";
echo "   ADVERSARIAL STRESS TEST SUITE: MILESTONE 2 EMPIRICAL HARNESS\n";
echo "   Challenger M2-2: Identity Adapters & Moderation Stress Verifier\n";
echo "======================================================================\n\n";

$msi = new MsiIdentityAdapter();
$gosuslugi = new GosuslugiIdentityAdapter();

// ====================================================================
// SUITE 1: MSI Identity Adapter Adversarial Stress-Tests
// ====================================================================
echo "[SUITE 1] MSI Identity Adapter (Centuries 1-6, Leap Years, Regions, Fuzzing)...\n";

// 1.1 Centuries 1 through 6
// Century 1: Male born in 19th century (1800-1899)
$c1 = $msi->validateFormat('1010185A001PB1');
assertAdversarial('MSI', 'Century 1: Male born in 1885',
    $c1['valid'] === true &&
    $c1['parsed']['gender'] === 'M' &&
    $c1['parsed']['birth_date'] === '1885-01-01',
    json_encode($c1, JSON_UNESCAPED_UNICODE)
);

// Century 2: Female born in 19th century (1800-1899)
$c2 = $msi->validateFormat('2010185A001PB2');
assertAdversarial('MSI', 'Century 2: Female born in 1885',
    $c2['valid'] === true &&
    $c2['parsed']['gender'] === 'F' &&
    $c2['parsed']['birth_date'] === '1885-01-01',
    json_encode($c2, JSON_UNESCAPED_UNICODE)
);

// Century 3: Male born in 20th century (1900-1999)
$c3 = $msi->validateFormat('3010190A001PB5');
assertAdversarial('MSI', 'Century 3: Male born in 1990',
    $c3['valid'] === true &&
    $c3['parsed']['gender'] === 'M' &&
    $c3['parsed']['birth_date'] === '1990-01-01',
    json_encode($c3, JSON_UNESCAPED_UNICODE)
);

// Century 4: Female born in 20th century (1900-1999)
$c4 = $msi->validateFormat('4150885A002PB8');
assertAdversarial('MSI', 'Century 4: Female born in 1985',
    $c4['valid'] === true &&
    $c4['parsed']['gender'] === 'F' &&
    $c4['parsed']['birth_date'] === '1985-08-15',
    json_encode($c4, JSON_UNESCAPED_UNICODE)
);

// Century 5: Male born in 21st century (2000-2099)
$c5 = $msi->validateFormat('5010105A001PB5');
assertAdversarial('MSI', 'Century 5: Male born in 2005',
    $c5['valid'] === true &&
    $c5['parsed']['gender'] === 'M' &&
    $c5['parsed']['birth_date'] === '2005-01-01',
    json_encode($c5, JSON_UNESCAPED_UNICODE)
);

// Century 6: Female born in 21st century (2000-2099)
$c6 = $msi->validateFormat('6010105A001PB6');
assertAdversarial('MSI', 'Century 6: Female born in 2005',
    $c6['valid'] === true &&
    $c6['parsed']['gender'] === 'F' &&
    $c6['parsed']['birth_date'] === '2005-01-01',
    json_encode($c6, JSON_UNESCAPED_UNICODE)
);

// Invalid centuries (0, 7, 8, 9, letters)
assertAdversarial('MSI', 'Century 0 rejected', $msi->validateFormat('0010190A001PB5')['valid'] === false);
assertAdversarial('MSI', 'Century 7 rejected', $msi->validateFormat('7010190A001PB5')['valid'] === false);
assertAdversarial('MSI', 'Century 8 rejected', $msi->validateFormat('8010190A001PB5')['valid'] === false);
assertAdversarial('MSI', 'Century 9 rejected', $msi->validateFormat('9010190A001PB5')['valid'] === false);
assertAdversarial('MSI', 'Century letter A rejected', $msi->validateFormat('A010190A001PB5')['valid'] === false);

// 1.2 Leap Years & Gregorian Boundaries
// 2000 is a leap year (divisible by 400) -> valid
$leap2000 = $msi->validateFormat('5290200A001PB1');
assertAdversarial('MSI', 'Leap year 2000: 29.02.2000 accepted',
    $leap2000['valid'] === true && $leap2000['parsed']['birth_date'] === '2000-02-29'
);

// 2004 is a leap year (divisible by 4) -> valid
$leap2004 = $msi->validateFormat('5290204A001PB1');
assertAdversarial('MSI', 'Leap year 2004: 29.02.2004 accepted',
    $leap2004['valid'] === true && $leap2004['parsed']['birth_date'] === '2004-02-29'
);

// 1996 is a leap year -> valid
$leap1996 = $msi->validateFormat('3290296A001PB1');
assertAdversarial('MSI', 'Leap year 1996: 29.02.1996 accepted',
    $leap1996['valid'] === true && $leap1996['parsed']['birth_date'] === '1996-02-29'
);

// 1904 is a leap year -> valid
$leap1904 = $msi->validateFormat('3290204A001PB1');
assertAdversarial('MSI', 'Leap year 1904: 29.02.1904 accepted',
    $leap1904['valid'] === true && $leap1904['parsed']['birth_date'] === '1904-02-29'
);

// 1900 is NOT a leap year in Gregorian calendar -> MUST BE REJECTED
$nonLeap1900 = $msi->validateFormat('3290200A001PB1');
assertAdversarial('MSI', 'Non-leap year 1900: 29.02.1900 rejected',
    $nonLeap1900['valid'] === false,
    '1900 is not a leap year'
);

// 2001 is NOT a leap year -> MUST BE REJECTED
$nonLeap2001 = $msi->validateFormat('5290201A001PB1');
assertAdversarial('MSI', 'Non-leap year 2001: 29.02.2001 rejected',
    $nonLeap2001['valid'] === false
);

// 1999 is NOT a leap year -> MUST BE REJECTED
$nonLeap1999 = $msi->validateFormat('3290299A001PB1');
assertAdversarial('MSI', 'Non-leap year 1999: 29.02.1999 rejected',
    $nonLeap1999['valid'] === false
);

// Day 30 in February on leap year -> MUST BE REJECTED
$feb30 = $msi->validateFormat('5300200A001PB1');
assertAdversarial('MSI', 'Invalid date: 30.02.2000 rejected', $feb30['valid'] === false);

// Day 31 in April (30-day month) -> MUST BE REJECTED
$apr31 = $msi->validateFormat('3310490A001PB5');
assertAdversarial('MSI', 'Invalid date: 31.04.1990 rejected', $apr31['valid'] === false);

// Day 00 / Day 32 / Month 00 / Month 13
assertAdversarial('MSI', 'Invalid day 00 rejected', $msi->validateFormat('3000190A001PB5')['valid'] === false);
assertAdversarial('MSI', 'Invalid day 32 rejected', $msi->validateFormat('3320190A001PB5')['valid'] === false);
assertAdversarial('MSI', 'Invalid month 00 rejected', $msi->validateFormat('3150090A001PB5')['valid'] === false);
assertAdversarial('MSI', 'Invalid month 13 rejected', $msi->validateFormat('3151390A001PB5')['valid'] === false);

// Additional 30-day month checks
assertAdversarial('MSI', 'Invalid date: 31.06.1990 (June 31) rejected', $msi->validateFormat('3310690A001PB5')['valid'] === false);
assertAdversarial('MSI', 'Invalid date: 31.09.1990 (September 31) rejected', $msi->validateFormat('3310990A001PB5')['valid'] === false);
assertAdversarial('MSI', 'Invalid date: 31.11.1990 (November 31) rejected', $msi->validateFormat('3311190A001PB5')['valid'] === false);

// Century 1 leap year (1888) vs non-leap year (1800)
$leap1888 = $msi->validateFormat('1290288A001PB1');
assertAdversarial('MSI', 'Century 1: Leap year 1888 (29.02.1888) accepted',
    $leap1888['valid'] === true && $leap1888['parsed']['birth_date'] === '1888-02-29'
);
assertAdversarial('MSI', 'Century 1: Non-leap year 1800 (29.02.1800) rejected',
    $msi->validateFormat('1290200A001PB1')['valid'] === false
);

// All 7 official Belarusian regions mapped correctly
$regions = [
    'A' => 'г. Минск',
    'B' => 'Брестская область',
    'C' => 'Витебская область',
    'E' => 'Гомельская область',
    'H' => 'Минская область',
    'K' => 'Гродненская область',
    'M' => 'Могилевская область',
];
foreach ($regions as $rCode => $rExpected) {
    $rRes = $msi->validateFormat("3010190{$rCode}001PB5");
    assertAdversarial('MSI', "Official region code {$rCode} maps to {$rExpected}",
        $rRes['valid'] === true && ($rRes['parsed']['region_name'] ?? '') === $rExpected
    );
}

// All official document types mapped correctly
$docTypes = [
    'PB' => 'Паспорт гражданина Республики Беларусь',
    'BA' => 'Удостоверение лица без гражданства',
    'BI' => 'Вид на жительство иностранного гражданина в РБ',
];
foreach ($docTypes as $dCode => $dExpected) {
    $dRes = $msi->validateFormat("3010190A001{$dCode}5");
    assertAdversarial('MSI', "Document type code {$dCode} maps to {$dExpected}",
        $dRes['valid'] === true && ($dRes['parsed']['doc_name'] ?? '') === $dExpected
    );
}

// Invalid region digit instead of letter
assertAdversarial('MSI', 'Invalid numeric region character rejected',
    $msi->validateFormat('30101909001PB5')['valid'] === false
);

// Non-standard region letter 'Z': check graceful fallback
$regZ = $msi->validateFormat('3010190Z001PB5');
assertAdversarial('MSI', 'Region Z gracefully handled with fallback descriptor',
    $regZ['valid'] === true && !empty($regZ['parsed']['region_name'])
);

// Check digit in position 14: non-digit character MUST BE REJECTED
assertAdversarial('MSI', 'Non-digit check character rejected by regex',
    $msi->validateFormat('3010190A001PBA')['valid'] === false
);

// 1.4 Known Security Blacklists (MVD / Court)
$secAlert = $msi->verifyIdentity('3010170A999PB0');
assertAdversarial('MSI', 'MVD Security Alert blacklist rejected',
    $secAlert['verified'] === false && ($secAlert['error'] ?? '') === 'MSI_SECURITY_ALERT'
);

$crimRecord = $msi->verifyIdentity('3010170A998PB9');
assertAdversarial('MSI', 'Criminal record blacklist rejected',
    $crimRecord['verified'] === false && ($crimRecord['error'] ?? '') === 'POLICE_CLEARANCE_FAILED'
);

// 1.5 Adversarial Fuzzing & Injection on MSI
$sqli1 = $msi->verifyIdentity("' OR '1'='1");
assertAdversarial('MSI', 'SQLi 1 rejected safely', $sqli1['verified'] === false && $sqli1['error'] === 'INVALID_FORMAT');

$sqli2 = $msi->verifyIdentity("3010190A001PB5'; DROP TABLE users; --");
assertAdversarial('MSI', 'SQLi 2 rejected safely', $sqli2['verified'] === false && $sqli2['error'] === 'INVALID_FORMAT');

$xss = $msi->verifyIdentity("<script>alert('xss')</script>");
assertAdversarial('MSI', 'XSS payload rejected safely', $xss['verified'] === false && $xss['error'] === 'INVALID_FORMAT');

$nulByte = $msi->verifyIdentity("3010190\0A001PB5");
assertAdversarial('MSI', 'Middle null-byte injection rejected safely', $nulByte['verified'] === false);

$longBuf = $msi->verifyIdentity(str_repeat('3', 10000));
assertAdversarial('MSI', '10,000-char buffer overflow rejected', $longBuf['verified'] === false);

// Cyrillic Homoglyphs: replace Latin 'A', 'P', 'B' with Cyrillic equivalents
$cyrillicA = '3010190' . "\xD0\x90" . '001PB5';
assertAdversarial('MSI', 'Cyrillic homoglyph А rejected safely',
    $msi->validateFormat($cyrillicA)['valid'] === false
);

// ====================================================================
// SUITE 2: Gosuslugi Identity Adapter Adversarial Stress-Tests
// ====================================================================
echo "\n[SUITE 2] Gosuslugi Identity Adapter (Zeros, Modulo 100/101 Boundaries, Malformed)...\n";

// 2.1 Zeros and Special ESIA Accounts
$esiaZeroUnformatted = $gosuslugi->verifyIdentity('00000000000');
assertAdversarial('Gosuslugi', '00000000000 rejected as ESIA_UNCONFIRMED_ACCOUNT',
    $esiaZeroUnformatted['verified'] === false && ($esiaZeroUnformatted['error'] ?? '') === 'ESIA_UNCONFIRMED_ACCOUNT'
);

$esiaZeroFormatted = $gosuslugi->verifyIdentity('000-000-000 00');
assertAdversarial('Gosuslugi', '000-000-000 00 rejected as ESIA_UNCONFIRMED_ACCOUNT',
    $esiaZeroFormatted['verified'] === false && ($esiaZeroFormatted['error'] ?? '') === 'ESIA_UNCONFIRMED_ACCOUNT'
);

$esiaBlocked = $gosuslugi->verifyIdentity('99999999999');
assertAdversarial('Gosuslugi', '99999999999 rejected as ESIA_ACCOUNT_BLOCKED',
    $esiaBlocked['verified'] === false && ($esiaBlocked['error'] ?? '') === 'ESIA_ACCOUNT_BLOCKED'
);

// Boundary numPart <= 1001998 (checksum check bypassed per Russian regulation)
$bypass1 = $gosuslugi->validateSnils('000-000-001 00');
assertAdversarial('Gosuslugi', 'SNILS <= 001-001-998: 000-000-001 00 accepted by regulation',
    $bypass1['valid'] === true
);

$bypassBoundary = $gosuslugi->validateSnils('001-001-998 00');
assertAdversarial('Gosuslugi', 'SNILS boundary 001-001-998 00 accepted by regulation',
    $bypassBoundary['valid'] === true
);

// SNILS 001-001-999: numPart = 1001999 > 1001998 -> checksum active!
// Sum = 0*9 + 0*8 + 1*7 + 0*6 + 0*5 + 1*4 + 9*3 + 9*2 + 9*1 = 7 + 4 + 27 + 18 + 9 = 65
$activeValid = $gosuslugi->validateSnils('001-001-999 65');
assertAdversarial('Gosuslugi', 'SNILS 001-001-999 with valid checksum 65 accepted',
    $activeValid['valid'] === true
);

$activeInvalid = $gosuslugi->validateSnils('001-001-999 00');
assertAdversarial('Gosuslugi', 'SNILS 001-001-999 with invalid checksum 00 rejected',
    $activeInvalid['valid'] === false
);

// 2.2 Modulo 100 / 101 Boundary Arithmetic Stress Tests
// Case A: Sum < 100 (e.g. sum = 14)
// Digits: 002-000-000 -> 2*7 = 14
$sum14Valid = $gosuslugi->validateSnils('002-000-000 14');
assertAdversarial('Gosuslugi', 'Boundary Sum < 100: sum=14, check=14 valid',
    $sum14Valid['valid'] === true
);
$sum14Invalid = $gosuslugi->validateSnils('002-000-000 15');
assertAdversarial('Gosuslugi', 'Boundary Sum < 100: sum=14, check=15 rejected',
    $sum14Invalid['valid'] === false
);

// Case B: Sum == 100 -> Check digit MUST be 00
// Digits: 920-000-003 -> 9*9 (81) + 2*8 (16) + 3*1 (3) = 100
$sum100Valid = $gosuslugi->validateSnils('920-000-003 00');
assertAdversarial('Gosuslugi', 'Boundary Sum == 100: check=00 valid',
    $sum100Valid['valid'] === true
);
$sum100Invalid = $gosuslugi->validateSnils('920-000-003 01');
assertAdversarial('Gosuslugi', 'Boundary Sum == 100: check=01 rejected',
    $sum100Invalid['valid'] === false
);

// Case C: Sum == 101 -> Check digit MUST be 00
// Digits: 920-000-004 -> 9*9 (81) + 2*8 (16) + 4*1 (4) = 101
$sum101Valid = $gosuslugi->validateSnils('920-000-004 00');
assertAdversarial('Gosuslugi', 'Boundary Sum == 101: check=00 valid',
    $sum101Valid['valid'] === true
);
$sum101Invalid = $gosuslugi->validateSnils('920-000-004 01');
assertAdversarial('Gosuslugi', 'Boundary Sum == 101: check=01 rejected',
    $sum101Invalid['valid'] === false
);

// Case D: Sum > 101 and (sum % 101) == 100 -> Check digit MUST be 00
// Digits: 996-000-200 -> 9*9(81) + 9*8(72) + 6*7(42) + 2*3(6) = 201. 201 % 101 = 100
$rem100Valid = $gosuslugi->validateSnils('996-000-200 00');
assertAdversarial('Gosuslugi', 'Boundary Sum=201 (rem=100): check=00 valid',
    $rem100Valid['valid'] === true
);
$rem100Invalid = $gosuslugi->validateSnils('996-000-200 99');
assertAdversarial('Gosuslugi', 'Boundary Sum=201 (rem=100): check=99 rejected',
    $rem100Invalid['valid'] === false
);

// Case E: Sum > 101 and (sum % 101) == 0 -> Check digit MUST be 00
// Digits: 996-000-007 -> 9*9(81) + 9*8(72) + 6*7(42) + 7*1(7) = 202. 202 % 101 = 0
$rem0Valid = $gosuslugi->validateSnils('996-000-007 00');
assertAdversarial('Gosuslugi', 'Boundary Sum=202 (rem=0): check=00 valid',
    $rem0Valid['valid'] === true
);
$rem0Invalid = $gosuslugi->validateSnils('996-000-007 01');
assertAdversarial('Gosuslugi', 'Boundary Sum=202 (rem=0): check=01 rejected',
    $rem0Invalid['valid'] === false
);

// Case F: Sum > 101 and (sum % 101) == 1 -> Check digit MUST be 01
// Digits: 996-000-008 -> 81 + 72 + 42 + 8 = 203. 203 % 101 = 1
$rem1Valid = $gosuslugi->validateSnils('996-000-008 01');
assertAdversarial('Gosuslugi', 'Boundary Sum=203 (rem=1): check=01 valid',
    $rem1Valid['valid'] === true
);
$rem1Invalid = $gosuslugi->validateSnils('996-000-008 00');
assertAdversarial('Gosuslugi', 'Boundary Sum=203 (rem=1): check=00 rejected',
    $rem1Invalid['valid'] === false
);

// 2.3 Malformed Strings & Fuzzing on Gosuslugi
assertAdversarial('Gosuslugi', 'Letters in SNILS rejected',
    $gosuslugi->validateSnils('1122334459A')['valid'] === false
);
assertAdversarial('Gosuslugi', '10-digit short SNILS rejected',
    $gosuslugi->validateSnils('1122334459')['valid'] === false
);
assertAdversarial('Gosuslugi', '12-digit long SNILS rejected',
    $gosuslugi->validateSnils('112233445955')['valid'] === false
);
assertAdversarial('Gosuslugi', 'SQLi in SNILS rejected',
    $gosuslugi->validateSnils("' OR '1'='1")['valid'] === false
);
assertAdversarial('Gosuslugi', 'XSS in SNILS rejected',
    $gosuslugi->validateSnils("<script>alert(1)</script>")['valid'] === false
);

// ====================================================================
// SUITE 3: Real-Time Badge Revocation Under In-Flight Active TTL
// ====================================================================
echo "\n[SUITE 3] Real-Time Badge Revocation (In-Flight Active TTL & RBAC)...\n";

$pdo = Database::getConnection();

// Obtain genuine coordinator token via login
$coordLogin = httpCall('POST', '/api/auth/login', [
    'phone' => '+375295556677',
    'password' => 'password123'
]);
$coordToken = $coordLogin['json']['token'] ?? '';
assertAdversarial('Revocation', 'Coordinator logged in successfully', !empty($coordToken));

// Create a dedicated isolated test volunteer for this adversarial suite
$testPhone = '+37529' . mt_rand(1000000, 9999999);
$testVolunteerName = 'Ковалёв Алексей Сергеевич';
$passwordHash = password_hash('password123', PASSWORD_BCRYPT);

$pdo->prepare("INSERT INTO users (role_id, phone, name, password_hash) VALUES (2, ?, ?, ?)")
    ->execute([$testPhone, $testVolunteerName, $passwordHash]);
$testVolunteerId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO volunteer_profiles (user_id, is_verified, verification_status, latitude, longitude, passport_masked, police_clearance_status) VALUES (?, 1, 'VERIFIED', 53.9168, 27.5856, '3010190A***PB5', 'CLEAN')")
    ->execute([$testVolunteerId]);

// Generate active volunteer token
$volToken = AuthService::createToken([
    'id' => $testVolunteerId,
    'role_id' => 2,
    'role_code' => 'volunteer',
    'phone' => $testPhone,
    'name' => $testVolunteerName
]);

// Step 3.1: Volunteer generates a fresh active badge token (TTL = 3600)
$token = BadgeService::generateBadgeToken($testVolunteerId, $testVolunteerName, null, 3600);
$payload = BadgeService::verifyBadgeToken($token);

assertAdversarial('Revocation', 'Generated active badge token with TTL = 3600s',
    $payload !== null && ($payload['exp'] - $payload['iat']) === 3600
);

// Step 3.2: Scan badge via public API endpoint -> MUST BE 200 OK
$scan1 = httpCall('GET', "/api/volunteers/badge/{$token}");
assertAdversarial('Revocation', 'Initial public scan succeeds with HTTP 200 OK',
    $scan1['status'] === 200 && ($scan1['json']['valid'] ?? false) === true,
    "Status: {$scan1['status']}, Body: " . json_encode($scan1['json'], JSON_UNESCAPED_UNICODE)
);

// Step 3.3: Coordinator revokes / rejects volunteer
$rejectRes = httpCall('POST', "/api/coordinator/volunteers/{$testVolunteerId}/reject", [
    'reason' => 'Выявлена недостоверность предоставленных сведений о судимости'
], ["Authorization: Bearer {$coordToken}"]);

assertAdversarial('Revocation', 'Coordinator reject endpoint returns HTTP 200 OK',
    $rejectRes['status'] === 200 && ($rejectRes['json']['verification_status'] ?? '') === 'REJECTED',
    "Status: {$rejectRes['status']}"
);

// Verify database reflects rejection
$vpCheck = $pdo->query("SELECT is_verified, verification_status, badge_token FROM volunteer_profiles WHERE user_id = {$testVolunteerId}")->fetch(PDO::FETCH_ASSOC);
assertAdversarial('Revocation', 'Database immediately updated: is_verified = -1, status = REJECTED',
    (int)$vpCheck['is_verified'] === -1 && $vpCheck['verification_status'] === 'REJECTED'
);

// Step 3.4: Re-scan EXACT SAME in-flight token (TTL has NOT expired, 3590s remain!)
// MUST BE IMMEDIATELY REJECTED!
$scan2 = httpCall('GET', "/api/volunteers/badge/{$token}");
assertAdversarial('Revocation', 'In-flight token IMMEDIATELY REJECTED on public scan (HTTP 400, valid: false)',
    $scan2['status'] === 400 && ($scan2['json']['valid'] ?? true) === false,
    "Status: {$scan2['status']}, Body: " . json_encode($scan2['json'], JSON_UNESCAPED_UNICODE)
);

// Step 3.5: Volunteer tries self-service badge generation while rejected -> MUST BE HTTP 403
$selfBadgeBlocked = httpCall('GET', '/api/volunteers/my-badge', null, ["Authorization: Bearer {$volToken}"]);
assertAdversarial('Revocation', 'Volunteer self-service blocked after rejection (HTTP 403 Forbidden)',
    $selfBadgeBlocked['status'] === 403,
    "Status: {$selfBadgeBlocked['status']}"
);

// Step 3.6: Test SUSPENDED status
$pdo->prepare("UPDATE volunteer_profiles SET is_verified = 0, verification_status = 'SUSPENDED' WHERE user_id = ?")
    ->execute([$testVolunteerId]);

$scanSuspended = httpCall('GET', "/api/volunteers/badge/{$token}");
assertAdversarial('Revocation', 'Suspended volunteer token immediately rejected (HTTP 400, valid: false)',
    $scanSuspended['status'] === 400 && ($scanSuspended['json']['valid'] ?? true) === false
);

// Step 3.7: Test Tampered Signature
$tokenParts = explode('.', $token);
$tamperedToken = $tokenParts[0] . '.' . substr($tokenParts[1], 0, -2) . 'XX';
$scanTampered = httpCall('GET', "/api/volunteers/badge/{$tamperedToken}");
assertAdversarial('Revocation', 'Cryptographically tampered signature rejected (HTTP 400, valid: false)',
    $scanTampered['status'] === 400 && ($scanTampered['json']['valid'] ?? true) === false
);

// Step 3.8: Test Non-existent Volunteer Profile
$phantomToken = BadgeService::generateBadgeToken(999999, 'Фантом Волонтёрович Ф.', null, 3600);
$scanPhantom = httpCall('GET', "/api/volunteers/badge/{$phantomToken}");
assertAdversarial('Revocation', 'Non-existent volunteer profile token rejected (HTTP 400, valid: false)',
    $scanPhantom['status'] === 400 && ($scanPhantom['json']['valid'] ?? true) === false
);

// ====================================================================
// SUITE 4: Public Endpoint PII Leakage Stress-Test (Law 99-Z)
// ====================================================================
echo "\n[SUITE 4] Public Verification Endpoint PII Leakage Stress-Test...\n";

// Re-verify test volunteer for PII leakage suite
$pdo->prepare("UPDATE volunteer_profiles SET is_verified = 1, verification_status = 'VERIFIED' WHERE user_id = ?")
    ->execute([$testVolunteerId]);

$piiToken = BadgeService::generateBadgeToken($testVolunteerId, null, null, 3600);

// 4.1 JSON Public Endpoint PII Analysis
$piiScan = httpCall('GET', "/api/volunteers/badge/{$piiToken}");
assertAdversarial('PII', 'Public badge scan returns HTTP 200 OK', $piiScan['status'] === 200);

$rawJson = $piiScan['raw'];
$jsonObj = $piiScan['json'];

$sensitiveLastName = 'Ковалёв';
$sensitivePhoneDigits = substr($testPhone, -7);

// Assert NO occurrence of full surname "Ковалёв"
assertAdversarial('PII', 'Response contains ZERO occurrences of full surname (Ковалёв)',
    !str_contains($rawJson, $sensitiveLastName),
    "Surname leaked in JSON response: {$rawJson}"
);

// Assert NO occurrence of personal phone number
assertAdversarial('PII', "Response contains ZERO occurrences of personal phone digits ({$sensitivePhoneDigits})",
    !str_contains($rawJson, $sensitivePhoneDigits),
    "Phone leaked in JSON response"
);

// Assert NO occurrence of passport or passport_masked
assertAdversarial('PII', 'Response contains ZERO occurrences of personal ID / passport',
    !str_contains($rawJson, '3010190A') && !str_contains($rawJson, 'PB5') && !str_contains($rawJson, 'passport'),
    "Passport leaked in JSON response"
);

// Assert NO occurrence of password hash
assertAdversarial('PII', 'Response contains ZERO occurrences of password hash',
    !str_contains($rawJson, '$2y$') && !str_contains($rawJson, 'password_hash'),
    "Password hash leaked in JSON response"
);

// Assert exact safe public volunteer structure
$vData = $jsonObj['volunteer'] ?? [];
assertAdversarial('PII', 'Public data has first_name = Алексей', ($vData['first_name'] ?? '') === 'Алексей');
assertAdversarial('PII', 'Public data has patronymic = Сергеевич', ($vData['patronymic'] ?? '') === 'Сергеевич');
assertAdversarial('PII', 'Public data has last_initial = К.', ($vData['last_initial'] ?? '') === 'К.');
assertAdversarial('PII', 'Public data contains TCSON organization', str_contains($vData['organization'] ?? '', 'ТЦСОН'));

// 4.1.b Slavic Name Deconstruction Stress-Tests (Law 99-Z Surname Masking)
$nameMatrix = [
    'Смирнов Алексей Сергеевич' => ['first' => 'Алексей', 'patronymic' => 'Сергеевич', 'initial' => 'С.'],
    'Алексей Сергеевич Смирнов' => ['first' => 'Алексей', 'patronymic' => 'Сергеевич', 'initial' => 'С.'],
    'Ковалева Елена Владимировна' => ['first' => 'Елена', 'patronymic' => 'Владимировна', 'initial' => 'К.'],
    'Елена Владимировна Ковалева' => ['first' => 'Елена', 'patronymic' => 'Владимировна', 'initial' => 'К.'],
    'Алексей Смирнов' => ['first' => 'Алексей', 'initial' => 'С.'],
    'Алексей С.' => ['first' => 'Алексей', 'initial' => 'С.'],
    'С. Алексей' => ['first' => 'Алексей', 'initial' => 'С.'],
    '   Дмитрий   Александрович   Новиков   ' => ['first' => 'Дмитрий', 'patronymic' => 'Александрович', 'initial' => 'Н.'],
];

foreach ($nameMatrix as $rawInput => $exp) {
    $parsed = BadgeService::formatPublicVolunteerName($rawInput);
    $okFirst = $parsed['first_name'] === $exp['first'];
    $okInit = $parsed['last_initial'] === $exp['initial'];
    $okPatr = !isset($exp['patronymic']) || $parsed['patronymic'] === $exp['patronymic'];
    assertAdversarial('PII-Name', "Name parsing '{$rawInput}' masks surname to initial",
        $okFirst && $okInit && $okPatr,
        "Got: " . json_encode($parsed, JSON_UNESCAPED_UNICODE)
    );
}

// 4.2 SVG Visual Badge Card PII Analysis
$svgRes = httpCall('GET', "/api/volunteers/badge/{$piiToken}/svg");
assertAdversarial('PII', 'SVG badge card returns HTTP 200 OK', $svgRes['status'] === 200);
assertAdversarial('PII', 'SVG Content-Type is image/svg+xml', str_contains($svgRes['headers']['content-type'] ?? '', 'image/svg+xml'));

$rawSvg = $svgRes['raw'];

// Zero PII in SVG
assertAdversarial('PII', 'SVG contains ZERO occurrences of full surname (Ковалёв)', !str_contains($rawSvg, $sensitiveLastName));
assertAdversarial('PII', 'SVG contains ZERO occurrences of personal phone digits', !str_contains($rawSvg, $sensitivePhoneDigits));
assertAdversarial('PII', 'SVG contains ZERO occurrences of passport', !str_contains($rawSvg, '3010190A'));

// Only public TCSON hotline present in SVG
assertAdversarial('PII', 'SVG hotline is public TCSON duty service (+375 (17) 377-00-00)',
    str_contains($rawSvg, '+375 (17) 377-00-00')
);

// XML Validity
$xmlValid = @simplexml_load_string($rawSvg);
assertAdversarial('PII', 'SVG is 100% well-formed valid XML', $xmlValid !== false);

// Zero-Emoji compliance in SVG
$emojiRegex = '/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u';
$emojiMatches = preg_match($emojiRegex, $rawSvg);
assertAdversarial('PII', 'SVG contains ZERO emojis (100% inline SVG vector icons)', $emojiMatches === 0);

// 4.3 Fuzzing Public Badge Endpoint
$xssToken = rawurlencode("<script>alert(1)</script>");
$fuzzXss = httpCall('GET', "/api/volunteers/badge/{$xssToken}");
assertAdversarial('PII', 'XSS in token URL safely rejected with HTTP 400', $fuzzXss['status'] === 400, "Actual HTTP status: {$fuzzXss['status']}, body: {$fuzzXss['raw']}");

$sqliToken = rawurlencode("' OR 1=1 --");
$fuzzSqli = httpCall('GET', "/api/volunteers/badge/{$sqliToken}");
assertAdversarial('PII', 'SQLi in token URL safely rejected with HTTP 400', $fuzzSqli['status'] === 400, "Actual HTTP status: {$fuzzSqli['status']}, body: {$fuzzSqli['raw']}");

$fuzzNull = httpCall('GET', '/api/volunteers/badge/%00%00%00');
assertAdversarial('PII', 'Null bytes in token URL safely rejected with HTTP 400', $fuzzNull['status'] === 400);

// Cleanup test volunteer
$pdo->prepare("DELETE FROM volunteer_profiles WHERE user_id = ?")->execute([$testVolunteerId]);
$pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$testVolunteerId]);

// ====================================================================
// SUMMARY & VERDICT
// ====================================================================
echo "\n======================================================================\n";
echo "   ADVERSARIAL STRESS TEST RESULTS (MILESTONE 2)\n";
echo "======================================================================\n";
echo "Total Tests Run : {$totalTests}\n";
echo "Passed Assertions: {$passedTests}\n";
echo "Failed Assertions: {$failedTests}\n";

$verdict = ($failedTests === 0) ? 'CONFIRM' : 'REJECT';
echo "VERDICT: {$verdict}\n";
echo "======================================================================\n";

if ($failedTests > 0) {
    echo "\nFailed Assertions Detail:\n";
    foreach ($findings as $f) {
        echo " - [{$f['suite']}] {$f['test']}: {$f['details']}\n";
    }
}

exit($failedTests === 0 ? 0 : 1);
