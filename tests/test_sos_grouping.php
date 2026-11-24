<?php

declare(strict_types=1);

/**
 * Regression suite for the coordinator SOS console.
 *
 * Repeated SOS presses from the same senior must be grouped into one incident
 * and resolved with one action instead of forcing the coordinator to click the
 * same alert dozens of times.
 */

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/e2e_suite/TestHarness.php';

use App\Core\Database;
use Tests\E2ESuite\TestHarness;

$harness = TestHarness::getInstance();
$passed = 0;
$failed = 0;

function assertSosGroup(string $name, bool $condition, string $details = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $passed++;
        return;
    }
    $failed++;
    echo "  [FAIL] {$name}" . ($details !== '' ? " - {$details}" : '') . "\n";
}

echo "========================================================\n";
echo "   SOS GROUPING & BULK RESOLUTION TESTS\n";
echo "========================================================\n\n";

$elderlyToken = $harness->getElderlyToken();
$coordinatorToken = $harness->getCoordinatorToken();
$volunteerToken = $harness->getVolunteerToken();
$elderlyId = 1;
$created = 0;

// Create three signals from the same senior, as a panic burst would.
for ($i = 1; $i <= 3; $i++) {
    $res = $harness->apiCall('POST', '/api/sos', [
        'reason' => "[TEST-SOS-GROUP] повторный сигнал {$i}",
        'latitude' => 53.9168,
        'longitude' => 27.5862,
    ], ["Authorization: Bearer {$elderlyToken}"]);
    if (($res['data']['success'] ?? false) === true) {
        $created++;
    }
}
assertSosGroup('Three repeated SOS signals are created', $created === 3, "Created: {$created}/3");

$active = $harness->apiCall('GET', '/api/sos/active', null, ["Authorization: Bearer {$coordinatorToken}"]);
$activeAlerts = $active['data']['alerts'] ?? $active['data']['data'] ?? [];
$sameSenior = array_values(array_filter(
    $activeAlerts,
    static fn(array $alert): bool => (int)($alert['elderly_id'] ?? 0) === $elderlyId
));
assertSosGroup(
    'Coordinator sees all repeated signals for the same senior',
    count($sameSenior) >= 3,
    'Count: ' . count($sameSenior)
);

$volunteerAttempt = $harness->apiCall(
    'POST',
    "/api/sos/elderly/{$elderlyId}/resolve-all",
    null,
    ["Authorization: Bearer {$volunteerToken}"]
);
assertSosGroup(
    'Volunteer cannot bulk-resolve SOS signals (HTTP 403)',
    $volunteerAttempt['code'] === 403,
    "HTTP {$volunteerAttempt['code']}"
);

$resolve = $harness->apiCall(
    'POST',
    "/api/sos/elderly/{$elderlyId}/resolve-all",
    null,
    ["Authorization: Bearer {$coordinatorToken}"]
);
assertSosGroup(
    'Coordinator resolves all repeated signals in one action',
    $resolve['code'] === 200 && (int)($resolve['data']['resolved_count'] ?? 0) >= 3,
    "HTTP {$resolve['code']}, resolved=" . ($resolve['data']['resolved_count'] ?? 'null')
);

$after = $harness->apiCall('GET', '/api/sos/active', null, ["Authorization: Bearer {$coordinatorToken}"]);
$afterAlerts = $after['data']['alerts'] ?? $after['data']['data'] ?? [];
$remaining = array_values(array_filter(
    $afterAlerts,
    static fn(array $alert): bool => (int)($alert['elderly_id'] ?? 0) === $elderlyId
));
assertSosGroup(
    'No active signals remain for the resolved senior',
    $remaining === [],
    'Remaining: ' . count($remaining)
);

// Cleanup even if this suite runs standalone.
$pdo = Database::getConnection();
$pdo->exec("DELETE FROM sos_alerts WHERE reason LIKE '[TEST-SOS-GROUP]%'");

echo "\n--------------------------------------------------------\n";
echo "TEST RESULTS: Total: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================\n";

exit($failed === 0 ? 0 : 1);
