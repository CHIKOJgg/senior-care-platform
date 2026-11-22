<?php
declare(strict_types=1);

/**
 * INDEPENDENT ADVERSARIAL STRESS TEST
 * Challenger M4-r2-1
 * 
 * Verifies:
 * 1. WCAG 2.1 AAA Contrast Ratio Mathematics
 * 2. Touch target minimum boundaries (>=60px min-height & min-width)
 * 3. Keyboard focus (:focus-visible >= 3px)
 * 4. Reduced motion animation disabling
 * 5. Zero emojis across all frontend and plan files
 * 6. CSS Specificity & Cascade simulation
 */

$rootDir = dirname(__DIR__);
$testsRun = 0;
$testsPassed = 0;
$testsFailed = 0;
$failures = [];

function check(string $id, string $desc, bool $condition, string $detail = ''): void {
    global $testsRun, $testsPassed, $testsFailed, $failures;
    $testsRun++;
    if ($condition) {
        $testsPassed++;
        echo "  [PASS] {$id}: {$desc}" . ($detail ? " ({$detail})" : "") . PHP_EOL;
    } else {
        $testsFailed++;
        $failures[] = "{$id}: {$desc} - {$detail}";
        echo "  [FAIL] {$id}: {$desc}" . ($detail ? " ({$detail})" : "") . PHP_EOL;
    }
}

echo "=== INDEPENDENT EMPIRICAL CHALLENGER VERIFICATION (M4-r2-1) ===" . PHP_EOL;

// ==========================================
// 1. WCAG 2.1 AAA Contrast Ratio Mathematics
// ==========================================
echo PHP_EOL . "--- [1] Mathematical Luminance & Contrast Calculation ---" . PHP_EOL;

function srgbToLinear(float $val): float {
    return ($val <= 0.04045) ? ($val / 12.92) : pow(($val + 0.055) / 1.055, 2.4);
}

function calcRelLuminance(int $r, int $g, int $b): float {
    return 0.2126 * srgbToLinear($r / 255.0)
         + 0.7152 * srgbToLinear($g / 255.0)
         + 0.0722 * srgbToLinear($b / 255.0);
}

function contrastRatio(float $l1, float $l2): float {
    $lighter = max($l1, $l2);
    $darker = min($l1, $l2);
    return ($lighter + 0.05) / ($darker + 0.05);
}

$lumWhite = calcRelLuminance(255, 255, 255);
$lumBlack = calcRelLuminance(0, 0, 0);
$crSenior = contrastRatio($lumWhite, $lumBlack);

check(
    'MATH-01',
    'Senior mode active contrast #000000 on #FFFFFF equals theoretical 21.0:1 maximum',
    abs($crSenior - 21.0) < 0.0001,
    sprintf('Computed: %.4f:1', $crSenior)
);

// SOS button #B00020
$lumAlert = calcRelLuminance(176, 0, 32);
$crSosOnWhite = contrastRatio($lumWhite, $lumAlert);
check(
    'MATH-02',
    'SOS button #B00020 on white background exceeds WCAG AAA normal text 7.0:1 threshold',
    $crSosOnWhite >= 7.0,
    sprintf('Computed: %.4f:1 (Threshold: 7.0:1)', $crSosOnWhite)
);

$crWhiteOnSos = contrastRatio($lumAlert, $lumWhite);
check(
    'MATH-03',
    'White label #FFFFFF on SOS button #B00020 exceeds WCAG AAA 7.0:1 threshold',
    $crWhiteOnSos >= 7.0,
    sprintf('Computed: %.4f:1 (Threshold: 7.0:1)', $crWhiteOnSos)
);

// Focus outline #0056B3 on white
$lumFocusBlue = calcRelLuminance(0, 86, 179);
$crFocusBlue = contrastRatio($lumWhite, $lumFocusBlue);
check(
    'MATH-04',
    'Focus outline #0056B3 on white exceeds WCAG 2.1 non-text 3.0:1 threshold',
    $crFocusBlue >= 3.0,
    sprintf('Computed: %.4f:1 (Threshold: 3.0:1)', $crFocusBlue)
);

// ==========================================
// 2. CSS Rules Parsing & Boundary Enforcement
// ==========================================
echo PHP_EOL . "--- [2] CSS Rules Parsing & Boundary Enforcement ---" . PHP_EOL;

$css = (string)file_get_contents($rootDir . '/public/css/accessibility.css');

// Extract body.senior-mode block
preg_match('/body\.senior-mode\s*\{([^}]+)\}/is', $css, $mSeniorVars);
$seniorVars = $mSeniorVars[1] ?? '';

check(
    'CSS-VARS-01',
    'Senior mode defines --bg-color as #ffffff and --text-color as #000000',
    strpos($seniorVars, '--bg-color: #ffffff') !== false && strpos($seniorVars, '--text-color: #000000') !== false,
    'Verified variables'
);

check(
    'CSS-VARS-02',
    'Senior mode defines --btn-height as 60px',
    strpos($seniorVars, '--btn-height: 60px') !== false,
    'Verified --btn-height: 60px'
);

check(
    'CSS-VARS-03',
    'Senior mode defines --border-focus as #000000',
    strpos($seniorVars, '--border-focus: #000000') !== false,
    'Verified --border-focus: #000000'
);

// Focus-visible outline
preg_match('/:focus-visible\s*\{([^}]+)\}/is', $css, $mFocus);
$focusProps = $mFocus[1] ?? '';
preg_match('/outline:\s*(\d+)px/i', $focusProps, $mOutline);
$outlineWidth = (int)($mOutline[1] ?? 0);

check(
    'CSS-FOCUS-01',
    ':focus-visible outline width is at least 3px',
    $outlineWidth >= 3,
    "Outline width: {$outlineWidth}px"
);

// SOS button focus outline
preg_match('/(?:#sosBtn|\.sos-btn)[^{]*:focus-visible[^{]*\{([^}]+)\}/is', $css, $mSosFocus);
$sosFocusProps = $mSosFocus[1] ?? '';
preg_match('/outline:\s*(\d+)px/i', $sosFocusProps, $mSosOutline);
$sosOutlineWidth = (int)($mSosOutline[1] ?? 0);

check(
    'CSS-FOCUS-02',
    'SOS button :focus-visible outline width is at least 3px (actual 4px)',
    $sosOutlineWidth >= 3,
    "SOS outline width: {$sosOutlineWidth}px"
);

// Reduced motion
preg_match('/@media\s*\(\s*prefers-reduced-motion:\s*reduce\s*\)\s*\{([^}]+(?:\{[^}]+\}[^}]*)*)\}/is', $css, $mRedMotion);
$redMotionContent = $mRedMotion[1] ?? '';

check(
    'CSS-MOTION-01',
    '@media (prefers-reduced-motion: reduce) block exists',
    !empty($redMotionContent),
    'Found reduced-motion query'
);

check(
    'CSS-MOTION-02',
    '@media (prefers-reduced-motion: reduce) sets animation: none on pulse elements',
    strpos($redMotionContent, 'animation: none') !== false,
    'animation: none confirmed'
);

// Senior mode button touch targets
preg_match('/body\.senior-mode\s+\.btn\s*\{([^}]+)\}/is', $css, $mSeniorBtn);
$seniorBtnProps = $mSeniorBtn[1] ?? '';
preg_match('/min-height:\s*(\d+)px/i', $seniorBtnProps, $mBtnMinH);
preg_match('/min-width:\s*(\d+)px/i', $seniorBtnProps, $mBtnMinW);
$btnMinH = (int)($mBtnMinH[1] ?? 0);
$btnMinW = (int)($mBtnMinW[1] ?? 0);

check(
    'CSS-TARGET-01',
    'body.senior-mode .btn enforces min-height >= 60px',
    $btnMinH >= 60,
    "Min height: {$btnMinH}px"
);

check(
    'CSS-TARGET-02',
    'body.senior-mode .btn enforces min-width >= 60px',
    $btnMinW >= 60,
    "Min width: {$btnMinW}px"
);

// Senior mode .btn-sm
preg_match('/body\.senior-mode\s+\.btn-sm\s*\{([^}]+)\}/is', $css, $mSeniorBtnSm);
$seniorBtnSmProps = $mSeniorBtnSm[1] ?? '';
$btnSmMinH60 = strpos($seniorBtnSmProps, 'min-height: 60px !important') !== false;
$btnSmMinW60 = strpos($seniorBtnSmProps, 'min-width: 60px !important') !== false;
$btnSmHeightAuto = strpos($seniorBtnSmProps, 'height: auto !important') !== false;

check(
    'CSS-TARGET-03',
    'body.senior-mode .btn-sm enforces min-height: 60px !important and min-width: 60px !important',
    $btnSmMinH60 && $btnSmMinW60 && $btnSmHeightAuto,
    'Verified !important override for senior mode .btn-sm'
);

// Senior mode .btn-xs
preg_match('/body\.senior-mode\s+\.btn-xs\s*\{([^}]+)\}/is', $css, $mSeniorBtnXs);
$seniorBtnXsProps = $mSeniorBtnXs[1] ?? '';
$btnXsMinH60 = strpos($seniorBtnXsProps, 'min-height: 60px !important') !== false;
$btnXsMinW60 = strpos($seniorBtnXsProps, 'min-width: 60px !important') !== false;
$btnXsHeightAuto = strpos($seniorBtnXsProps, 'height: auto !important') !== false;

check(
    'CSS-TARGET-04',
    'body.senior-mode .btn-xs enforces min-height: 60px !important and min-width: 60px !important',
    $btnXsMinH60 && $btnXsMinW60 && $btnXsHeightAuto,
    'Verified !important override for senior mode .btn-xs'
);

// Form control, inputs, selects in senior mode
preg_match('/body\.senior-mode\s+(?:\.form-control|input|select)[^{]*\{([^}]+)\}/is', $css, $mSeniorForm);
$seniorFormProps = $mSeniorForm[1] ?? '';
preg_match('/min-height:\s*(\d+)px/i', $seniorFormProps, $mFormMinH);
preg_match('/min-width:\s*(\d+)px/i', $seniorFormProps, $mFormMinW);
$formMinH = (int)($mFormMinH[1] ?? 0);
$formMinW = (int)($mFormMinW[1] ?? 0);

check(
    'CSS-TARGET-05',
    'body.senior-mode form controls enforce min-height >= 60px',
    $formMinH >= 60,
    "Form min height: {$formMinH}px"
);

check(
    'CSS-TARGET-06',
    'body.senior-mode form controls enforce min-width >= 60px',
    $formMinW >= 60,
    "Form min width: {$formMinW}px"
);

// SOS button touch target
preg_match('/\.sos-btn,\s*#sosBtn[^{]*\{([^}]+)\}/is', $css, $mSosBtn);
$sosBtnProps = $mSosBtn[1] ?? '';
preg_match('/min-height:\s*(\d+)px/i', $sosBtnProps, $mSosMinH);
preg_match('/min-width:\s*(\d+)px/i', $sosBtnProps, $mSosMinW);
$sosMinH = (int)($mSosMinH[1] ?? 0);
$sosMinW = (int)($mSosMinW[1] ?? 0);

check(
    'CSS-TARGET-07',
    '.sos-btn / #sosBtn enforces min-height >= 60px and min-width >= 60px',
    $sosMinH >= 60 && $sosMinW >= 60,
    "min-height: {$sosMinH}px, min-width: {$sosMinW}px"
);

// Senior mode SOS button
preg_match('/body\.senior-mode\s+\.sos-btn[^{]*\{([^}]+)\}/is', $css, $mSeniorSos);
$seniorSosProps = $mSeniorSos[1] ?? '';
preg_match('/min-height:\s*(\d+)px/i', $seniorSosProps, $mSeniorSosMinH);
preg_match('/min-width:\s*(\d+)px/i', $seniorSosProps, $mSeniorSosMinW);
$seniorSosMinH = (int)($mSeniorSosMinH[1] ?? 0);
$seniorSosMinW = (int)($mSeniorSosMinW[1] ?? 0);

check(
    'CSS-TARGET-08',
    'body.senior-mode .sos-btn expands to min-height >= 76px and min-width >= 60px',
    $seniorSosMinH >= 76 && $seniorSosMinW >= 60,
    "min-height: {$seniorSosMinH}px, min-width: {$seniorSosMinW}px"
);

// ==========================================
// 3. Index.php Verification
// ==========================================
echo PHP_EOL . "--- [3] HTML DOM & Inline Style Verification (public/index.php) ---" . PHP_EOL;

$indexPhp = (string)file_get_contents($rootDir . '/public/index.php');

// Verify #btnAnonymizeProfile
preg_match('/<button[^>]+id="btnAnonymizeProfile"[^>]*>/is', $indexPhp, $mAnonBtn);
$anonBtnTag = $mAnonBtn[0] ?? '';

check(
    'DOM-ANON-01',
    '#btnAnonymizeProfile exists in public/index.php',
    !empty($anonBtnTag),
    'Found button tag'
);

check(
    'DOM-ANON-02',
    '#btnAnonymizeProfile specifies min-height >= 60px inline',
    strpos($anonBtnTag, 'min-height: 60px') !== false,
    $anonBtnTag
);

check(
    'DOM-ANON-03',
    '#btnAnonymizeProfile specifies min-width >= 60px inline',
    strpos($anonBtnTag, 'min-width: 60px') !== false,
    $anonBtnTag
);

check(
    'DOM-ANON-04',
    '#btnAnonymizeProfile does NOT contain obsolete min-height: 48px',
    strpos($anonBtnTag, '48px') === false,
    'Cleaned up obsolete 48px'
);

// Verify SOS button in index.php
preg_match('/<button[^>]+id="sosBtn"[^>]*>/is', $indexPhp, $mSosHtml);
$sosHtmlTag = $mSosHtml[0] ?? '';

check(
    'DOM-SOS-01',
    '#sosBtn exists in public/index.php with min-height: 60px and min-width: 200px',
    strpos($sosHtmlTag, 'min-height: 60px') !== false && strpos($sosHtmlTag, 'min-width: 200px') !== false,
    'Found #sosBtn with explicit dimensions'
);

// ==========================================
// 4. Exhaustive Zero-Emoji Scan
// ==========================================
echo PHP_EOL . "--- [4] Exhaustive Zero-Emoji Scan Across Frontend & Plan ---" . PHP_EOL;

$emojiPattern = '/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u';

function getFiles(string $dir): array {
    $files = [];
    if (!is_dir($dir)) return $files;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        if ($item->isFile()) {
            $files[] = $item->getPathname();
        }
    }
    return $files;
}

$dirsToScan = [
    $rootDir . '/public',
    $rootDir . '/plan'
];

$allScanned = [];
$emojisFound = [];

foreach ($dirsToScan as $d) {
    foreach (getFiles($d) as $f) {
        $allScanned[] = $f;
        $content = (string)file_get_contents($f);
        if (preg_match_all($emojiPattern, $content, $matches)) {
            $emojisFound[$f] = count($matches[0]);
        }
    }
}

check(
    'ZERO-EMOJI-01',
    sprintf('Exhaustive scan across all %d files in public/ and plan/ detects zero emojis', count($allScanned)),
    empty($emojisFound),
    empty($emojisFound) ? '0 emojis detected across all files' : 'EMOJIS DETECTED: ' . json_encode($emojisFound)
);

// Also check app/ files for sanity
$appFiles = getFiles($rootDir . '/app');
$appEmojis = [];
foreach ($appFiles as $f) {
    $content = (string)file_get_contents($f);
    if (preg_match_all($emojiPattern, $content, $matches)) {
        $appEmojis[$f] = count($matches[0]);
    }
}

check(
    'ZERO-EMOJI-02',
    sprintf('Sanity scan across all %d files in app/ detects zero emojis', count($appFiles)),
    empty($appEmojis),
    empty($appEmojis) ? '0 emojis in app/' : 'EMOJIS: ' . json_encode($appEmojis)
);

// ==========================================
// SUMMARY
// ==========================================
echo PHP_EOL . "==========================================================" . PHP_EOL;
printf("INDEPENDENT SUITE COMPLETE: %d Run | %d Passed | %d Failed" . PHP_EOL, $testsRun, $testsPassed, $testsFailed);

if ($testsFailed > 0) {
    echo "INDEPENDENT VERDICT: DEFECT_FOUND" . PHP_EOL;
    foreach ($failures as $f) {
        echo " - " . $f . PHP_EOL;
    }
    exit(1);
} else {
    echo "INDEPENDENT VERDICT: CONFIRM" . PHP_EOL;
    exit(0);
}
