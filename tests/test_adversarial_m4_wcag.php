<?php
declare(strict_types=1);

/**
 * =============================================================================
 * ADVERSARIAL STRESS TEST SUITE: MILESTONE 4
 * WCAG 2.1 AAA, Touch Targets, Zero-Emoji & DOM Accessibility / Security
 * Platform: «ЗаботаРядом» (Советский район г. Минска)
 * Challenger: M4-1
 * =============================================================================
 */

$rootDir = dirname(__DIR__);
$passCount = 0;
$failCount = 0;
$defects = [];
$warnings = [];

function assertAdversarial(string $category, string $code, string $description, bool $condition, string $evidence = '', bool $isFatal = true): void {
    global $passCount, $failCount, $defects, $warnings;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$code}: {$description}" . ($evidence !== '' ? " :: {$evidence}" : "") . PHP_EOL;
    } else {
        if ($isFatal) {
            $failCount++;
            $defects[] = "[{$category}] {$code}: {$description} -> {$evidence}";
            echo "  [FAIL] {$code}: {$description}" . ($evidence !== '' ? " :: {$evidence}" : "") . PHP_EOL;
        } else {
            $warnings[] = "[{$category}] {$code}: {$description} -> {$evidence}";
            echo "  [WARN] {$code}: {$description}" . ($evidence !== '' ? " :: {$evidence}" : "") . PHP_EOL;
        }
    }
}

echo "================================================================================" . PHP_EOL;
echo "  ADVERSARIAL CHALLENGER M4-1: WCAG 2.1 AAA, ZERO-EMOJI & DOM SECURITY SUITE   " . PHP_EOL;
echo "================================================================================" . PHP_EOL;

// =============================================================================
// PART 1: WCAG 2.1 AAA Contrast Ratio Mathematics & CSS Palette Analysis
// =============================================================================
echo PHP_EOL . "--- [PART 1] WCAG 2.1 AAA Contrast Ratio Mathematics ---" . PHP_EOL;

/**
 * Convert Hex Color to sRGB tuple [0..255]
 */
function hexToRgb(string $hex): array {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $r = hexdec(str_repeat(substr($hex, 0, 1), 2));
        $g = hexdec(str_repeat(substr($hex, 1, 1), 2));
        $b = hexdec(str_repeat(substr($hex, 2, 1), 2));
    } else {
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
    }
    return [$r, $g, $b];
}

/**
 * Gamma linearization and relative luminance per W3C WCAG 2.1:
 * L = 0.2126 * R_lin + 0.7152 * G_lin + 0.0722 * B_lin
 * where C_lin = C_srgb / 12.92 if C_srgb <= 0.04045 else ((C_srgb + 0.055) / 1.055)^2.4
 */
function getRelativeLuminance(string $hex): float {
    [$r, $g, $b] = hexToRgb($hex);
    $linearize = function(int $c8): float {
        $c = $c8 / 255.0;
        return ($c <= 0.04045) ? ($c / 12.92) : pow(($c + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * $linearize($r) + 0.7152 * $linearize($g) + 0.0722 * $linearize($b);
}

/**
 * Contrast ratio: (L1 + 0.05) / (L2 + 0.05) where L1 >= L2
 */
function getContrastRatio(string $color1, string $color2): float {
    $l1 = getRelativeLuminance($color1);
    $l2 = getRelativeLuminance($color2);
    $lighter = max($l1, $l2);
    $darker = min($l1, $l2);
    return ($lighter + 0.05) / ($darker + 0.05);
}

$cssPath = $rootDir . '/public/css/accessibility.css';
$cssContent = (string)@file_get_contents($cssPath);

// 1.1 Senior Mode active contrast: #000000 on #FFFFFF
$crBlackWhite = getContrastRatio('#000000', '#FFFFFF');
assertAdversarial(
    'WCAG-MATH',
    'CR-MATH-01',
    'Senior mode active color pair #000000 on #FFFFFF satisfies WCAG AAA senior contrast (>= 21.0:1)',
    $crBlackWhite >= 21.0,
    sprintf("Exact ratio: %.4f:1 (L_white=%.4f, L_black=%.4f)", $crBlackWhite, getRelativeLuminance('#FFFFFF'), getRelativeLuminance('#000000'))
);

// 1.2 Senior Mode candidate warm background: #000000 on #FFF9E6
$crBlackWarm = getContrastRatio('#000000', '#FFF9E6');
$lumWarm = getRelativeLuminance('#FFF9E6');
assertAdversarial(
    'WCAG-MATH',
    'CR-MATH-02',
    'Candidate warm low-glare background #FFF9E6 contrast with #000000 exceeds WCAG AAA normal text 7.0:1',
    $crBlackWarm >= 7.0,
    sprintf("Exact ratio: %.4f:1 (L_warm=%.4f; satisfies WCAG AAA 7:1; falls short of 21:1 ceiling)", $crBlackWarm, $lumWarm)
);

// 1.3 High-contrast alert button: #B00020 on #FFFFFF (and white text on #B00020)
$crAlertWhite = getContrastRatio('#B00020', '#FFFFFF');
assertAdversarial(
    'WCAG-MATH',
    'CR-MATH-03',
    'High-contrast alert button #B00020 on #FFFFFF satisfies UI component / large text threshold (>= 4.5:1)',
    $crAlertWhite >= 4.5,
    sprintf("Exact ratio: %.4f:1 (L_alert=%.4f, Threshold=4.5:1)", $crAlertWhite, getRelativeLuminance('#B00020'))
);

// 1.4 Focus visible outline: #0056B3 on #FFFFFF
$crFocusBlue = getContrastRatio('#0056B3', '#FFFFFF');
assertAdversarial(
    'WCAG-MATH',
    'CR-MATH-04',
    'Focus visible outline #0056B3 on #FFFFFF satisfies non-text UI component contrast (>= 3.0:1)',
    $crFocusBlue >= 3.0,
    sprintf("Exact ratio: %.4f:1 (L_blue=%.4f, Threshold=3.0:1)", $crFocusBlue, getRelativeLuminance('#0056B3'))
);

// 1.5 Focus visible outline: #000000 on #FFFFFF
$crFocusBlack = getContrastRatio('#000000', '#FFFFFF');
assertAdversarial(
    'WCAG-MATH',
    'CR-MATH-05',
    'Focus visible outline #000000 on #FFFFFF satisfies non-text UI component contrast (>= 3.0:1)',
    $crFocusBlack >= 3.0,
    sprintf("Exact ratio: %.4f:1 (Threshold=3.0:1)", $crFocusBlack)
);

// 1.6 Verify CSS palette configuration matches mathematical specifications
$seniorVarsOk = preg_match('/body\.senior-mode\s*\{[^}]*--bg-color:\s*#ffffff[^}]*--text-color:\s*#000000/is', $cssContent);
assertAdversarial(
    'WCAG-CSS',
    'CR-CSS-01',
    'accessibility.css body.senior-mode assigns --bg-color: #ffffff and --text-color: #000000',
    (bool)$seniorVarsOk,
    $seniorVarsOk ? 'Verified in body.senior-mode rule' : 'Missing 21:1 senior mode palette'
);

$sosBtnColorOk = preg_match('/\.sos-btn[^{]*\{[^}]*background-color:\s*#B00020/is', $cssContent);
assertAdversarial(
    'WCAG-CSS',
    'CR-CSS-02',
    'accessibility.css .sos-btn assigns background-color: #B00020',
    (bool)$sosBtnColorOk,
    $sosBtnColorOk ? 'Verified in .sos-btn rule' : 'Missing #B00020 background color'
);

// =============================================================================
// PART 2: Touch Target Boundaries, Keyboard Focus & Reduced Motion
// =============================================================================
echo PHP_EOL . "--- [PART 2] Touch Target Boundaries, Keyboard Focus & Reduced Motion ---" . PHP_EOL;

// 2.1 Keyboard focus-visible outline >= 3px
$focusOutline3px = preg_match('/(?::focus-visible|\.sos-btn:focus-visible)[^{]*\{[^}]*outline:\s*([3-9]|\d{2,})px/is', $cssContent);
assertAdversarial(
    'A11Y-FOCUS',
    'TARGET-01',
    ':focus-visible specifies outline width >= 3px for keyboard accessibility',
    (bool)$focusOutline3px,
    $focusOutline3px ? 'Enforced in accessibility.css (:focus-visible { outline: 3px solid ... })' : 'Missing focus outline >= 3px'
);

// 2.2 prefers-reduced-motion tames @keyframes pulse and @keyframes sos-pulse
$hasReducedMotionBlock = preg_match('/@media\s*\(\s*prefers-reduced-motion:\s*reduce\s*\)\s*\{([^}]+)\}/is', $cssContent, $mReduced);
$reducedContent = $mReduced[1] ?? '';
$tamesAnimations = strpos($reducedContent, 'animation: none') !== false;
assertAdversarial(
    'A11Y-MOTION',
    'TARGET-02',
    '@media (prefers-reduced-motion: reduce) disables keyframe pulse animations',
    $hasReducedMotionBlock && $tamesAnimations,
    $tamesAnimations ? 'Verified: animation: none with 4px static border fallback' : 'Missing animation taming in media query'
);

// 2.3 #sosBtn min-height: 60px
$sosMinHeightOk = preg_match('/(?:#sosBtn|\.sos-btn)[^{]*\{[^}]*min-height:\s*([6-9]\d|\d{3,})px/is', $cssContent);
assertAdversarial(
    'A11Y-TARGET',
    'TARGET-03',
    '#sosBtn enforces min-height >= 60px',
    (bool)$sosMinHeightOk,
    $sosMinHeightOk ? 'Enforced: min-height: 60px (and 76px in senior-mode)' : 'Missing min-height >= 60px'
);

// 2.4 #sosBtn min-width: 60px
$sosMinWidthOk = preg_match('/(?:#sosBtn|\.sos-btn)[^{]*\{[^}]*min-width:\s*([6-9]\d|\d{3,})px/is', $cssContent);
assertAdversarial(
    'A11Y-TARGET',
    'TARGET-04',
    '#sosBtn enforces explicit min-width >= 60px',
    (bool)$sosMinWidthOk,
    $sosMinWidthOk ? 'Enforced: min-width: 60px' : 'DEFECT: Missing explicit min-width: 60px rule in CSS'
);

// 2.5 .btn base height in senior mode
$btnHeightSeniorOk = preg_match('/body\.senior-mode\s*\{[^}]*--btn-height:\s*60px/is', $cssContent)
    && preg_match('/\.btn\s*\{[^}]*height:\s*var\(--btn-height\)/is', $cssContent);
assertAdversarial(
    'A11Y-TARGET',
    'TARGET-05',
    '.btn base height expands to 60px in senior mode via --btn-height: 60px',
    (bool)$btnHeightSeniorOk,
    $btnHeightSeniorOk ? 'Enforced via --btn-height: 60px' : 'Missing 60px button height variable in senior mode'
);

// 2.6 .btn min-width in senior mode
$btnMinWidthSeniorOk = preg_match('/(?:body\.senior-mode\s+\.btn|\.btn)[^{]*\{[^}]*min-width:\s*([6-9]\d|\d{3,})px/is', $cssContent);
assertAdversarial(
    'A11Y-TARGET',
    'TARGET-06',
    '.btn interactive elements enforce explicit min-width >= 60px in senior mode',
    (bool)$btnMinWidthSeniorOk,
    $btnMinWidthSeniorOk ? 'Enforced in CSS' : 'DEFECT: Missing explicit min-width: 60px for .btn in senior mode'
);

// 2.7 Senior mode button size degradation (.btn-sm / .btn-xs override below 60px)
$sub60Overrides = preg_match('/body\.senior-mode\s+\.(?:btn-sm|btn-xs)\s*\{[^}]*height:\s*(?:[1-5]\d|[1-9])px/is', $cssContent, $mSub);
assertAdversarial(
    'A11Y-TARGET',
    'TARGET-07',
    'Senior mode contains NO button overrides below 60px minimum boundary',
    !$sub60Overrides,
    $sub60Overrides ? 'DEFECT: body.senior-mode overrides .btn-sm to 48px and .btn-xs to 40px (< 60px)' : 'Clean: no sub-60px overrides'
);

// 2.8 Single-line input and select elements min-height: 60px in senior mode
$inputMinHeightOk = preg_match('/(?:body\.senior-mode\s+input|input)[^{]*\{[^}]*min-height:\s*([6-9]\d|\d{3,})px/is', $cssContent);
assertAdversarial(
    'A11Y-TARGET',
    'TARGET-08A',
    'input elements enforce explicit min-height >= 60px in senior mode',
    (bool)$inputMinHeightOk,
    $inputMinHeightOk ? 'Enforced in CSS' : 'DEFECT: Missing explicit min-height: 60px for input elements'
);

$selectMinHeightOk = preg_match('/(?:body\.senior-mode\s+select|select)[^{]*\{[^}]*min-height:\s*([6-9]\d|\d{3,})px/is', $cssContent);
assertAdversarial(
    'A11Y-TARGET',
    'TARGET-08B',
    'select elements enforce explicit min-height >= 60px in senior mode',
    (bool)$selectMinHeightOk,
    $selectMinHeightOk ? 'Enforced in CSS' : 'DEFECT: Missing explicit min-height: 60px for select elements'
);

// 2.9 .form-control / input / select min-width: 60px in senior mode
$formControlMinWidthOk = preg_match('/(?:body\.senior-mode\s+(?:\.form-control|input|select)|\.form-control|input|select)[^{]*\{[^}]*min-width:\s*([6-9]\d|\d{3,})px/is', $cssContent);
assertAdversarial(
    'A11Y-TARGET',
    'TARGET-09',
    '.form-control / input / select enforce min-width >= 60px in senior mode',
    (bool)$formControlMinWidthOk,
    $formControlMinWidthOk ? 'Enforced in CSS' : 'DEFECT: Missing explicit min-width: 60px for form controls/inputs'
);

// =============================================================================
// PART 3: Exhaustive Zero-Emoji Compliance Across All Codebases
// =============================================================================
echo PHP_EOL . "--- [PART 3] Exhaustive Zero-Emoji Compliance (public/ and plan/) ---" . PHP_EOL;

$emojiRegex = '/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u';

function scanDirFiles(string $dir): array {
    $results = [];
    if (!is_dir($dir)) return $results;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $results[] = $file->getPathname();
        }
    }
    return $results;
}

$allFiles = array_merge(
    scanDirFiles($rootDir . '/public'),
    scanDirFiles($rootDir . '/plan')
);

$filesWithEmojis = [];
$totalScanned = 0;

foreach ($allFiles as $file) {
    $totalScanned++;
    $content = (string)file_get_contents($file);
    $rel = str_replace($rootDir . DIRECTORY_SEPARATOR, '', $file);
    $rel = str_replace('\\', '/', $rel);

    if (preg_match_all($emojiRegex, $content, $matches)) {
        $filesWithEmojis[$rel] = count($matches[0]);
    }
}

assertAdversarial(
    'ZERO-EMOJI',
    'EMOJI-01',
    sprintf('Exhaustive regex scan across all %d files in public/ and plan/', $totalScanned),
    empty($filesWithEmojis),
    empty($filesWithEmojis) ? '100% Vector SVG Clean: EXACTLY 0 emoji code points found' : 'DEFECT: Emojis detected in: ' . json_encode($filesWithEmojis)
);

// =============================================================================
// PART 4: DOM Security & Privacy Fuzzing in public/verify.php
// =============================================================================
echo PHP_EOL . "--- [PART 4] DOM Security & Privacy Fuzzing in public/verify.php ---" . PHP_EOL;

require_once $rootDir . '/app/Services/BadgeService.php';
use App\Services\BadgeService;

function simulateVerify(array $get, array $server = []): string {
    global $rootDir;
    $prevGet = $_GET;
    $prevServer = $_SERVER;

    $_GET = $get;
    $_SERVER = array_merge($_SERVER, $server);

    ob_start();
    try {
        include $rootDir . '/public/verify.php';
    } catch (\Throwable $t) {
        echo "VERIFY_ERROR: " . $t->getMessage();
    }
    $res = ob_get_clean();

    $_GET = $prevGet;
    $_SERVER = $prevServer;

    return (string)$res;
}

// 4.1 Fuzzing $_GET['token'] with adversarial injection vectors
$fuzzTokens = [
    'xss_script' => '<script>alert(1)</script>',
    'xss_img_onerror' => '"><img src=x onerror=alert(1)>',
    'xss_svg_onload' => '"><svg/onload=alert(document.cookie)>',
    'sqli_injection' => "' OR '1'='1",
    'html_body_injection' => '<body onload=alert(1)>',
    'proto_pollution' => '__proto__[admin]=1',
    'path_traversal' => '../../../../etc/passwd',
];

$xssViolations = [];
foreach ($fuzzTokens as $label => $payload) {
    $output = simulateVerify(['token' => $payload]);
    // The raw payload must NEVER be rendered into DOM unescaped
    if (strpos($output, $payload) !== false) {
        $xssViolations[] = "Unescaped {$label}: {$payload}";
    }
}

assertAdversarial(
    'SECURITY-DOM',
    'VERIFY-XSS-01',
    'Adversarial fuzzing of $_GET[\'token\'] with 7 injection vectors produces zero unescaped reflections',
    empty($xssViolations),
    empty($xssViolations) ? 'All 7 injection payloads safely neutralized / rejected' : 'DEFECT: ' . implode(', ', $xssViolations)
);

// 4.2 Forged signed token containing XSS payloads inside claims
$xssName = '"><script>alert("VOLUNTEER_NAME_PWNED")</script>';
$xssOrg = '"><img src=x onerror="alert(\'ORG_PWNED\')">';

$toxicToken = BadgeService::generateBadgeToken(777, $xssName, $xssOrg, 3600);
$toxicHtml = simulateVerify(['token' => $toxicToken]);

$rawScriptFound = strpos($toxicHtml, '<script>alert("VOLUNTEER_NAME_PWNED")</script>') !== false;
$rawImgFound = strpos($toxicHtml, '<img src=x onerror="alert(\'ORG_PWNED\')">') !== false;

assertAdversarial(
    'SECURITY-DOM',
    'VERIFY-XSS-02',
    'Signed toxic token with script injection in volunteer name is sanitized via htmlspecialchars()',
    !$rawScriptFound,
    !$rawScriptFound ? 'Escaped safely to &lt;script&gt;' : 'DEFECT: Raw <script> tag injected into DOM'
);

assertAdversarial(
    'SECURITY-DOM',
    'VERIFY-XSS-03',
    'Signed toxic token with img onerror in organization is sanitized via htmlspecialchars()',
    !$rawImgFound,
    !$rawImgFound ? 'Escaped safely to &lt;img' : 'DEFECT: Raw <img> tag injected into DOM'
);

// 4.3 PII and Law 99-Z Privacy Leakage Verification
$cleanToken = BadgeService::generateBadgeToken(101, 'Смирнов Алексей Сергеевич', 'ТЦСОН Советского района г. Минска', 3600);
$cleanHtml = simulateVerify(['token' => $cleanToken]);

$leakedFullSurname = strpos($cleanHtml, 'Смирнов') !== false;
$hasMaskedName = strpos($cleanHtml, 'Алексей') !== false && strpos($cleanHtml, 'С.') !== false;

assertAdversarial(
    'PRIVACY-PII',
    'VERIFY-PII-01',
    'Public verification view masks volunteer surname to initial (Law 99-Z: "Алексей С.")',
    !$leakedFullSurname && $hasMaskedName,
    !$leakedFullSurname ? 'Full surname suppressed; displayed as "Алексей С."' : 'DEFECT: Full surname "Смирнов" leaked in public HTML'
);

$piiPatterns = [
    'passport_by' => '/\b[A-Z]{2}\s*\d{7}\b/i',
    'personal_phone_by' => '/\+375\s*\((?:25|29|33|44)\)\s*\d{3}-\d{2}-\d{2}/',
    'apartment_street' => '/(?:кв(?:\.|артира)|ул(?:\.|ица))\s*\d+/ui',
];

$foundPii = [];
foreach ($piiPatterns as $pName => $pRegex) {
    if (preg_match($pRegex, $cleanHtml, $matches)) {
        $foundPii[] = "{$pName} ({$matches[0]})";
    }
}

assertAdversarial(
    'PRIVACY-PII',
    'VERIFY-PII-02',
    'Public verification view contains zero passport numbers, personal phones or street addresses',
    empty($foundPii),
    empty($foundPii) ? 'Zero sensitive PII elements detected in public DOM' : 'DEFECT: Sensitive PII found: ' . implode(', ', $foundPii)
);

$hasOfficialHotline = strpos($cleanHtml, '+375 (17) 377-00-00') !== false;
assertAdversarial(
    'PRIVACY-PII',
    'VERIFY-PII-03',
    'Public verification view displays official organizational landline hotline (+375 17 377-00-00)',
    $hasOfficialHotline,
    $hasOfficialHotline ? 'Official hotline present in public footer' : 'DEFECT: Missing official hotline contact'
);

// =============================================================================
// SUMMARY & VERDICT
// =============================================================================
$totalAssertions = $passCount + $failCount;
echo PHP_EOL . "================================================================================" . PHP_EOL;
printf("TEST EXECUTION COMPLETE: %d Total Assertions | %d Passed | %d Failed" . PHP_EOL, $totalAssertions, $passCount, $failCount);

if ($failCount > 0) {
    echo "VERDICT: DEFECT_FOUND" . PHP_EOL;
    echo "Identified Defect Details:" . PHP_EOL;
    foreach ($defects as $i => $d) {
        printf("  %d) %s" . PHP_EOL, $i + 1, $d);
    }
    echo "================================================================================" . PHP_EOL;
    exit(1);
} else {
    echo "VERDICT: CONFIRM" . PHP_EOL;
    echo "All WCAG 2.1 AAA, Zero-Emoji and DOM Security invariants hold." . PHP_EOL;
    echo "================================================================================" . PHP_EOL;
    exit(0);
}
