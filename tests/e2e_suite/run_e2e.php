<?php

declare(strict_types=1);

namespace Tests\E2ESuite;

require_once __DIR__ . '/TestHarness.php';
require_once __DIR__ . '/Tier1_FeatureCoverageTest.php';
require_once __DIR__ . '/Tier2_BoundaryCornerTest.php';
require_once __DIR__ . '/Tier3_CrossFeatureTest.php';
require_once __DIR__ . '/Tier4_RealWorldScenariosTest.php';

// Parse command line arguments
$options = getopt('', ['tier:', 'strict', 'json::', 'help', 'verbose']);

if (isset($options['help'])) {
    echo "«ЗаботаРядом» Comprehensive E2E Test Suite Runner\n";
    echo "Usage: php tests/e2e_suite/run_e2e.php [options]\n\n";
    echo "Options:\n";
    echo "  --tier=1|2|3|4|all   Run a specific tier or all tiers (default: all)\n";
    echo "  --strict             Fail immediately if pending milestone features are absent\n";
    echo "  --json[=path]        Export structured JSON report (default: tests/e2e_report.json)\n";
    echo "  --verbose            Show detailed diagnostic logs\n";
    echo "  --help               Show this help message\n";
    exit(0);
}

$tierArg = $options['tier'] ?? 'all';
$strictMode = isset($options['strict']);
$verboseMode = isset($options['verbose']);
$jsonPath = null;
if (isset($options['json'])) {
    $jsonPath = is_string($options['json']) && $options['json'] !== '' 
        ? $options['json'] 
        : __DIR__ . '/../e2e_report.json';
}

$harness = TestHarness::getInstance();
$harness->setStrictMode($strictMode);
$harness->setVerbose($verboseMode);

echo "\n========================================================\n";
echo "   «ЗАБОТАРЯДОМ» OPAQUE-BOX E2E TEST TRACK (TIERS 1-4)\n";
echo "========================================================\n";
echo "  Mode: " . ($strictMode ? "STRICT (Zero-skip)" : "PROGRESSIVE (Milestone-aware)") . "\n";
echo "  Selected Tier: " . strtoupper((string)$tierArg) . "\n";
echo "  Base URL: " . $harness->getBaseUrl() . "\n";
echo "========================================================\n";

if ($tierArg === '1' || $tierArg === 'all') {
    $t1 = new Tier1_FeatureCoverageTest($harness);
    $t1->run();
}

if ($tierArg === '2' || $tierArg === 'all') {
    $t2 = new Tier2_BoundaryCornerTest($harness);
    $t2->run();
}

if ($tierArg === '3' || $tierArg === 'all') {
    $t3 = new Tier3_CrossFeatureTest($harness);
    $t3->run();
}

if ($tierArg === '4' || $tierArg === 'all') {
    $t4 = new Tier4_RealWorldScenariosTest($harness);
    $t4->run();
}

$harness->printSummary();

if ($jsonPath !== null) {
    $harness->exportJson($jsonPath);
    echo "  Report exported to: {$jsonPath}\n";
}

exit($harness->hasFailures() ? 1 : 0);
