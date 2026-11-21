<?php
declare(strict_types=1);











$rootDir = dirname(__DIR__);
$passCount = 0;
$failCount = 0;
$defectDetails = [];

function recordResult(string $suite, string $testName, bool $passed, string $details = ''): void {
    global $passCount, $failCount, $defectDetails;
    if ($passed) {
        $passCount++;
        echo "  \033[32m[PASS]\033[0m {$testName}" . ($details !== '' ? " :: {$details}" : "") . PHP_EOL;
    } else {
        $failCount++;
        $defectDetails[] = "[{$suite}] {$testName}: {$details}";
        echo "  \033[31m[FAIL]\033[0m {$testName}" . ($details !== '' ? " :: {$details}" : "") . PHP_EOL;
    }
}

echo "================================================================================" . PHP_EOL;
echo "  CHALLENGER M4-2: ADVERSARIAL UI WORKFLOWS & PLAN INTEGRITY SUITE              " . PHP_EOL;
echo "================================================================================" . PHP_EOL;


$appJsPath = $rootDir . '/public/js/app.js';
$speechJsPath = $rootDir . '/public/js/speech.js';
$indexPath = $rootDir . '/public/index.php';
$verifyPath = $rootDir . '/public/verify.php';
$cssPath = $rootDir . '/public/css/accessibility.css';

$appJs = file_exists($appJsPath) ? file_get_contents($appJsPath) : '';
$speechJs = file_exists($speechJsPath) ? file_get_contents($speechJsPath) : '';
$indexHtml = file_exists($indexPath) ? file_get_contents($indexPath) : '';
$verifyHtml = file_exists($verifyPath) ? file_get_contents($verifyPath) : '';
$cssContent = file_exists($cssPath) ? file_get_contents($cssPath) : '';




echo PHP_EOL . "--- [PART 1] Web Audio API Siren & Voice Interception Logic ---" . PHP_EOL;


$hasAudioContext = strpos($appJs, 'AudioContext') !== false || strpos($appJs, 'webkitAudioContext') !== false;
recordResult(
    'AUDIO-SIREN',
    'AUD-01: AudioContext initialization present in app.js',
    $hasAudioContext,
    'AudioContext / webkitAudioContext fallback implemented'
);


$initialFreqMatch = preg_match('/setValueAtTime\s*\(\s*(\d+)\s*,\s*sirenAudioCtx\.currentTime\s*\)/', $appJs, $freqM);
$initialFreq = $initialFreqMatch ? (int)$freqM[1] : 0;
$freqSweepMatch = preg_match('/targetFreq\s*=\s*highFreq\s*\?\s*(\d+)\s*:\s*(\d+)/', $appJs, $sweepM);
$lowFreq = $freqSweepMatch ? (int)$sweepM[1] : 0;
$highFreq = $freqSweepMatch ? (int)$sweepM[2] : 0;

$frequenciesValid = ($initialFreq >= 600 && $initialFreq <= 900)
    && ($lowFreq >= 600 && $lowFreq <= 900)
    && ($highFreq >= 600 && $highFreq <= 900)
    && ($lowFreq === 600 && $highFreq === 900);

recordResult(
    'AUDIO-SIREN',
    'AUD-02: Siren sweeps frequencies strictly within [600, 900] Hz',
    $frequenciesValid,
    sprintf("Base: %d Hz, Alternating: [%d, %d] Hz", $initialFreq, $lowFreq, $highFreq)
);


$gainMatch = preg_match('/sirenGainNode\.gain\.setValueAtTime\s*\(\s*([0-9.]+)\s*,/', $appJs, $gainM);
$initialGain = $gainMatch ? (float)$gainM[1] : 999.0;
recordResult(
    'AUDIO-SIREN',
    'AUD-03: GainNode volume envelope <= 1.0 (clipping & hearing safety)',
    $initialGain > 0.0 && $initialGain <= 1.0,
    sprintf("Configured gain: %.3f (Safe threshold <= 1.0, recommended <= 0.3)", $initialGain)
);


$rampMatch = preg_match('/sirenGainNode\.gain\.linearRampToValueAtTime\s*\(\s*([0-9.]+)\s*,/', $appJs, $rampM);
$fadeGain = $rampMatch ? (float)$rampM[1] : 1.0;
recordResult(
    'AUDIO-SIREN',
    'AUD-04: Siren stop gracefully ramps down volume (anti-pop)',
    $fadeGain <= 0.01,
    sprintf("Fade-out target gain: %.4f", $fadeGain)
);


$hasResumeCheck = strpos($appJs, "sirenAudioCtx.state === 'suspended'") !== false
    && strpos($appJs, "sirenAudioCtx.resume()") !== false;
recordResult(
    'AUDIO-SIREN',
    'AUD-05: User-gesture auto-unlocking implemented (state === suspended -> resume)',
    $hasResumeCheck,
    'AudioContext checks suspended state and triggers resume'
);


$hasMuteCheck = strpos($appJs, 'isSirenMuted') !== false
    && strpos($appJs, 'toggleSirenMute') !== false
    && strpos($appJs, 'if (isSirenMuted) return') !== false;
recordResult(
    'AUDIO-SIREN',
    'AUD-06: Mute toggle suppresses audio oscillator playback',
    $hasMuteCheck,
    'isSirenMuted state guard active in playSiren and toggleSirenMute'
);


$hasVoiceFn = strpos($speechJs, 'checkEmergencyVoiceCommand') !== false;
recordResult(
    'VOICE-INTERCEPT',
    'VOC-01: checkEmergencyVoiceCommand function exists in speech.js',
    $hasVoiceFn,
    'Voice emergency listener hook declared'
);


preg_match('/const\s+emergencyRegex\s*=\s*\/(.*?)\/([a-z]*);/', $speechJs, $regexMatches);
$voicePattern = $regexMatches[1] ?? '';
$voiceFlags = $regexMatches[2] ?? '';


$kwPomosh = stripos($voicePattern, 'помощь') !== false;
$kwTrevo = stripos($voicePattern, 'тревога') !== false;
$kwSos = stripos($voicePattern, 'sos') !== false;
$kwSpasite = stripos($voicePattern, 'спасите') !== false;

recordResult(
    'VOICE-INTERCEPT',
    'VOC-02: Emergency voice regex pattern contains all 4 mandatory keywords (Помощь, Тревога, SOS, Спасите)',
    $kwPomosh && $kwTrevo && $kwSos && $kwSpasite,
    sprintf("Pattern: /%s/%s (Помощь:%d, Тревога:%d, SOS:%d, Спасите:%d)", $voicePattern, $voiceFlags, $kwPomosh, $kwTrevo, $kwSos, $kwSpasite)
);



$testPhrases = ['Помощь', 'Тревога', 'SOS', 'Спасите', 'Мне нужна помощь срочно', 'Спасите помогите'];
$pcreMatchedAll = true;
foreach ($testPhrases as $phrase) {
    if (!preg_match('/' . $voicePattern . '/u' . str_replace('u', '', $voiceFlags), mb_strtolower($phrase, 'UTF-8'))) {
        $pcreMatchedAll = false;
        break;
    }
}
recordResult(
    'VOICE-INTERCEPT',
    'VOC-03: PCRE Unicode engine matches Russian emergency phrases',
    $pcreMatchedAll,
    'All test phrases matched under PCRE /u rules'
);





$hasAsciiBoundaryOnCyrillic = str_contains($voicePattern, '\b') && (
    str_starts_with($voicePattern, '\b') ||
    str_ends_with($voicePattern, '\b') ||
    preg_match('/\\\\b[а-яёА-ЯЁ]/u', $voicePattern) ||
    preg_match('/[а-яёА-ЯЁ]\\\\b/u', $voicePattern)
);


$negativePhrases = ['беспомощный', 'помощник', 'помощница', 'беспомощность'];
$negativeRejectedAll = true;
foreach ($negativePhrases as $neg) {
    if (preg_match('/' . $voicePattern . '/u' . str_replace('u', '', $voiceFlags), mb_strtolower($neg, 'UTF-8'))) {
        $negativeRejectedAll = false;
        break;
    }
}

$voc04Passed = !$hasAsciiBoundaryOnCyrillic && $pcreMatchedAll && $negativeRejectedAll;

recordResult(
    'VOICE-INTERCEPT',
    'VOC-04: JavaScript RegExp uses Unicode-safe word boundaries for Cyrillic keywords',
    $voc04Passed,
    $voc04Passed
        ? 'Pattern avoids ASCII-only \b on Cyrillic keywords and rejects false positives'
        : ($hasAsciiBoundaryOnCyrillic
            ? 'ASCII-only \b boundary detected on Cyrillic in pattern'
            : 'Negative keyword rejection or PCRE matching failed')
);




echo PHP_EOL . "--- [PART 2] Volunteer Geofenced Modal & Distance Telemetry ---" . PHP_EOL;


$domGeoModal = strpos($indexHtml, 'id="geo-complete-modal"') !== false;
$domGeoReqId = strpos($indexHtml, 'id="geoCompleteRequestId"') !== false;
$domGeoSecret = strpos($indexHtml, 'id="geoSecretWordInput"') !== false;
$domGeoCoords = strpos($indexHtml, 'id="geoCoordinatesDisplay"') !== false;
$domGeoErrorBox = strpos($indexHtml, 'id="geoDistanceErrorBox"') !== false;
$domGeoErrorText = strpos($indexHtml, 'id="geoDistanceErrorText"') !== false;
$domGeoConfirmBtn = strpos($indexHtml, 'id="confirmGeoCompleteBtn"') !== false;

recordResult(
    'GEOFENCE-UI',
    'GEO-01: Geofenced completion modal DOM elements present in index.php',
    $domGeoModal && $domGeoReqId && $domGeoSecret && $domGeoCoords && $domGeoErrorBox && $domGeoErrorText && $domGeoConfirmBtn,
    'Modal, inputs, telemetry display, and error boxes confirmed'
);


$hasOpenModal = strpos($appJs, 'function openCompleteModal') !== false;
$populatesId = strpos($appJs, 'reqIdInput.value = id') !== false;
$queriesGeo = strpos($appJs, 'navigator.geolocation.getCurrentPosition') !== false;

recordResult(
    'GEOFENCE-UI',
    'GEO-02: openCompleteModal populates request ID and queries navigator.geolocation',
    $hasOpenModal && $populatesId && $queriesGeo,
    'Request ID binding and live GPS query verified in app.js'
);


$validatesSecret = strpos($appJs, '!secretCode') !== false
    && strpos($appJs, 'errorBox.style.display = \'block\'') !== false;

recordResult(
    'GEOFENCE-UI',
    'GEO-03: submitGeoComplete enforces client-side non-empty secret word validation',
    $validatesSecret,
    'Rejects empty secret code before dispatching to server'
);


$hasDistanceFeedback = strpos($appJs, 'Превышение радиуса геозоны (до 150 м)') !== false
    && strpos($appJs, 'errorText') !== false;

recordResult(
    'GEOFENCE-UI',
    'GEO-04: Out-of-range (>150m) feedback displays distance in meters and friendly warning',
    $hasDistanceFeedback,
    'Displays Russian error with distance and prompts volunteer to approach residence'
);


require_once $rootDir . '/app/Core/Database.php';
$pdo = App\Core\Database::getConnection();


$pdo->exec("DELETE FROM help_requests WHERE title LIKE 'CHALLENGER_M4_TEST%'");



$stmt = $pdo->prepare("
    INSERT INTO help_requests (elderly_id, volunteer_id, category_id, title, description, address, latitude, longitude, secret_code, status, assigned_at)
    VALUES (1, 2, 1, 'CHALLENGER_M4_TEST_GEOFENCE', 'Test request for geofence verification', 'Минск, ул. Якуба Коласа, 28', 53.9168, 27.5856, 'ромашка', 'in_progress', :assigned_at)
");
$stmt->execute(['assigned_at' => date('Y-m-d H:i:s')]);
$testRequestId = (int)$pdo->lastInsertId();

require_once $rootDir . '/app/Services/GeoMatchingService.php';


$geoService = new App\Services\GeoMatchingService();
$distMeters = $geoService->calculateDistanceMeters(53.9168, 27.5856, 53.9280, 27.5990);

recordResult(
    'GEOFENCE-API',
    'GEO-05: Haversine distance correctly computed for out-of-range coordinates',
    $distMeters > 150.0,
    sprintf("Computed distance: %.2f m (Threshold: 150.0 m)", $distMeters)
);



$ch = curl_init("http://127.0.0.1:8000/api/requests/{$testRequestId}/complete");

$loginCh = curl_init("http://127.0.0.1:8000/api/auth/login");
curl_setopt($loginCh, CURLOPT_RETURNTRANSFER, true);
curl_setopt($loginCh, CURLOPT_POST, true);
curl_setopt($loginCh, CURLOPT_POSTFIELDS, json_encode(['phone' => '+375292223344', 'password' => 'password123']));
curl_setopt($loginCh, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$loginResp = json_decode((string)curl_exec($loginCh), true);
curl_close($loginCh);
$volToken = $loginResp['token'] ?? '';


curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'secret_code' => 'ромашка',
    'latitude' => 53.9280,
    'longitude' => 27.5990
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    "Authorization: Bearer {$volToken}"
]);
$completeRespRaw = (string)curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$completeResp = json_decode($completeRespRaw, true);

$rejectedOutOfRange = ($httpCode === 400)
    && isset($completeResp['error'])
    && (strpos($completeResp['error'], 'вне допустимой зоны') !== false || strpos($completeResp['error'], 'расстояние') !== false);

recordResult(
    'GEOFENCE-API',
    'GEO-06: Server rejects completion when volunteer is out-of-range (>150m)',
    $rejectedOutOfRange,
    sprintf("HTTP %d :: %s", $httpCode, $completeResp['error'] ?? 'No error')
);


$checkStmt = $pdo->prepare("SELECT status FROM help_requests WHERE id = ?");
$checkStmt->execute([$testRequestId]);
$currentStatus = $checkStmt->fetchColumn();
recordResult(
    'GEOFENCE-API',
    'GEO-07: Out-of-range request remains in_progress (fraud prevention)',
    $currentStatus === 'in_progress',
    "Current status in DB: {$currentStatus}"
);


$pdo->exec("DELETE FROM help_requests WHERE id = {$testRequestId}");




echo PHP_EOL . "--- [PART 3] Volunteer Dynamic QR Badge Modal ---" . PHP_EOL;


$domBadgeModal = strpos($indexHtml, 'id="volunteer-badge-modal"') !== false;
$domBadgeCardContainer = strpos($indexHtml, 'id="badgeQrCardContainer"') !== false;
$domBadgeTimer = strpos($indexHtml, 'id="badgeCountdownTimer"') !== false;
$domRefreshBtn = strpos($indexHtml, 'id="refreshBadgeBtn"') !== false;
$domPublicUrlBtn = strpos($indexHtml, 'id="btnBadgePublicUrl"') !== false;

recordResult(
    'BADGE-MODAL',
    'BDG-01: Volunteer badge modal DOM structure in index.php',
    $domBadgeModal && $domBadgeCardContainer && $domBadgeTimer && $domRefreshBtn && $domPublicUrlBtn,
    'Modal, card container, countdown timer, refresh button, public status link confirmed'
);


$hasCountdownFn = strpos($appJs, 'function startBadgeCountdown') !== false;
$hasTtlCalc = strpos($appJs, 'expiresAtTimestamp - now') !== false;
$hasPadFormat = strpos($appJs, "padStart(2, '0')") !== false;
$hasExpiredState = strpos($appJs, '00:00 (Истёк)') !== false;

recordResult(
    'BADGE-MODAL',
    'BDG-02: startBadgeCountdown computes remaining seconds from TTL and formats MM:SS',
    $hasCountdownFn && $hasTtlCalc && $hasPadFormat && $hasExpiredState,
    'Timer calculates remaining seconds, formats MM:SS, and switches to expired state'
);


function formatBadgeCountdown(int $remaining): string {
    $remaining = max(0, $remaining);
    $mins = (int)floor($remaining / 60);
    $secs = $remaining % 60;
    return sprintf('%02d:%02d', $mins, $secs);
}

$testFormat3600 = formatBadgeCountdown(3600);
$testFormat3599 = formatBadgeCountdown(3599);
$testFormat65   = formatBadgeCountdown(65);
$testFormat0    = formatBadgeCountdown(0);

$mathCorrect = ($testFormat3600 === '60:00')
    && ($testFormat3599 === '59:59')
    && ($testFormat65 === '01:05')
    && ($testFormat0 === '00:00');

recordResult(
    'BADGE-MODAL',
    'BDG-03: Countdown formatting math produces exact 2-digit zero-padded MM:SS strings',
    $mathCorrect,
    "3600s -> {$testFormat3600}, 3599s -> {$testFormat3599}, 65s -> {$testFormat65}, 0s -> {$testFormat0}"
);


$hasVerifyWarningBanner = strpos($verifyHtml, 'alert-banner alert-banner-danger') !== false
    && strpos($verifyHtml, 'Не впускайте человека в жилое помещение') !== false;

recordResult(
    'BADGE-MODAL',
    'BDG-04: verify.php contains high-contrast warning banner for expired / invalid badge',
    $hasVerifyWarningBanner,
    'Renders alert-banner-danger with urgent instructions for elderly citizen'
);


$refreshTriggersFetch = strpos($appJs, 'refreshBadgeBtn') !== false || strpos($indexHtml, 'id="refreshBadgeBtn"') !== false;
$showBadgeFetches = strpos($appJs, "authFetch('/api/volunteers/my-badge')") !== false;

recordResult(
    'BADGE-MODAL',
    'BDG-05: Refresh button dispatches fresh dynamic token request to /api/volunteers/my-badge',
    $refreshTriggersFetch && $showBadgeFetches,
    'Button triggers showVolunteerBadge() which re-fetches dynamic badge and restarts timer'
);


$badgeCh = curl_init("http://127.0.0.1:8000/api/volunteers/my-badge");
curl_setopt($badgeCh, CURLOPT_RETURNTRANSFER, true);
curl_setopt($badgeCh, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$volToken}"]);
$badgeResp = json_decode((string)curl_exec($badgeCh), true);
curl_close($badgeCh);

$badgeSuccess = ($badgeResp['success'] ?? false) === true;
$badgeToken = $badgeResp['badge_token'] ?? '';
$expiresAt = $badgeResp['expires_at'] ?? 0;
$ttlSeconds = $expiresAt - time();

recordResult(
    'BADGE-API',
    'BDG-06: /api/volunteers/my-badge returns valid token with 1-hour TTL (3600s)',
    $badgeSuccess && strlen($badgeToken) > 30 && $ttlSeconds >= 3500 && $ttlSeconds <= 3600,
    sprintf("Token issued with TTL: %d seconds (Expected ~3600s)", $ttlSeconds)
);


$publicBadgeCh = curl_init("http://127.0.0.1:8000/api/volunteers/badge/{$badgeToken}");
curl_setopt($publicBadgeCh, CURLOPT_RETURNTRANSFER, true);
$publicResp = json_decode((string)curl_exec($publicBadgeCh), true);
curl_close($publicBadgeCh);

$publicValid = ($publicResp['valid'] ?? false) === true;
recordResult(
    'BADGE-API',
    'BDG-07: Public badge endpoint validates newly issued dynamic token',
    $publicValid,
    "Public validation response valid = " . ($publicValid ? 'true' : 'false')
);


$tamperedToken = $badgeToken . 'tampered';
$tamperCh = curl_init("http://127.0.0.1:8000/api/volunteers/badge/{$tamperedToken}");
curl_setopt($tamperCh, CURLOPT_RETURNTRANSFER, true);
$tamperResp = json_decode((string)curl_exec($tamperCh), true);
$tamperCode = (int)curl_getinfo($tamperCh, CURLINFO_HTTP_CODE);
curl_close($tamperCh);

$tamperRejected = ($tamperCode === 400 || $tamperCode === 404) || (($tamperResp['valid'] ?? true) === false);
recordResult(
    'BADGE-API',
    'BDG-08: Tampered token signature is rejected by public verification',
    $tamperRejected,
    sprintf("HTTP %d :: valid = %s", $tamperCode, var_export($tamperResp['valid'] ?? null, true))
);




echo PHP_EOL . "--- [PART 4] Architecture Decision Records Integrity (docs/adr/*.md) ---" . PHP_EOL;

$adrFiles = [
    'README.md' => ['min_size' => 500, 'keywords' => ['ADR-0001', 'ADR-0014', 'Стек']],
    '0001-php82-custom-mvc.md' => ['min_size' => 500, 'keywords' => ['PHP 8.2', 'MVC', 'Composer']],
    '0002-vanilla-js-frontend-wcag.md' => ['min_size' => 500, 'keywords' => ['Vanilla', 'WCAG', 'CSS']],
    '0003-database-sqlite-mysql.md' => ['min_size' => 500, 'keywords' => ['SQLite', 'MySQL', 'PDO']],
    '0004-pdo-idempotent-migrations.md' => ['min_size' => 500, 'keywords' => ['PDO', 'migrate.php', 'индекс']],
    '0005-hmac-auth-and-rate-limiting.md' => ['min_size' => 500, 'keywords' => ['HMAC', 'RBAC', 'Rate']],
    '0007-aes256gcm-and-data-masking.md' => ['min_size' => 500, 'keywords' => ['99-З', 'AES-256-GCM', 'enc:v1:']],
    '0008-identity-provider-adapters.md' => ['min_size' => 500, 'keywords' => ['МСИ', 'СНИЛС', '101']],
    '0009-emergency-112-adapter.md' => ['min_size' => 500, 'keywords' => ['EMERCOM-112', '112', 'SOS']],
    '0012-custom-test-harness.md' => ['min_size' => 500, 'keywords' => ['T1', 'T2', 'run_e2e.php']],
    '0015-anti-fraud-keyword-ai-fallback.md' => ['min_size' => 500, 'keywords' => ['OpenRouter', 'fallback', 'risk_level']]
];

foreach ($adrFiles as $filename => $spec) {
    $fullPath = $rootDir . '/docs/adr/' . $filename;
    $exists = file_exists($fullPath);
    $size = $exists ? filesize($fullPath) : 0;
    $content = $exists ? (string)file_get_contents($fullPath) : '';

    recordResult(
        'ADR-SUITE',
        "ADR-EX-{$filename}: Exists and exceeds 500 bytes",
        $exists && $size >= $spec['min_size'],
        sprintf("Size: %d bytes (Threshold: %d bytes)", $size, $spec['min_size'])
    );

    foreach ($spec['keywords'] as $kw) {
        $found = mb_stripos($content, $kw, 0, 'UTF-8') !== false;
        recordResult(
            'ADR-SUITE',
            "ADR-KW-{$filename}: Contains required keyword [{$kw}]",
            $found,
            $found ? 'Found in document' : 'MISSING from document'
        );
    }
}




echo PHP_EOL . "--- [PART 5] Zero-Emoji Forensic Audit & Database State ---" . PHP_EOL;

$targetFiles = array_merge(
    [$indexPath, $verifyPath, $cssPath, $appJsPath, $speechJsPath],
    glob($rootDir . '/docs/adr/*.md') ?: []
);

$emojiPattern = '/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u';

$totalEmojiCount = 0;
foreach ($targetFiles as $file) {
    $c = file_get_contents($file);
    if (preg_match_all($emojiPattern, $c, $m)) {
        $cnt = count($m[0]);
        $totalEmojiCount += $cnt;
        echo "  [FAIL] Emoji found in " . basename($file) . ": {$cnt} occurrences" . PHP_EOL;
    }
}

recordResult(
    'ZERO-EMOJI',
    'EMJ-01: Zero Unicode emojis in all M4 frontend, CSS, JS, and ADR documents',
    $totalEmojiCount === 0,
    sprintf("Found %d emoji characters across %d files", $totalEmojiCount, count($targetFiles))
);


$driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($driver === 'sqlite') {
    $fkCheck = $pdo->query("PRAGMA foreign_key_check")->fetchAll();
    recordResult(
        'DB-INTEGRITY',
        'DB-01: SQLite foreign key integrity verified (0 violations)',
        count($fkCheck) === 0,
        sprintf("%d foreign key violations found", count($fkCheck))
    );
} else {
    $fkCount = (int)$pdo->query("
        SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
    ")->fetchColumn();
    recordResult(
        'DB-INTEGRITY',
        'DB-01: MySQL foreign key constraints are present',
        $fkCount > 0,
        sprintf("%d foreign key constraints found", $fkCount)
    );
}


$testRowsCount = (int)$pdo->query("SELECT COUNT(*) FROM help_requests WHERE title LIKE 'CHALLENGER_M4_TEST%'")->fetchColumn();
recordResult(
    'DB-INTEGRITY',
    'DB-02: Zero temporary test rows remaining in SQLite database',
    $testRowsCount === 0,
    sprintf("%d test artifacts remaining", $testRowsCount)
);




echo PHP_EOL . "================================================================================" . PHP_EOL;
echo "  TEST SUMMARY" . PHP_EOL;
echo "================================================================================" . PHP_EOL;
echo "  Total Executed Tests: " . ($passCount + $failCount) . PHP_EOL;
echo "  \033[32mPassed: {$passCount}\033[0m" . PHP_EOL;
echo "  \033[31mFailed: {$failCount}\033[0m" . PHP_EOL;

if ($failCount > 0) {
    echo PHP_EOL . "Defects / Failures Encountered:" . PHP_EOL;
    foreach ($defectDetails as $defect) {
        echo "  - {$defect}" . PHP_EOL;
    }
    echo PHP_EOL . "VERDICT: \033[31mDEFECT_FOUND\033[0m" . PHP_EOL;
} else {
    echo PHP_EOL . "VERDICT: \033[32mCONFIRM\033[0m" . PHP_EOL;
}
echo "================================================================================" . PHP_EOL;


exit($failCount > 0 ? 1 : 0);
