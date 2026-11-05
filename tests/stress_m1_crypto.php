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
require_once __DIR__ . '/../app/Services/CryptoService.php';
require_once __DIR__ . '/../app/Models/ElderlyProfile.php';

use App\Core\Database;
use App\Services\CryptoService;
use App\Models\ElderlyProfile;

echo "====================================================================\n";
echo "   ADVERSARIAL STRESS TEST SUITE — MILESTONE 1 (CRYPTO & MIGRATION)  \n";
echo "====================================================================\n\n";

$totalAssertions = 0;
$passedAssertions = 0;
$failedAssertions = 0;
$startTime = microtime(true);

function assertStress(string $description, bool $condition, string $details = ''): void {
    global $totalAssertions, $passedAssertions, $failedAssertions;
    $totalAssertions++;
    if ($condition) {
        $passedAssertions++;
        echo "  [PASS] {$description}\n";
    } else {
        $failedAssertions++;
        echo "  [FAIL] {$description} | {$details}\n";
    }
}

// ====================================================================
// SECTION 1: AES-256-GCM IV UNIQUENESS (10,000 ENCRYPTIONS)
// ====================================================================
echo "[STRESS 1] IV Uniqueness & Entropy Stress-Test (10,000 encryptions)...\n";
$ivSet = [];
$collisionCount = 0;
$sampleMessage = "Тестовые конфиденциальные данные пациента для проверки IV";
$ivBytesHistogram = array_fill(0, 256, 0);

$t0 = microtime(true);
for ($i = 0; $i < 10000; $i++) {
    $enc = CryptoService::encrypt($sampleMessage);
    if ($enc === null || !str_starts_with($enc, 'enc:v1:')) {
        $collisionCount++;
        continue;
    }
    $raw = base64_decode(substr($enc, strlen('enc:v1:')), true);
    if ($raw === false || strlen($raw) < 28) {
        $collisionCount++;
        continue;
    }
    // IV is first 12 bytes
    $iv = substr($raw, 0, 12);
    if (isset($ivSet[$iv])) {
        $collisionCount++;
    }
    $ivSet[$iv] = true;

    // Track byte histogram for entropy sanity
    for ($j = 0; $j < 12; $j++) {
        $ivBytesHistogram[ord($iv[$j])]++;
    }
}
$t1 = microtime(true);
$ivDuration = round(($t1 - $t0) * 1000, 2);

$uniqueIvCount = count($ivSet);
$zeroFrequencyBytes = 0;
foreach ($ivBytesHistogram as $byteVal => $freq) {
    if ($freq === 0) {
        $zeroFrequencyBytes++;
    }
}

assertStress("10,000 IV generations completed in {$ivDuration} ms", $uniqueIvCount === 10000, "Unique count: {$uniqueIvCount}");
assertStress("Zero IV collisions detected (0 in 10,000 samples)", $collisionCount === 0, "Collisions: {$collisionCount}");
assertStress("Full 8-bit byte range coverage in IV entropy (all 256 byte values present in 120,000 sampled bytes)", $zeroFrequencyBytes === 0, "Zero freq count: {$zeroFrequencyBytes}");

// ====================================================================
// SECTION 2: BIT-FLIP TAMPERING (AUTHENTICATION TAG & CIPHERTEXT)
// ====================================================================
echo "\n[STRESS 2] Bit-Flip Tampering Attack Harness...\n";

$originalSecret = "Пациент Ковалева А.И.: постельный режим, гипертония III ст., аллергия на пенициллин!";
$encryptedPayload = CryptoService::encrypt($originalSecret);
$rawPayload = base64_decode(substr($encryptedPayload, strlen('enc:v1:')), true);

$iv = substr($rawPayload, 0, 12);
$tag = substr($rawPayload, 12, 16);
$ciphertext = substr($rawPayload, 28);

// 2.1 Bit flips across ALL 128 bits of the authentication tag
$tagBitFlipAttempts = 0;
$tagBitFlipRejections = 0;
for ($byteIdx = 0; $byteIdx < 16; $byteIdx++) {
    for ($bit = 0; $bit < 8; $bit++) {
        $tamperedTag = $tag;
        $tamperedTag[$byteIdx] = chr(ord($tamperedTag[$byteIdx]) ^ (1 << $bit));

        $tamperedBinary = $iv . $tamperedTag . $ciphertext;
        $tamperedWire = 'enc:v1:' . base64_encode($tamperedBinary);

        $tagBitFlipAttempts++;
        try {
            CryptoService::decrypt($tamperedWire);
            // If decrypt succeeds, that is a cryptographic failure!
        } catch (\RuntimeException $e) {
            $tagBitFlipRejections++;
        }
    }
}
assertStress("Tag bit-flip tampering: 100% rejection across all 128 bits of authentication tag", 
    $tagBitFlipAttempts === 128 && $tagBitFlipRejections === 128, 
    "Attempts: {$tagBitFlipAttempts}, Rejections: {$tagBitFlipRejections}"
);

// 2.2 Bit flips across ALL bits of ciphertext
$ctLength = strlen($ciphertext);
$ctBitFlipAttempts = 0;
$ctBitFlipRejections = 0;

for ($byteIdx = 0; $byteIdx < $ctLength; $byteIdx++) {
    for ($bit = 0; $bit < 8; $bit++) {
        $tamperedCt = $ciphertext;
        $tamperedCt[$byteIdx] = chr(ord($tamperedCt[$byteIdx]) ^ (1 << $bit));

        $tamperedBinary = $iv . $tag . $tamperedCt;
        $tamperedWire = 'enc:v1:' . base64_encode($tamperedBinary);

        $ctBitFlipAttempts++;
        try {
            CryptoService::decrypt($tamperedWire);
        } catch (\RuntimeException $e) {
            $ctBitFlipRejections++;
        }
    }
}
assertStress("Ciphertext bit-flip tampering: 100% rejection across all {$ctBitFlipAttempts} bits of ciphertext", 
    $ctBitFlipAttempts === ($ctLength * 8) && $ctBitFlipRejections === $ctBitFlipAttempts, 
    "Attempts: {$ctBitFlipAttempts}, Rejections: {$ctBitFlipRejections}"
);

// 2.3 Bit flips across ALL 96 bits of IV
$ivBitFlipAttempts = 0;
$ivBitFlipRejections = 0;
for ($byteIdx = 0; $byteIdx < 12; $byteIdx++) {
    for ($bit = 0; $bit < 8; $bit++) {
        $tamperedIv = $iv;
        $tamperedIv[$byteIdx] = chr(ord($tamperedIv[$byteIdx]) ^ (1 << $bit));

        $tamperedBinary = $tamperedIv . $tag . $ciphertext;
        $tamperedWire = 'enc:v1:' . base64_encode($tamperedBinary);

        $ivBitFlipAttempts++;
        try {
            CryptoService::decrypt($tamperedWire);
        } catch (\RuntimeException $e) {
            $ivBitFlipRejections++;
        }
    }
}
assertStress("IV bit-flip tampering: 100% rejection across all 96 bits of IV", 
    $ivBitFlipAttempts === 96 && $ivBitFlipRejections === 96, 
    "Attempts: {$ivBitFlipAttempts}, Rejections: {$ivBitFlipRejections}"
);

// 2.4 Truncation, payload corruption & malformed data
$corruptedPayloads = [
    'Empty payload after prefix' => 'enc:v1:',
    'Truncated below IV+TAG (5 bytes)' => 'enc:v1:' . base64_encode('abcde'),
    'Truncated at exactly IV (12 bytes)' => 'enc:v1:' . base64_encode(str_repeat('A', 12)),
    'Truncated at IV+TAG-1 (27 bytes)' => 'enc:v1:' . base64_encode(str_repeat('B', 27)),
    'Invalid base64 payload' => 'enc:v1:!@#$%^&*()_+',
    'Extra garbage suffix' => $encryptedPayload . '===corrupted',
];

$malformedRejections = 0;
foreach ($corruptedPayloads as $desc => $malformedWire) {
    try {
        CryptoService::decrypt($malformedWire);
    } catch (\RuntimeException $e) {
        $malformedRejections++;
    }
}
assertStress("Malformed & truncated payload resistance (all 6 rejected)", 
    $malformedRejections === count($corruptedPayloads), 
    "Rejections: {$malformedRejections}/" . count($corruptedPayloads)
);

// 2.5 AAD (Additional Authenticated Data) stress
$aad1 = "patient_id:42;scope:mobility_notes";
$aad2 = "patient_id:43;scope:mobility_notes";
$encWithAad = CryptoService::encrypt("Диагноз: артрит коленного сустава", $aad1);
$aadMatchPass = false;
$aadTamperFail = false;
$aadEmptyFail = false;

try {
    $decSameAad = CryptoService::decrypt($encWithAad, $aad1);
    $aadMatchPass = ($decSameAad === "Диагноз: артрит коленного сустава");
} catch (\Throwable $e) {}

try {
    CryptoService::decrypt($encWithAad, $aad2);
} catch (\RuntimeException $e) {
    $aadTamperFail = true;
}

try {
    CryptoService::decrypt($encWithAad, "");
} catch (\RuntimeException $e) {
    $aadEmptyFail = true;
}

assertStress("AAD matching succeeds correctly", $aadMatchPass);
assertStress("Tampered AAD causes authenticated decryption rejection", $aadTamperFail);
assertStress("Missing AAD on authenticated payload causes rejection", $aadEmptyFail);

// ====================================================================
// SECTION 3: EDGE CASES & ENCODINGS
// ====================================================================
echo "\n[STRESS 3] Edge Cases: Nulls, Empty Strings, Belarusian UTF-8, Large Payloads...\n";

// 3.1 Null and empty values
$nullEnc = CryptoService::encrypt(null);
$nullDec = CryptoService::decrypt(null);
assertStress("Null plaintext encrypts to null", $nullEnc === null);
assertStress("Null ciphertext decrypts to null", $nullDec === null);

$emptyEnc = CryptoService::encrypt("");
$emptyDec = CryptoService::decrypt("");
assertStress("Empty string encrypts to empty string", $emptyEnc === "");
assertStress("Empty string decrypts to empty string", $emptyDec === "");

// 3.2 Whitespace and special non-printable bytes
$whitespace = "   \t\r\n   ";
$wsEnc = CryptoService::encrypt($whitespace);
$wsDec = CryptoService::decrypt($wsEnc);
assertStress("Whitespace preserves exact characters", $wsDec === $whitespace);

$binaryData = "\x00\x01\x02\xFF\xFE\x00\xAA\x55";
$binEnc = CryptoService::encrypt($binaryData);
$binDec = CryptoService::decrypt($binEnc);
assertStress("Embedded null bytes and binary strings preserve exact bytes", $binDec === $binaryData);

// 3.3 Multi-byte UTF-8 & Belarusian specific letters
$belarusianText = "У Іўі хутка пачнецца свята. Беларускія літары: ў, і, ё, Ы, Э, апостраф ’ (напрыклад: з’ява, аб’ект, пад’езд, сям’я). Патрабуецца дапамога ветэрану працы.";
$byEnc = CryptoService::encrypt($belarusianText);
$byDec = CryptoService::decrypt($byEnc);
assertStress("Belarusian letters (ў, і, ё, Ы, Э, апостраф ’) round-trip identically", 
    $byDec === $belarusianText, 
    "Decrypted: " . mb_substr($byDec ?? '', 0, 40)
);

// 3.4 Multi-lingual strings & emojis (UTF-8 4-byte astral plane)
$multiLingual = "Мінск / Минск / Minsk \u{1F3E5} \u{1F48A} \u{1FA7A} \u{1F475} \u{1F474} \u{2764}\u{FE0F} Спасибо за заботу! 100% надзейнасць.";
$mlEnc = CryptoService::encrypt($multiLingual);
$mlDec = CryptoService::decrypt($mlEnc);
assertStress("Multi-lingual and 4-byte astral UTF-8 characters round-trip identically", $mlDec === $multiLingual);

// 3.5 Double-encryption idempotency check
$doubleEnc = CryptoService::encrypt($byEnc);
assertStress("Double encryption is prevented (idempotent prefix check)", $doubleEnc === $byEnc);
$doubleDec = CryptoService::decrypt($doubleEnc);
assertStress("Decryption of idempotent ciphertext restores original plaintext", $doubleDec === $belarusianText);

// 3.6 Very large strings (100 KB and 256 KB)
echo "  [INFO] Generating 100 KB and 256 KB payloads for throughput & memory stress...\n";
$chunk100k = str_repeat("ТЦСОН Советского района г. Минска: регулярный патронаж и медицинский уход. ", 1200); // ~100 KB
$payload100k = mb_substr($chunk100k, 0, 102400); // exactly 102,400 chars
$size100k = strlen($payload100k);

$tLarge0 = microtime(true);
$enc100k = CryptoService::encrypt($payload100k);
$dec100k = CryptoService::decrypt($enc100k);
$tLarge1 = microtime(true);
$duration100k = round(($tLarge1 - $tLarge0) * 1000, 2);

assertStress("100 KB payload ({$size100k} bytes) encrypts & decrypts successfully in {$duration100k} ms", 
    $dec100k === $payload100k && hash('sha256', $dec100k) === hash('sha256', $payload100k)
);

$chunk256k = str_repeat("Аб'яднаныя намаганні валанцёраў і сацыяльных работнікаў. ", 3500);
$payload256k = mb_substr($chunk256k, 0, 262144);
$size256k = strlen($payload256k);
$enc256k = CryptoService::encrypt($payload256k);
$dec256k = CryptoService::decrypt($enc256k);
assertStress("256 KB payload ({$size256k} bytes) encrypts & decrypts with exact SHA-256 match", 
    $dec256k === $payload256k && hash('sha256', $dec256k) === hash('sha256', $payload256k)
);

// ====================================================================
// SECTION 4: MIGRATION STRESS TEST (5 RAPID SUCCESSIVE EXECUTIONS)
// ====================================================================
echo "\n[STRESS 4] Database Migration Rapid Idempotency Stress (5 successive runs)...\n";

$migrateScript = __DIR__ . '/../database/migrate.php';
$migrationSuccessCount = 0;
$migrationErrors = [];

for ($run = 1; $run <= 5; $run++) {
    $cmd = 'php ' . escapeshellarg($migrateScript) . ' 2>&1';
    $outputLines = [];
    $exitCode = 0;
    exec($cmd, $outputLines, $exitCode);
    $output = implode("\n", $outputLines);

    $hasSqlError = (
        stripos($output, 'duplicate column') !== false ||
        stripos($output, 'SQLSTATE') !== false ||
        stripos($output, 'Fatal error') !== false ||
        stripos($output, 'Exception') !== false ||
        stripos($output, 'Failed:') !== false
    );

    if ($exitCode === 0 && !$hasSqlError && stripos($output, 'Database is up to date') !== false) {
        $migrationSuccessCount++;
    } else {
        $migrationErrors[] = "Run {$run}: ExitCode={$exitCode}, Output={$output}";
    }
}

assertStress("Migration script executes 5 times in rapid succession with exit code 0", 
    $migrationSuccessCount === 5, 
    implode("; ", $migrationErrors)
);
assertStress("Zero duplicate column or SQLite DDL errors observed during rapid migrations", 
    count($migrationErrors) === 0, 
    implode("; ", $migrationErrors)
);

// 4.2 SQLite database integrity check
$pdo = Database::getConnection();
$integrityStmt = $pdo->query("PRAGMA integrity_check;");
$integrityResult = $integrityStmt ? $integrityStmt->fetchColumn() : 'failed';
assertStress("SQLite database passes PRAGMA integrity_check with 'ok'", $integrityResult === 'ok', "Result: {$integrityResult}");

// 4.3 Verify data consistency after 5 migrations
$stmtProfile = $pdo->query("SELECT user_id, mobility_notes FROM elderly_profiles WHERE user_id = 1;");
$rowProfile = $stmtProfile ? $stmtProfile->fetch(PDO::FETCH_ASSOC) : null;
$profileNotesEncrypted = ($rowProfile && CryptoService::isEncrypted($rowProfile['mobility_notes']));
$decryptedProfileNotes = $profileNotesEncrypted ? CryptoService::decrypt($rowProfile['mobility_notes']) : null;

assertStress("Elderly profile #1 mobility_notes remains validly encrypted after repeated migrations", $profileNotesEncrypted);
assertStress("Elderly profile #1 mobility_notes decrypts to valid human-readable note", 
    !empty($decryptedProfileNotes) && is_string($decryptedProfileNotes) && str_contains($decryptedProfileNotes, 'Трудно')
);

// ====================================================================
// SUMMARY & TIMING
// ====================================================================
$totalDuration = round((microtime(true) - $startTime) * 1000, 2);

echo "\n--------------------------------------------------------------------\n";
echo "STRESS TEST AUDIT SUMMARY:\n";
echo "  Total Assertions: {$totalAssertions}\n";
echo "  Passed:           {$passedAssertions}\n";
echo "  Failed:           {$failedAssertions}\n";
echo "  Total Duration:   {$totalDuration} ms\n";
echo "--------------------------------------------------------------------\n";

if ($failedAssertions === 0) {
    echo "\n>>> VERDICT: CONFIRM (All cryptographic & migration stress tests PASSED)\n";
    exit(0);
} else {
    echo "\n>>> VERDICT: REJECT ({$failedAssertions} stress test assertion(s) FAILED)\n";
    exit(1);
}
