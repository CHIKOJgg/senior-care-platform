<?php

declare(strict_types=1);

/**
 * Adversarial Stress Test Suite — Milestone 2: Badge Cryptography & Pure-PHP Vector QR Generator
 *
 * Scopes Tested:
 * 1. Dynamic badge token generation: tampering signature, payload alteration, expired tokens (TTL + 1s), boundary conditions.
 * 2. Pure-PHP Vector QR Code generator: test across various string lengths (V1-V20), special characters, URLs,
 *    XML validity via simplexml_load_string, and strict Zero-Emoji compliance in SVG output.
 * 3. Timing-attack verification: verify hash_equals is used for HMAC comparison (static inspection + empirical timing).
 * 4. End-to-End HTTP API & Real-time Revocation: verify public badge endpoint suppresses PII and honors coordinator revocation.
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
require_once __DIR__ . '/../app/Core/Logger.php';
require_once __DIR__ . '/../app/Core/RateLimiter.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/VolunteerProfile.php';
require_once __DIR__ . '/../app/Services/BadgeService.php';
require_once __DIR__ . '/../app/Services/AuthService.php';
require_once __DIR__ . '/e2e_suite/TestHarness.php';

use App\Services\BadgeService;
use App\Models\VolunteerProfile;
use App\Models\User;
use Tests\E2ESuite\TestHarness;

echo "================================================================================\n";
echo "   ADVERSARIAL STRESS TEST SUITE: MILESTONE 2 (BADGE CRYPTO & QR VERIFIER)      \n";
echo "================================================================================\n\n";

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;
$startTime = microtime(true);

function assertAdv(string $code, string $description, bool $condition, string $details = ''): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$code}: {$description}\n";
    } else {
        $failedTests++;
        echo "  [FAIL] {$code}: {$description} | Details: {$details}\n";
    }
}

$harness = TestHarness::getInstance('http://127.0.0.1:8000');

// =============================================================================
// SECTION 1: BADGE TOKEN DYNAMIC CRYPTOGRAPHY & SIGNATURE TAMPERING
// =============================================================================
echo "\n--- [SECTION 1] Dynamic Badge Token Signature Tampering Stress ---\n";

$validVolunteerId = 2;
$validToken = BadgeService::generateBadgeToken($validVolunteerId, 'Алексей Сергеевич Смирнов', 'ТЦСОН Советского района г. Минска');
$parts = explode('.', $validToken);
$origPayloadB64 = $parts[0];
$origSignature = $parts[1];

assertAdv('ADV-SIG-01', 'Valid dynamic token structure consists of 2 dot-separated base64url segments',
    count($parts) === 2 && !empty($origPayloadB64) && !empty($origSignature)
);

// 1.2: Random bit-flip signature corruption (100 independent trials)
$bitFlipFailures = 0;
$b64Alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
for ($i = 0; $i < 100; $i++) {
    $corruptedSig = $origSignature;
    $pos = random_int(0, strlen($corruptedSig) - 1);
    $origChar = $corruptedSig[$pos];
    do {
        $newChar = $b64Alphabet[random_int(0, strlen($b64Alphabet) - 1)];
    } while ($newChar === $origChar);
    
    $corruptedSig[$pos] = $newChar;
    $tamperedToken = $origPayloadB64 . '.' . $corruptedSig;
    if (BadgeService::verifyBadgeToken($tamperedToken) !== null) {
        $bitFlipFailures++;
    }
}
assertAdv('ADV-SIG-02', '100/100 single-character random signature mutations strictly rejected',
    $bitFlipFailures === 0,
    "Accepted mutations count: {$bitFlipFailures}"
);

// 1.3: Truncated signatures
$truncLengths = [0, 1, 5, 16, 32, 42]; // Full base64url sha256 is 43 characters
$allTruncRejected = true;
foreach ($truncLengths as $len) {
    $truncSig = substr($origSignature, 0, $len);
    $truncToken = $origPayloadB64 . '.' . $truncSig;
    if (BadgeService::verifyBadgeToken($truncToken) !== null) {
        $allTruncRejected = false;
        break;
    }
}
assertAdv('ADV-SIG-03', 'Truncated signatures (0, 1, 5, 16, 32, 42 chars) strictly rejected',
    $allTruncRejected
);

// 1.4: Appended garbage to signature
$garbageExtensions = ['A', '===', ' ', "\0", 'xYz123', '=='];
$allAppendedRejected = true;
foreach ($garbageExtensions as $ext) {
    $extendedToken = $origPayloadB64 . '.' . $origSignature . $ext;
    if (BadgeService::verifyBadgeToken($extendedToken) !== null) {
        $allAppendedRejected = false;
        break;
    }
}
assertAdv('ADV-SIG-04', 'Appended characters / padding to valid signature strictly rejected',
    $allAppendedRejected
);

// 1.5: Cross-payload signature replay
$token2 = BadgeService::generateBadgeToken(3, 'Дмитрий Новиков', 'ТЦСОН');
[$payload2B64, $sig2] = explode('.', $token2);
$replayedToken = $origPayloadB64 . '.' . $sig2;
assertAdv('ADV-SIG-05', 'Cross-payload signature replay attack strictly rejected',
    BadgeService::verifyBadgeToken($replayedToken) === null
);

// 1.6: Cross-secret signature verification
$defaultSecret = BadgeService::getSecret();
BadgeService::setSecret('adversarial_attacker_secret_key_666');
$attackerToken = BadgeService::generateBadgeToken($validVolunteerId, 'Алексей С.', 'ТЦСОН');
BadgeService::setSecret($defaultSecret); // restore legitimate secret
assertAdv('ADV-SIG-06', 'Token signed with foreign secret key strictly rejected by active service',
    BadgeService::verifyBadgeToken($attackerToken) === null
);

// 1.7: Hex formatted signature acceptance and corruption
$expectedRaw = hash_hmac('sha256', $origPayloadB64, BadgeService::getSecret(), false); // hex format
$hexToken = $origPayloadB64 . '.' . $expectedRaw;
$hexDecoded = BadgeService::verifyBadgeToken($hexToken);
assertAdv('ADV-SIG-07', 'Hex-formatted signature is supported and validly decodes payload',
    is_array($hexDecoded) && ($hexDecoded['vid'] ?? 0) === $validVolunteerId
);

$corruptedHex = substr_replace($expectedRaw, 'f', 0, 1);
if ($corruptedHex[0] === $expectedRaw[0]) {
    $corruptedHex = substr_replace($expectedRaw, '0', 0, 1);
}
assertAdv('ADV-SIG-08', 'Corrupted Hex-formatted signature strictly rejected',
    BadgeService::verifyBadgeToken($origPayloadB64 . '.' . $corruptedHex) === null
);

// 1.8: Non-base64 characters in signature
$nonB64Sigs = [
    $origPayloadB64 . '.!@#$%^&*()_+',
    $origPayloadB64 . '.[invalid]',
    $origPayloadB64 . '.<script>alert(1)</script>',
];
$allNonB64Rejected = true;
foreach ($nonB64Sigs as $nbs) {
    if (BadgeService::verifyBadgeToken($nbs) !== null) {
        $allNonB64Rejected = false;
        break;
    }
}
assertAdv('ADV-SIG-09', 'Non-base64 characters and symbols in signature strictly rejected',
    $allNonB64Rejected
);


// =============================================================================
// SECTION 2: PAYLOAD ALTERATION & PRIVILEGE ESCALATION INJECTION
// =============================================================================
echo "\n--- [SECTION 2] Payload Alteration & Privilege Escalation Stress ---\n";

// Decode legitimate payload
$decodedJson = base64_decode(strtr($origPayloadB64, '-_', '+/'));
$basePayload = json_decode($decodedJson, true);

// 2.1: Privilege Escalation — Change Volunteer ID to 1 (Admin) keeping original signature
$escalatedPayload = $basePayload;
$escalatedPayload['vid'] = 1;
$escalatedPayload['user_id'] = 1;
$escalatedB64 = rtrim(strtr(base64_encode(json_encode($escalatedPayload, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
assertAdv('ADV-PAY-01', 'Privilege escalation via vid alteration (keeps original signature) strictly rejected',
    BadgeService::verifyBadgeToken($escalatedB64 . '.' . $origSignature) === null
);

// 2.2: Status tampering — Change status to SUPER_ADMIN
$statusPayload = $basePayload;
$statusPayload['status'] = 'SUPER_ADMIN';
$statusB64 = rtrim(strtr(base64_encode(json_encode($statusPayload, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
assertAdv('ADV-PAY-02', 'Status field tampering (e.g. SUPER_ADMIN) strictly rejected',
    BadgeService::verifyBadgeToken($statusB64 . '.' . $origSignature) === null
);

// 2.3: Expiration Extension — Extend exp by 1 year
$expPayload = $basePayload;
$expPayload['exp'] = time() + 31536000;
$expB64 = rtrim(strtr(base64_encode(json_encode($expPayload, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
assertAdv('ADV-PAY-03', 'Expiration extension tampering (exp + 1 year) strictly rejected',
    BadgeService::verifyBadgeToken($expB64 . '.' . $origSignature) === null
);

// 2.4: Organization spoofing
$orgPayload = $basePayload;
$orgPayload['org'] = 'Мошенническая Организация';
$orgB64 = rtrim(strtr(base64_encode(json_encode($orgPayload, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
assertAdv('ADV-PAY-04', 'Organization name alteration strictly rejected',
    BadgeService::verifyBadgeToken($orgB64 . '.' . $origSignature) === null
);

// 2.5: Syntax-corrupted JSON payload (truncated JSON)
$corruptedJson = '{"vid":2,"user_id":2,"name":"Алексей';
$corruptedB64 = rtrim(strtr(base64_encode($corruptedJson), '+/', '-_'), '=');
$validSigForCorrupt = rtrim(strtr(base64_encode(hash_hmac('sha256', $corruptedB64, BadgeService::getSecret(), true)), '+/', '-_'), '=');
assertAdv('ADV-PAY-05', 'Truncated JSON in payload (even with valid HMAC) safely returns null without uncaught exception',
    BadgeService::verifyBadgeToken($corruptedB64 . '.' . $validSigForCorrupt) === null
);

// 2.6: Valid Base64 with binary random bytes
$randomBinary = random_bytes(64);
$randomB64 = rtrim(strtr(base64_encode($randomBinary), '+/', '-_'), '=');
$validSigForRand = rtrim(strtr(base64_encode(hash_hmac('sha256', $randomB64, BadgeService::getSecret(), true)), '+/', '-_'), '=');
assertAdv('ADV-PAY-06', 'Binary non-JSON payload (with valid HMAC) safely returns null',
    BadgeService::verifyBadgeToken($randomB64 . '.' . $validSigForRand) === null
);

// 2.7: Valid JSON but non-associative array
$arrayPayload = [1, 2, 3, 4, 5];
$arrayB64 = rtrim(strtr(base64_encode(json_encode($arrayPayload)), '+/', '-_'), '=');
$sigArray = rtrim(strtr(base64_encode(hash_hmac('sha256', $arrayB64, BadgeService::getSecret(), true)), '+/', '-_'), '=');
assertAdv('ADV-PAY-07', 'Non-associative JSON array payload returns null',
    BadgeService::verifyBadgeToken($arrayB64 . '.' . $sigArray) === null
);

// 2.8: Valid JSON primitive (e.g. integer or string)
$stringPayload = "just_a_string";
$strB64 = rtrim(strtr(base64_encode(json_encode($stringPayload)), '+/', '-_'), '=');
$sigStr = rtrim(strtr(base64_encode(hash_hmac('sha256', $strB64, BadgeService::getSecret(), true)), '+/', '-_'), '=');
assertAdv('ADV-PAY-08', 'JSON string primitive payload returns null',
    BadgeService::verifyBadgeToken($strB64 . '.' . $sigStr) === null
);

// 2.9: Missing exp field
$missingExp = ['vid' => 2, 'user_id' => 2, 'name' => 'Test'];
$missingExpB64 = rtrim(strtr(base64_encode(json_encode($missingExp)), '+/', '-_'), '=');
$sigMissingExp = rtrim(strtr(base64_encode(hash_hmac('sha256', $missingExpB64, BadgeService::getSecret(), true)), '+/', '-_'), '=');
assertAdv('ADV-PAY-09', 'Payload missing exp field returns null',
    BadgeService::verifyBadgeToken($missingExpB64 . '.' . $sigMissingExp) === null
);

// 2.10: Missing vid and user_id fields
$missingVid = ['exp' => time() + 3600, 'name' => 'Test'];
$missingVidB64 = rtrim(strtr(base64_encode(json_encode($missingVid)), '+/', '-_'), '=');
$sigMissingVid = rtrim(strtr(base64_encode(hash_hmac('sha256', $missingVidB64, BadgeService::getSecret(), true)), '+/', '-_'), '=');
assertAdv('ADV-PAY-10', 'Payload missing vid and user_id fields returns null',
    BadgeService::verifyBadgeToken($missingVidB64 . '.' . $sigMissingVid) === null
);

// 2.11: Future issued-at (iat) beyond 60s clock skew tolerance
$futureIatPayload = $basePayload;
$futureIatPayload['iat'] = time() + 70; // 70 seconds into the future
$futureIatB64 = rtrim(strtr(base64_encode(json_encode($futureIatPayload, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
$sigFutureIat = rtrim(strtr(base64_encode(hash_hmac('sha256', $futureIatB64, BadgeService::getSecret(), true)), '+/', '-_'), '=');
assertAdv('ADV-PAY-11', 'Token with future iat (+70s, exceeding 60s tolerance) strictly rejected',
    BadgeService::verifyBadgeToken($futureIatB64 . '.' . $sigFutureIat) === null
);

// 2.12: Future issued-at (iat) within 60s clock skew tolerance
$toleratedIatPayload = $basePayload;
$toleratedIatPayload['iat'] = time() + 45; // 45 seconds into future
$toleratedIatB64 = rtrim(strtr(base64_encode(json_encode($toleratedIatPayload, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
$sigToleratedIat = rtrim(strtr(base64_encode(hash_hmac('sha256', $toleratedIatB64, BadgeService::getSecret(), true)), '+/', '-_'), '=');
assertAdv('ADV-PAY-12', 'Token with near-future iat (+45s, within 60s tolerance) accepted',
    BadgeService::verifyBadgeToken($toleratedIatB64 . '.' . $sigToleratedIat) !== null
);


// =============================================================================
// SECTION 3: TTL EXPIRATION BOUNDARY CONDITION PRECISION TESTING
// =============================================================================
echo "\n--- [SECTION 3] TTL Expiration Boundary Conditions (TTL + 1s precision) ---\n";

$now = time();

// Function to construct and sign an arbitrary payload
function buildSignedBadgeToken(array $payload): string {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $b64, BadgeService::getSecret(), true)), '+/', '-_'), '=');
    return $b64 . '.' . $sig;
}

// 3.1: Token expiring at exp = now - 1 (1 second in past) -> MUST BE EXPIRED
$tokenPast1 = buildSignedBadgeToken([
    'vid' => 2,
    'user_id' => 2,
    'bid' => 'BY-MSK-SOV-2026-0002',
    'name' => 'Алексей С.',
    'org' => BadgeService::DEFAULT_ORG,
    'status' => 'VERIFIED',
    'iat' => $now - 3601,
    'exp' => $now - 1 // Expired 1 second ago
]);
assertAdv('ADV-TTL-01', 'Token at exp = now - 1s (expired by 1 second) strictly returns null',
    BadgeService::verifyBadgeToken($tokenPast1) === null
);

// 3.2: Token expiring at exp = now (boundary condition)
$tokenBoundaryNow = buildSignedBadgeToken([
    'vid' => 2,
    'user_id' => 2,
    'bid' => 'BY-MSK-SOV-2026-0002',
    'name' => 'Алексей С.',
    'org' => BadgeService::DEFAULT_ORG,
    'status' => 'VERIFIED',
    'iat' => $now - 3600,
    'exp' => $now // Exactly now
]);
// In BadgeService: if (time() > (int)$payload['exp']) -> at exactly time() == exp, not yet > exp
$boundaryDecoded = BadgeService::verifyBadgeToken($tokenBoundaryNow);
assertAdv('ADV-TTL-02', 'Token at exp = now (exact boundary) valid until strictly exceeded',
    $boundaryDecoded !== null && ($boundaryDecoded['vid'] ?? 0) === 2
);

// 3.3: Token expiring at exp = now + 1 (1 second left before expiration)
$tokenActive1 = buildSignedBadgeToken([
    'vid' => 2,
    'user_id' => 2,
    'bid' => 'BY-MSK-SOV-2026-0002',
    'name' => 'Алексей С.',
    'org' => BadgeService::DEFAULT_ORG,
    'status' => 'VERIFIED',
    'iat' => $now - 3599,
    'exp' => $now + 1 // 1 second remaining
]);
assertAdv('ADV-TTL-03', 'Token at exp = now + 1s (1 second remaining) remains valid',
    BadgeService::verifyBadgeToken($tokenActive1) !== null
);

// 3.4: Simulated token lifecycle: Created at t0 with TTL = 5 seconds, verified at t0+6s
$shortTtlToken = BadgeService::generateBadgeToken(2, 'Алексей С.', null, 5);
$shortPayload = BadgeService::verifyBadgeToken($shortTtlToken);
assertAdv('ADV-TTL-04', 'Custom short TTL (5s) correctly issued with exp = iat + 5',
    is_array($shortPayload) && ($shortPayload['exp'] - $shortPayload['iat']) === 5
);

// 3.5: Expired tokens at various historical deltas
$deltas = [2, 10, 60, 3600, 86400, 31536000];
$allHistoricalExpired = true;
foreach ($deltas as $d) {
    $histToken = buildSignedBadgeToken([
        'vid' => 2,
        'user_id' => 2,
        'bid' => 'BY-MSK-SOV-2026-0002',
        'name' => 'Алексей С.',
        'org' => BadgeService::DEFAULT_ORG,
        'status' => 'VERIFIED',
        'iat' => $now - $d - 3600,
        'exp' => $now - $d
    ]);
    if (BadgeService::verifyBadgeToken($histToken) !== null) {
        $allHistoricalExpired = false;
        break;
    }
}
assertAdv('ADV-TTL-05', 'Historical expired tokens at deltas (2s, 10s, 60s, 1h, 1d, 1y) strictly return null',
    $allHistoricalExpired
);


// =============================================================================
// SECTION 4: STRUCTURAL BOUNDARY CONDITIONS & MALFORMED STRINGS
// =============================================================================
echo "\n--- [SECTION 4] Structural Boundary Conditions & Malformed Tokens ---\n";

$malformedStrings = [
    'EMPTY' => '',
    'WHITESPACE' => '   ',
    'NO_DOT' => 'eyJ2aWQiOjJ9eyJhbGciOiJIUzI1NiJ9',
    'SINGLE_DOT' => '.',
    'TWO_DOTS' => 'a.b.c',
    'MANY_DOTS' => 'a.b.c.d.e.f',
    'LEADING_DOT' => '.signature_without_payload',
    'TRAILING_DOT' => 'payload_without_signature.',
    'NULL_BYTE' => "eyJ2aWQiOjJ9\0.signature",
    'UNICODE_DOT' => 'payload․signature', // U+2024 one dot leader
    'DOUBLE_DOT' => 'payload..signature',
    'CARRIAGE_RETURN' => "payload\r\n.signature",
];

$allMalformedRejected = true;
foreach ($malformedStrings as $caseName => $str) {
    if (BadgeService::verifyBadgeToken($str) !== null) {
        $allMalformedRejected = false;
        echo "    [WARN] Failed to reject malformed case: {$caseName}\n";
    }
}
assertAdv('ADV-STRUCT-01', '12 structural malformations (empty, no dot, multiple dots, null byte) return null',
    $allMalformedRejected
);

// 4.2: Massive token string (50,000 characters)
$massiveToken = str_repeat('A', 25000) . '.' . str_repeat('B', 25000);
$memBefore = memory_get_usage();
$massiveResult = BadgeService::verifyBadgeToken($massiveToken);
$memAfter = memory_get_usage();
assertAdv('ADV-STRUCT-02', '50,000-character massive malformed token rejected safely with zero memory leak',
    $massiveResult === null && ($memAfter - $memBefore) < 500000
);


// =============================================================================
// SECTION 5: PURE-PHP VECTOR QR CODE GENERATOR STRESS TESTING
// =============================================================================
echo "\n--- [SECTION 5] Pure-PHP Vector QR Code Generator Stress Testing ---\n";

// 5.1: Test across various string lengths covering QR Versions 1 through 20
$testLengths = [
    0   => 'Empty string (boundary)',
    1   => 'Single ASCII char',
    8   => '8-char short string',
    14  => '14-char Personal ID',
    17  => 'Version 1 max capacity boundary (17 bytes)',
    18  => 'Version 2 lower boundary (18 bytes)',
    32  => 'Version 2 mid (32 bytes)',
    50  => 'Version 3 (50 bytes)',
    75  => 'Version 4 (75 bytes)',
    105 => 'Version 5 (105 bytes)',
    130 => 'Version 6 (130 bytes)',
    150 => 'Version 7 (150 bytes, Version Info V>=7)',
    200 => 'Version 8/9 (200 bytes)',
    300 => 'Version 11/12 (300 bytes)',
    450 => 'Version 14 (450 bytes)',
    600 => 'Version 17 (600 bytes)',
    700 => 'Version 18/19 (700 bytes)',
    800 => 'Version 20 (800 bytes, high capacity)',
    850 => 'Version 20 near max (850 bytes)',
];

$allQrXmlValid = true;
$allQrSvgValid = true;
$allQrZeroEmoji = true;
$emojiRegex = '/[\x{1F300}-\x{1F64F}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1F900}-\x{1F9FF}\x{1FA70}-\x{1FAFF}]/u';

foreach ($testLengths as $len => $desc) {
    $inputStr = $len === 0 ? '' : substr(str_repeat('https://zabotaryadom.by/v/BY-MSK-SOV-2026-', (int)ceil($len / 40) + 1), 0, $len);
    try {
        $svg = BadgeService::generateQrSvg($inputStr, ['size' => 200, 'margin' => 3]);
        
        // 1. Check basic SVG structure
        if (!str_starts_with(trim($svg), '<svg') || !str_ends_with(trim($svg), '</svg>')) {
            $allQrSvgValid = false;
            echo "    [FAIL] SVG structure invalid for len {$len}: {$desc}\n";
        }
        
        // 2. Verify XML validity via simplexml_load_string
        $xml = simplexml_load_string($svg);
        if ($xml === false) {
            $allQrXmlValid = false;
            echo "    [FAIL] simplexml_load_string failed for len {$len}: {$desc}\n";
        }
        
        // 3. Verify Zero-Emoji compliance
        if (preg_match($emojiRegex, $svg)) {
            $allQrZeroEmoji = false;
            echo "    [FAIL] Emoji detected in QR SVG for len {$len}\n";
        }
        
    } catch (\Throwable $e) {
        $allQrXmlValid = false;
        echo "    [FAIL] Exception generated for len {$len} ({$desc}): " . $e->getMessage() . "\n";
    }
}

assertAdv('ADV-QR-01', 'Pure-PHP QR generates valid SVG across all test lengths (0 to 850 bytes, V1-V20)',
    $allQrSvgValid
);
assertAdv('ADV-QR-02', '100% of generated QR SVGs are well-formed XML verified by simplexml_load_string',
    $allQrXmlValid
);
assertAdv('ADV-QR-03', '100% of generated QR SVGs strictly comply with Zero-Emoji policy',
    $allQrZeroEmoji
);

// 5.2: Capacity Overflow Test (Payload exceeding Version 20 max capacity)
$oversizedPayload = str_repeat('X', 1200); // Exceeds V20 Level L (858 bytes)
$overflowHandled = false;
try {
    BadgeService::generateQrSvg($oversizedPayload);
} catch (\InvalidArgumentException $e) {
    $overflowHandled = true;
    assertAdv('ADV-QR-04', 'Capacity overflow (> 858 bytes) safely throws InvalidArgumentException without PHP fatal error',
        true,
        $e->getMessage()
    );
} catch (\Throwable $e) {
    assertAdv('ADV-QR-04', 'Capacity overflow threw unexpected exception type', false, get_class($e));
}
if (!$overflowHandled) {
    assertAdv('ADV-QR-04', 'Capacity overflow failed to throw InvalidArgumentException', false);
}

// 5.3: Special Characters, XML entities, URLs, Multibyte Cyrillic
$specialStrings = [
    'XML_TAGS' => '<script>alert("XSS")</script><tag attr="val">&quot;&amp;&apos;</tag>',
    'FULL_URL' => 'https://zabotaryadom.by/api/volunteers/badge/eyJhbGciOiJIUzI1NiJ9?param=1&ref=tcson#top',
    'CYRILLIC_TEXT' => 'Государственное учреждение «Территориальный центр социального обслуживания населения Советского района г. Минска», ул. Гикало, 22',
    'WHITESPACE' => "Line1\nLine2\r\nLine3\tTabbed\t\tDoubleTab",
    'PUNCTUATION' => '`~!@#$%^&*()-_=+[{]}\\|;:\'",<.>/?',
    'BINARY_BYTES' => "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x7F\x80\xFF",
];

$allSpecialValidXml = true;
$allSpecialZeroEmoji = true;
foreach ($specialStrings as $label => $specText) {
    $specSvg = BadgeService::generateQrSvg($specText, [
        'title' => "QR Тест: {$label} <&'\" >",
        'color' => '#0F172A',
        'bgColor' => '#F8FAFC'
    ]);
    
    $specXml = simplexml_load_string($specSvg);
    if ($specXml === false) {
        $allSpecialValidXml = false;
        echo "    [FAIL] Special string XML invalid: {$label}\n";
    }
    
    if (preg_match($emojiRegex, $specSvg)) {
        $allSpecialZeroEmoji = false;
        echo "    [FAIL] Emoji found in special string: {$label}\n";
    }
}
assertAdv('ADV-QR-05', 'Special chars (XML tags, XSS, Cyrillic, URLs, Binary, Whitespace) yield 100% valid XML',
    $allSpecialValidXml
);
assertAdv('ADV-QR-06', 'Special chars SVGs maintain 100% Zero-Emoji compliance',
    $allSpecialZeroEmoji
);

// 5.4: Stress with Emoji-Laden Text Input
$emojiInput = "Волонтёр ТЦСОН \u{1F475}\u{2764}\u{FE0F}\u{1F680}\u{1F691} Советский район г. Минска";
$emojiInputSvg = BadgeService::generateQrSvg($emojiInput, ['title' => 'Бейдж волонтёра ТЦСОН']);
$emojiXml = simplexml_load_string($emojiInputSvg);
$svgContainsEmoji = (bool)preg_match($emojiRegex, $emojiInputSvg);
assertAdv('ADV-QR-07', 'QR generated for emoji-laden input yields valid XML and does NOT inject emojis into SVG markup',
    $emojiXml !== false && !$svgContainsEmoji
);

// 5.5: Complete Visual Badge Card Stress Testing (BadgeService::generateBadgeSvg)
$cardNormal = BadgeService::generateBadgeSvg(2, $validToken);
$cardXmlNormal = simplexml_load_string($cardNormal);
$cardEmojiNormal = (bool)preg_match($emojiRegex, $cardNormal);
assertAdv('ADV-CARD-01', 'Complete 420x640 visual badge card is valid XML and contains zero emojis',
    $cardXmlNormal !== false && !$cardEmojiNormal
);

// Card with complex Slavic name and XML entities
$complexToken = BadgeService::generateBadgeToken(2, 'Д\'Артаньян & "О\'Генри" <Тест>', 'ТЦСОН Советского р-на & Партнёры');
$cardComplex = BadgeService::generateBadgeSvg(2, $complexToken);
$cardXmlComplex = simplexml_load_string($cardComplex);
$cardEmojiComplex = (bool)preg_match($emojiRegex, $cardComplex);
assertAdv('ADV-CARD-02', 'Visual badge card with quotes, XML entities and apostrophes is valid XML and 0 emojis',
    $cardXmlComplex !== false && !$cardEmojiComplex
);


// =============================================================================
// SECTION 6: TIMING-ATTACK RESISTANCE VERIFICATION (hash_equals)
// =============================================================================
echo "\n--- [SECTION 6] Timing-Attack Verification (hash_equals usage) ---\n";

// 6.1: Static Code Inspection of BadgeService.php for hash_equals usage
$badgeServiceSource = file_get_contents(__DIR__ . '/../app/Services/BadgeService.php');

$usesHashEquals = str_contains($badgeServiceSource, 'hash_equals(');
$looseComparisonPattern = '/\$validSignature\s*=\s*(\$expected[a-zA-Z0-9_]*\s*==[^=])/';
$hasLooseComparison = (bool)preg_match($looseComparisonPattern, $badgeServiceSource);

assertAdv('ADV-TIME-01', 'BadgeService source explicitly employs hash_equals() for signature verification',
    $usesHashEquals && !$hasLooseComparison,
    "usesHashEquals: " . ($usesHashEquals ? 'true' : 'false')
);

// Confirm no loose equality ($signature == $expected) on secrets in BadgeService
$insecureSigCheck = (bool)preg_match('/\$signature\s*={2,3}\s*\$expected|\$expected\s*={2,3}\s*\$signature/', $badgeServiceSource);
assertAdv('ADV-TIME-02', 'Zero loose or strict direct string comparisons (== or ===) used for HMAC checks',
    !$insecureSigCheck
);

// 6.2: Empirical Constant-Time Comparison Benchmark
// Test hash_equals behavior against early-mismatch vs late-mismatch
$correctSig = $origSignature; // 43 chars base64url
$earlyMismatchSig = 'X' . substr($correctSig, 1);
$lateMismatchSig = substr($correctSig, 0, -1) . 'X';

$iterations = 5000;

// Benchmark early mismatch
$t0 = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    hash_equals($correctSig, $earlyMismatchSig);
}
$earlyDuration = microtime(true) - $t0;

// Benchmark late mismatch
$t0 = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    hash_equals($correctSig, $lateMismatchSig);
}
$lateDuration = microtime(true) - $t0;

// Ratio of execution times: in a constant-time comparison, the ratio should be very close to 1.0 (within noise margins)
$ratio = $earlyDuration > 0 ? $lateDuration / $earlyDuration : 1.0;
echo "    [INFO] hash_equals benchmark ({$iterations} runs): early={$earlyDuration}s, late={$lateDuration}s (ratio=" . number_format($ratio, 3) . ")\n";

assertAdv('ADV-TIME-03', 'hash_equals exhibits consistent constant-time characteristics across mismatch positions',
    $ratio > 0.5 && $ratio < 2.0 // standard noisy-system tolerance window
);

// 6.3: Behavior on length mismatch
assertAdv('ADV-TIME-04', 'hash_equals safely returns false on length mismatch without throwing errors',
    hash_equals($correctSig, substr($correctSig, 0, 10)) === false
);


// =============================================================================
// SECTION 7: END-TO-END HTTP API & REAL-TIME REVOCATION STRESS
// =============================================================================
echo "\n--- [SECTION 7] End-to-End HTTP API & Real-time Revocation Stress ---\n";

// 7.1: Legitimate volunteer badge retrieval via GET /api/volunteers/my-badge
$volToken = $harness->getVolunteerToken();
$myBadgeRes = $harness->apiCall('GET', '/api/volunteers/my-badge', null, ["Authorization: Bearer {$volToken}"]);
assertAdv('ADV-API-01', 'Verified volunteer retrieves dynamic badge via HTTP API (200 OK)',
    $myBadgeRes['code'] === 200 && !empty($myBadgeRes['data']['badge_token']),
    "HTTP {$myBadgeRes['code']}"
);

$httpBadgeToken = $myBadgeRes['data']['badge_token'] ?? '';

// 7.2: Public verification endpoint GET /api/volunteers/badge/{token}
$publicRes = $harness->apiCall('GET', "/api/volunteers/badge/{$httpBadgeToken}");
assertAdv('ADV-API-02', 'Public verification endpoint validates active token (200 OK, valid: true)',
    $publicRes['code'] === 200 && ($publicRes['data']['valid'] ?? false) === true,
    "HTTP {$publicRes['code']}"
);

// 7.3: Strict Privacy Law 99-Z compliance on public verification response
$volProfile = $publicRes['data']['volunteer'] ?? [];
$forbiddenPiiKeys = ['passport', 'passport_masked', 'phone', 'address', 'email', 'password_hash'];
$leakedKeys = [];
foreach ($forbiddenPiiKeys as $k) {
    if (isset($volProfile[$k])) {
        $leakedKeys[] = $k;
    }
}
assertAdv('ADV-API-03', 'Public verification strictly suppresses sensitive PII (Law 99-Z: no passport, phone, address)',
    empty($leakedKeys),
    "Leaked keys: " . implode(', ', $leakedKeys)
);

// 7.4: Slavic safe name formatting in public response
assertAdv('ADV-API-04', 'Public profile provides safe public name components (first_name, patronymic, last_initial)',
    !empty($volProfile['first_name']) && !empty($volProfile['last_initial'])
);

// 7.5: HTTP API rejection of tampered token
$tamperedHttpToken = $httpBadgeToken . 'adversarial_tamper';
$tamperedHttpRes = $harness->apiCall('GET', "/api/volunteers/badge/{$tamperedHttpToken}");
assertAdv('ADV-API-05', 'Public endpoint returns HTTP 400 Bad Request for tampered token',
    $tamperedHttpRes['code'] === 400 && ($tamperedHttpRes['data']['valid'] ?? true) === false,
    "HTTP {$tamperedHttpRes['code']}"
);

// 7.6: HTTP API rejection of expired token
$expiredHttpRes = $harness->apiCall('GET', "/api/volunteers/badge/{$tokenPast1}");
assertAdv('ADV-API-06', 'Public endpoint returns HTTP 400 Bad Request for expired token (TTL + 1s)',
    $expiredHttpRes['code'] === 400 && ($expiredHttpRes['data']['valid'] ?? true) === false,
    "HTTP {$expiredHttpRes['code']}"
);

// 7.7: Real-time Revocation Check on HTTP API
// Register a volunteer, verify, issue token, then revoke and verify immediate invalidation
$coordToken = $harness->getCoordinatorToken();
$candidate = $harness->registerUser(2, 'Валерий На Отзыв Аккредитации');
$candidateId = (int)$candidate['user']['id'];

// Approve candidate
$harness->apiCall('POST', "/api/coordinator/volunteers/{$candidateId}/verify", null, ["Authorization: Bearer {$coordToken}"]);

// Issue token for this candidate
$candToken = BadgeService::generateBadgeToken($candidateId, 'Валерий О.', 'ТЦСОН');

// Verify token works before revocation
$preRevoke = $harness->apiCall('GET', "/api/volunteers/badge/{$candToken}");
$preValid = ($preRevoke['code'] === 200 && ($preRevoke['data']['valid'] ?? false) === true);

// Revoke volunteer
$harness->apiCall('POST', "/api/coordinator/volunteers/{$candidateId}/reject", [
    'reason' => 'Адверсарный тест: экстренный отзыв аккредитации'
], ["Authorization: Bearer {$coordToken}"]);

// Check token after revocation: must be rejected even though HMAC is cryptographically valid!
$postRevoke = $harness->apiCall('GET', "/api/volunteers/badge/{$candToken}");
assertAdv('ADV-API-07', 'Instant revocation: coordinator-rejected volunteer badge immediately invalid on public API',
    $preValid && $postRevoke['code'] === 400 && ($postRevoke['data']['valid'] ?? true) === false,
    "Pre: {$preRevoke['code']}, Post: {$postRevoke['code']}"
);

// 7.8: Direct Badge SVG Rendering Route (GET /api/volunteers/badge/{token}/svg)
$svgRouteRes = $harness->apiCall('GET', "/api/volunteers/badge/{$httpBadgeToken}/svg");
$svgRouteXml = simplexml_load_string($svgRouteRes['raw'] ?? '');
$svgRouteEmoji = (bool)preg_match($emojiRegex, $svgRouteRes['raw'] ?? '');

assertAdv('ADV-API-08', 'GET /api/volunteers/badge/{token}/svg returns HTTP 200, valid SVG XML, and 0 emojis',
    $svgRouteRes['code'] === 200 && $svgRouteXml !== false && !$svgRouteEmoji,
    "HTTP {$svgRouteRes['code']}, validXml: " . ($svgRouteXml !== false ? 'yes' : 'no')
);

// 7.9: Direct Badge SVG Route with tampered token returns 400
$tamperedSvgRes = $harness->apiCall('GET', "/api/volunteers/badge/{$tamperedHttpToken}/svg");
assertAdv('ADV-API-09', 'GET /api/volunteers/badge/{tampered}/svg returns HTTP 400 error',
    $tamperedSvgRes['code'] === 400,
    "HTTP {$tamperedSvgRes['code']}"
);


// =============================================================================
// SUMMARY & VERDICT
// =============================================================================
$elapsed = round(microtime(true) - $startTime, 3);
echo "\n================================================================================\n";
echo "   ADVERSARIAL STRESS TEST SUMMARY\n";
echo "================================================================================\n";
echo "Total Assertions : {$totalTests}\n";
echo "Passed           : {$passedTests}\n";
echo "Failed           : {$failedTests}\n";
echo "Execution Time   : {$elapsed}s\n";
echo "Verdict          : " . ($failedTests === 0 ? "CONFIRM" : "REJECT") . "\n";
echo "================================================================================\n\n";

if ($failedTests > 0) {
    exit(1);
}
exit(0);
