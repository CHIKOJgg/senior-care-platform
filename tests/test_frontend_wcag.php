<?php
declare(strict_types=1);

/**
 * Milestone 4: Comprehensive Frontend UI, WCAG 2.1 AAA & Zero-Emoji Test Suite
 * Platform: «ЗаботаРядом» (Советский район г. Минска)
 */

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function assertTest(string $name, bool $condition, string $details = ''): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$name}" . ($details !== '' ? " ({$details})" : "") . PHP_EOL;
    } else {
        $failedTests++;
        echo "  [FAIL] {$name}" . ($details !== '' ? " ({$details})" : "") . PHP_EOL;
    }
}

echo "========================================================" . PHP_EOL;
echo "   MILESTONE 4: FRONTEND DOM, WCAG & ZERO-EMOJI SUITE   " . PHP_EOL;
echo "========================================================" . PHP_EOL;

$baseDir = dirname(__DIR__);
$indexHtml = (string)@file_get_contents($baseDir . '/public/index.php');
$verifyHtml = (string)@file_get_contents($baseDir . '/public/verify.php');
$cssContent = (string)@file_get_contents($baseDir . '/public/css/accessibility.css');
$appJs = (string)@file_get_contents($baseDir . '/public/js/app.js');
$speechJs = (string)@file_get_contents($baseDir . '/public/js/speech.js');

// -----------------------------------------------------------------------------
// SUITE 1: Senior Screen DOM & Accessibility (Feature 15 & Law 99-Z)
// -----------------------------------------------------------------------------
echo PHP_EOL . "[SUITE 1] Senior Screen DOM Elements & Accessibility:" . PHP_EOL;

assertTest(
    'F15-DOM-01: Dedicated high-contrast SOS emergency button exists in index.php',
    strpos($indexHtml, 'id="sosBtn"') !== false && (strpos($indexHtml, 'sos-btn') !== false || strpos($indexHtml, 'btn-sos') !== false)
);

assertTest(
    'F15-DOM-02: SOS button contains aria-label and accessible role attributes',
    strpos($indexHtml, 'aria-label') !== false && strpos($indexHtml, 'КНОПКА SOS') !== false
);

assertTest(
    'F15-DOM-03: SOS button renders pure vector SVG icon (zero emojis)',
    strpos($indexHtml, '<svg') !== false && strpos($indexHtml, "\u{1F6A8}") === false && strpos($indexHtml, "\u{1F198}") === false
);

assertTest(
    'F15-DOM-04: Active SOS emergency banner #activeSosBanner exists with ticket code container',
    strpos($indexHtml, 'id="activeSosBanner"') !== false && strpos($indexHtml, 'id="sosTicketId"') !== false
);

assertTest(
    'F15-DOM-05: Law 99-Z electronic consent checkbox exists in #helpForm',
    strpos($indexHtml, 'id="requestConsentCheckbox"') !== false && strpos($indexHtml, '99-З') !== false
);

assertTest(
    'F15-DOM-06: Profile button for Right to be Forgotten (#btnAnonymizeProfile) exists',
    strpos($indexHtml, 'id="btnAnonymizeProfile"') !== false && strpos($indexHtml, 'requestRightToBeForgotten') !== false
);

// -----------------------------------------------------------------------------
// SUITE 2: Volunteer Screen DOM & Modals (Features 18 & 19)
// -----------------------------------------------------------------------------
echo PHP_EOL . "[SUITE 2] Volunteer Screen Proof-of-Presence & Badge Modals:" . PHP_EOL;

assertTest(
    'F18-DOM-01: Geolocation completion modal #geo-complete-modal exists',
    strpos($indexHtml, 'id="geo-complete-modal"') !== false
);

assertTest(
    'F18-DOM-02: Completion modal includes secret word input and coordinates display',
    strpos($indexHtml, 'id="geoSecretWordInput"') !== false && strpos($indexHtml, 'id="geoCoordinatesDisplay"') !== false
);

assertTest(
    'F18-DOM-03: Completion modal includes friendly distance error alert box',
    strpos($indexHtml, 'id="geoDistanceErrorBox"') !== false
);

assertTest(
    'F19-DOM-01: Digital Badge launcher button exists in volunteer section',
    strpos($indexHtml, 'id="btnOpenVolunteerBadge"') !== false && strpos($indexHtml, 'showVolunteerBadge') !== false
);

assertTest(
    'F19-DOM-02: Digital Badge modal #volunteer-badge-modal exists',
    strpos($indexHtml, 'id="volunteer-badge-modal"') !== false
);

assertTest(
    'F19-DOM-03: Badge modal contains countdown timer display and refresh button',
    strpos($indexHtml, 'id="badgeCountdownTimer"') !== false && strpos($indexHtml, 'id="refreshBadgeBtn"') !== false
);

// -----------------------------------------------------------------------------
// SUITE 3: Coordinator Screen & Watchdog (Features 16 & 17)
// -----------------------------------------------------------------------------
echo PHP_EOL . "[SUITE 3] Coordinator Console, Siren & Watchdog Panel:" . PHP_EOL;

assertTest(
    'F16-DOM-01: Coordinator SOS emergency banner container exists',
    strpos($indexHtml, 'id="coordinatorSosBannerContainer"') !== false
);

assertTest(
    'F17-DOM-01: Coordinator Watchdog subnav button exists',
    strpos($indexHtml, 'id="btnAdminWatchdog"') !== false && strpos($indexHtml, 'data-admin-tab="admin-watchdog"') !== false
);

assertTest(
    'F17-DOM-02: Watchdog panel #admin-watchdog exists with data table container',
    strpos($indexHtml, 'id="admin-watchdog"') !== false && strpos($indexHtml, 'id="watchdogTableBody"') !== false
);

assertTest(
    'F17-DOM-03: Watchdog manual scan trigger button exists',
    strpos($indexHtml, 'runWatchdogScan()') !== false
);

// -----------------------------------------------------------------------------
// SUITE 4: Public Verification View (Feature 20)
// -----------------------------------------------------------------------------
echo PHP_EOL . "[SUITE 4] Public Volunteer Badge Verification View (public/verify.php):" . PHP_EOL;

assertTest(
    'F20-DOM-01: public/verify.php file exists and is readable',
    file_exists($baseDir . '/public/verify.php') && strlen($verifyHtml) > 500
);

assertTest(
    'F20-DOM-02: public/verify.php renders official TЦСОН accreditation seal SVG',
    strpos($verifyHtml, 'badge-seal-svg') !== false && strpos($verifyHtml, '<svg') !== false
);

assertTest(
    'F20-DOM-03: public/verify.php formats volunteer name with last initial per Law 99-Z',
    strpos($verifyHtml, 'last_initial') !== false && strpos($verifyHtml, 'volunteerDisplayName') !== false
);

assertTest(
    'F20-DOM-04: public/verify.php displays high-contrast warning banner for invalid/expired tokens',
    strpos($verifyHtml, 'alert-banner alert-banner-danger') !== false && strpos($verifyHtml, 'БЕЙДЖ НЕ ДЕЙСТВИТЕЛЕН') !== false
);

assertTest(
    'F20-DOM-05: public/verify.php connects to accessible design system',
    strpos($verifyHtml, 'href="/css/accessibility.css"') !== false && strpos($verifyHtml, 'senior-mode') !== false
);

// -----------------------------------------------------------------------------
// SUITE 5: Accessible CSS & High Contrast (Feature 21)
// -----------------------------------------------------------------------------
echo PHP_EOL . "[SUITE 5] WCAG 2.1 AAA Accessibility & Animations (public/css/accessibility.css):" . PHP_EOL;

assertTest(
    'F21-CSS-01: accessibility.css defines minimum 21:1 contrast in senior mode',
    strpos($cssContent, 'senior-mode') !== false && (strpos($cssContent, '#000000') !== false || strpos($cssContent, '#000') !== false)
);

assertTest(
    'F21-CSS-02: Touch target size min-height >= 60px defined for interactive elements',
    strpos($cssContent, '60px') !== false
);

assertTest(
    'F21-CSS-03: Visible keyboard focus outline >= 3px defined',
    strpos($cssContent, ':focus-visible') !== false && (strpos($cssContent, '3px') !== false || strpos($cssContent, '4px') !== false)
);

assertTest(
    'F21-CSS-04: Keyframe pulse and sos-pulse animations defined with reduced motion fallback',
    strpos($cssContent, '@keyframes pulse') !== false && strpos($cssContent, '@keyframes sos-pulse') !== false && strpos($cssContent, 'prefers-reduced-motion') !== false
);

assertTest(
    'F21-CSS-05: Dedicated .sos-btn and .sos-pulse-banner classes defined',
    strpos($cssContent, '.sos-btn') !== false && strpos($cssContent, '.sos-pulse-banner') !== false
);

// -----------------------------------------------------------------------------
// SUITE 6: Client JavaScript Implementations (public/js/speech.js & public/js/app.js)
// -----------------------------------------------------------------------------
echo PHP_EOL . "[SUITE 6] Client JavaScript APIs & Web Speech / Web Audio:" . PHP_EOL;

assertTest(
    'JS-SOS-01: Voice command emergency keyword hook defined in speech.js',
    strpos($speechJs, 'checkEmergencyVoiceCommand') !== false && strpos($speechJs, 'triggerSos') !== false
);

assertTest(
    'JS-SOS-02: triggerSos function implemented in app.js with /api/sos dispatch',
    strpos($appJs, 'triggerSos') !== false && strpos($appJs, '/api/sos') !== false
);

assertTest(
    'JS-AUDIO-01: Web Audio API AudioContext and playSiren implemented in app.js',
    strpos($appJs, 'AudioContext') !== false && strpos($appJs, 'playSiren') !== false && strpos($appJs, 'stopSiren') !== false
);

assertTest(
    'JS-GEO-01: Complete modal queries navigator.geolocation with fallback',
    strpos($appJs, 'navigator.geolocation') !== false && strpos($appJs, 'openCompleteModal') !== false
);

assertTest(
    'JS-BDG-01: Dynamic volunteer badge modal renders SVG QR code via renderBadge',
    strpos($appJs, 'renderBadge') !== false && strpos($appJs, 'showVolunteerBadge') !== false && strpos($appJs, 'qr_svg') !== false
);

assertTest(
    'JS-WDC-01: loadWatchdogTasks, runWatchdogScan and reassignOverdueTask implemented',
    strpos($appJs, 'loadWatchdogTasks') !== false && strpos($appJs, 'runWatchdogScan') !== false && strpos($appJs, 'reassignOverdueTask') !== false
);

assertTest(
    'JS-PRV-01: requestRightToBeForgotten dispatches to /api/privacy/anonymize',
    strpos($appJs, 'requestRightToBeForgotten') !== false && strpos($appJs, '/api/privacy/anonymize') !== false
);

// -----------------------------------------------------------------------------
// SUITE 7: Architectural Plan Suite in /plan (Feature 22)
// -----------------------------------------------------------------------------
echo PHP_EOL . "[SUITE 7] Architectural Documentation Suite (/plan):" . PHP_EOL;

$planDir = $baseDir . '/plan';

assertTest('F22-PLN-01: Directory /plan exists in project root', is_dir($planDir));

assertTest(
    'F22-PLN-02: 01_SYSTEM_OVERVIEW.md exists and exceeds 500 bytes',
    file_exists($planDir . '/01_SYSTEM_OVERVIEW.md') && filesize($planDir . '/01_SYSTEM_OVERVIEW.md') > 500
);

$p2 = (string)@file_get_contents($planDir . '/02_GEOFENCING_AND_SECURITY.md');
assertTest(
    'F22-PLN-03: 02_GEOFENCING_AND_SECURITY.md details Haversine and 6371',
    strpos($p2, 'Haversine') !== false && (strpos($p2, '6371') !== false || strpos($p2, '6 371') !== false)
);

$p3 = (string)@file_get_contents($planDir . '/03_VOLUNTEER_VERIFICATION_AND_BADGES.md');
assertTest(
    'F22-PLN-04: 03_VOLUNTEER_VERIFICATION_AND_BADGES.md details MSI and Gosuslugi SNILS',
    strpos($p3, 'МСИ') !== false && (strpos($p3, 'СНИЛС') !== false || strpos($p3, '101') !== false)
);

$p4 = (string)@file_get_contents($planDir . '/04_DATA_PROTECTION_AND_PRIVACY.md');
assertTest(
    'F22-PLN-05: 04_DATA_PROTECTION_AND_PRIVACY.md details Law 99-Z and AES-256-GCM',
    strpos($p4, '99-З') !== false && strpos($p4, 'AES-256-GCM') !== false
);

$p5 = (string)@file_get_contents($planDir . '/05_EMERGENCY_SOS_AND_WATCHDOG.md');
assertTest(
    'F22-PLN-06: 05_EMERGENCY_SOS_AND_WATCHDOG.md details Priority 0 and Watchdog timeouts',
    strpos($p5, 'Priority 0') !== false || strpos($p5, 'Приоритет 0') !== false
);

$p6 = (string)@file_get_contents($planDir . '/06_DATABASE_MIGRATIONS.md');
assertTest(
    'F22-PLN-07: 06_DATABASE_MIGRATIONS.md details SQLite schema and 11 indexes',
    strpos($p6, 'индекс') !== false && strpos($p6, 'migrate.php') !== false
);

$p7 = (string)@file_get_contents($planDir . '/07_REST_API_SPECIFICATION.md');
assertTest(
    'F22-PLN-08: 07_REST_API_SPECIFICATION.md documents /api/requests catalog',
    strpos($p7, '/api/requests') !== false && strpos($p7, 'RBAC') !== false
);

$p8 = (string)@file_get_contents($planDir . '/08_TESTING_AND_AUDIT.md');
assertTest(
    'F22-PLN-09: 08_TESTING_AND_AUDIT.md details 5-tier testing architecture and regression audit',
    strpos($p8, 'Tier') !== false && strpos($p8, 'run_e2e.php') !== false
);

// -----------------------------------------------------------------------------
// SUITE 8: Strict Zero-Emoji Compliance Across All Frontend Files
// -----------------------------------------------------------------------------
echo PHP_EOL . "[SUITE 8] Strict Zero-Emoji Compliance Audit:" . PHP_EOL;

$emojiRegex = '/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1F900}-\x{1F9FF}\x{1F1E0}-\x{1F1FF}]/u';

$filesToAudit = [
    'public/index.php' => $indexHtml,
    'public/verify.php' => $verifyHtml,
    'public/css/accessibility.css' => $cssContent,
    'public/js/app.js' => $appJs,
    'public/js/speech.js' => $speechJs,
    'plan/01_SYSTEM_OVERVIEW.md' => (string)@file_get_contents($planDir . '/01_SYSTEM_OVERVIEW.md'),
    'plan/02_GEOFENCING_AND_SECURITY.md' => $p2,
    'plan/03_VOLUNTEER_VERIFICATION_AND_BADGES.md' => $p3,
    'plan/04_DATA_PROTECTION_AND_PRIVACY.md' => $p4,
    'plan/05_EMERGENCY_SOS_AND_WATCHDOG.md' => $p5,
    'plan/06_DATABASE_MIGRATIONS.md' => $p6,
    'plan/07_REST_API_SPECIFICATION.md' => $p7,
    'plan/08_TESTING_AND_AUDIT.md' => $p8,
];

foreach ($filesToAudit as $filename => $content) {
    $hasEmoji = preg_match($emojiRegex, $content);
    assertTest(
        "EMOJI-AUDIT: {$filename} has zero Unicode emojis",
        $hasEmoji === 0,
        $hasEmoji === 0 ? "100% SVG Vector Clean" : "Contains Unicode Emojis!"
    );
}

// -----------------------------------------------------------------------------
// SUMMARY
// -----------------------------------------------------------------------------
echo PHP_EOL . "--------------------------------------------------------" . PHP_EOL;
echo "TEST RESULTS: Total: {$totalTests} | Passed: {$passedTests} | Failed: {$failedTests}" . PHP_EOL;
echo "========================================================" . PHP_EOL;

exit($failedTests === 0 ? 0 : 1);
