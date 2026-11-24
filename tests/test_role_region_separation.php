<?php

declare(strict_types=1);

/**
 * Regression suite for administrator/coordinator separation.
 *
 * A coordinator must only see and change data in their own region and must not
 * access system administration (users, passwords, content). An administrator
 * remains global.
 */

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Model.php';
require_once __DIR__ . '/e2e_suite/TestHarness.php';

use App\Core\Database;
use Tests\E2ESuite\TestHarness;

$harness = TestHarness::getInstance();
$passed = 0;
$failed = 0;

function assertRegion(string $name, bool $condition, string $details = ''): void
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
echo "   ADMIN / COORDINATOR REGION SEPARATION TESTS\n";
echo "========================================================\n\n";

$coordToken = $harness->getCoordinatorToken();
$adminToken = $harness->getAdminToken();
$pdo = Database::getConnection();

// A senior, a volunteer and a request in Brest (not the coordinator's Minsk).
$brestElderly = $harness->registerUser(1, 'Тест Брест Подопечный');
$brestElderlyToken = (string)($brestElderly['token'] ?? '');
$brestElderlyId = (int)($brestElderly['user']['id'] ?? 0);

$brestVolunteer = $harness->registerUser(2, 'Тест Брест Волонтёр');
$brestVolunteerId = (int)($brestVolunteer['user']['id'] ?? 0);

$brestRequest = $harness->createHelpRequest(
    $brestElderlyId,
    1,
    '[TEST-REGION] Брестская заявка',
    'Проверка региональной изоляции',
    'г. Брест, ул. Советская, 1',
    52.0976,
    23.7341,
    $brestElderlyToken
);
$brestRequestId = (int)($brestRequest['data']['request_id'] ?? 0);

assertRegion('Brest senior, volunteer and request created', $brestElderlyId > 0 && $brestVolunteerId > 0 && $brestRequestId > 0);

// Explicitly put the test volunteer in Brest and verify via the global admin.
$pdo->prepare("UPDATE volunteer_profiles SET region_code = 'BREST' WHERE user_id = :id")->execute(['id' => $brestVolunteerId]);
$harness->apiCall('POST', "/api/coordinator/volunteers/{$brestVolunteerId}/verify", null, ["Authorization: Bearer {$adminToken}"]);

$coordRequests = $harness->apiCall('GET', '/api/coordinator/requests', null, ["Authorization: Bearer {$coordToken}"]);
$coordRequestIds = array_column($coordRequests['data']['data'] ?? [], 'id');
assertRegion(
    'Coordinator does not see another region request',
    $coordRequests['code'] === 200 && !in_array($brestRequestId, array_map('intval', $coordRequestIds), true),
    'IDs: ' . implode(',', $coordRequestIds)
);

$coordForced = $harness->apiCall('GET', '/api/coordinator/requests?region=BREST', null, ["Authorization: Bearer {$coordToken}"]);
$forcedIds = array_column($coordForced['data']['data'] ?? [], 'id');
assertRegion(
    'Coordinator cannot override own region with ?region=BREST',
    $coordForced['code'] === 200 && !in_array($brestRequestId, array_map('intval', $forcedIds), true)
);

$adminRequests = $harness->apiCall('GET', '/api/coordinator/requests', null, ["Authorization: Bearer {$adminToken}"]);
$adminRequestIds = array_column($adminRequests['data']['data'] ?? [], 'id');
assertRegion(
    'Administrator sees the Brest request',
    $adminRequests['code'] === 200 && in_array($brestRequestId, array_map('intval', $adminRequestIds), true)
);

$coordStatus = $harness->apiCall(
    'POST',
    "/api/coordinator/requests/{$brestRequestId}/status",
    ['status' => 'cancelled'],
    ["Authorization: Bearer {$coordToken}"]
);
assertRegion(
    'Coordinator cannot change another region request',
    $coordStatus['code'] === 403,
    "HTTP {$coordStatus['code']}"
);

$coordVolunteers = $harness->apiCall('GET', '/api/coordinator/volunteers', null, ["Authorization: Bearer {$coordToken}"]);
$coordVolunteerIds = array_column($coordVolunteers['data']['data'] ?? [], 'user_id');
assertRegion(
    'Coordinator does not see another region volunteer',
    $coordVolunteers['code'] === 200 && !in_array($brestVolunteerId, array_map('intval', $coordVolunteerIds), true)
);

$adminVolunteers = $harness->apiCall('GET', '/api/coordinator/volunteers', null, ["Authorization: Bearer {$adminToken}"]);
$adminVolunteerIds = array_column($adminVolunteers['data']['data'] ?? [], 'user_id');
assertRegion(
    'Administrator sees the Brest volunteer',
    $adminVolunteers['code'] === 200 && in_array($brestVolunteerId, array_map('intval', $adminVolunteerIds), true)
);

// SOS in Brest must only be visible to the global admin, not the Minsk coordinator.
$pdo->prepare("UPDATE elderly_profiles SET region_code = 'BREST' WHERE user_id = :id")->execute(['id' => $brestElderlyId]);
$brestSos = $harness->apiCall('POST', '/api/sos', [
    'reason' => '[TEST-REGION] SOS из Бреста',
    'latitude' => 52.0976,
    'longitude' => 23.7341,
], ["Authorization: Bearer {$brestElderlyToken}"]);
$brestSosId = (int)($brestSos['data']['sos_id'] ?? 0);

$coordSos = $harness->apiCall('GET', '/api/sos/active', null, ["Authorization: Bearer {$coordToken}"]);
$coordSosIds = array_column($coordSos['data']['alerts'] ?? [], 'id');
$adminSos = $harness->apiCall('GET', '/api/sos/active', null, ["Authorization: Bearer {$adminToken}"]);
$adminSosIds = array_column($adminSos['data']['alerts'] ?? [], 'id');
assertRegion(
    'Coordinator does not see another region SOS; admin does',
    $brestSosId > 0
        && !in_array($brestSosId, array_map('intval', $coordSosIds), true)
        && in_array($brestSosId, array_map('intval', $adminSosIds), true)
);
$harness->apiCall('POST', "/api/sos/elderly/{$brestElderlyId}/resolve-all", null, ["Authorization: Bearer {$adminToken}"]);

// Fraud alerts are global or regional; the coordinator sees Minsk + global only.
$coordAlert = $harness->apiCall('POST', '/api/coordinator/alerts', [
    'title' => '[TEST-REGION] Минское оповещение',
    'description' => 'Проверка региона',
    'severity' => 'info',
], ["Authorization: Bearer {$coordToken}"]);
$adminAlert = $harness->apiCall('POST', '/api/coordinator/alerts', [
    'title' => '[TEST-REGION] Брестское оповещение',
    'description' => 'Проверка региона',
    'severity' => 'info',
    'region_code' => 'BREST',
], ["Authorization: Bearer {$adminToken}"]);
$coordAlerts = $harness->apiCall('GET', '/api/coordinator/alerts', null, ["Authorization: Bearer {$coordToken}"]);
$fraudTitles = array_column($coordAlerts['data']['fraud_alerts'] ?? [], 'title');
assertRegion(
    'Coordinator alert feed excludes another region alert',
    $coordAlert['code'] === 201
        && $adminAlert['code'] === 201
        && in_array('[TEST-REGION] Минское оповещение', $fraudTitles, true)
        && !in_array('[TEST-REGION] Брестское оповещение', $fraudTitles, true)
);

$coordStats = $harness->apiCall('GET', '/api/coordinator/stats', null, ["Authorization: Bearer {$coordToken}"]);
$adminStats = $harness->apiCall('GET', '/api/coordinator/stats', null, ["Authorization: Bearer {$adminToken}"]);
assertRegion(
    'Coordinator stats are scoped to Minsk',
    ($coordStats['data']['region'] ?? null) === 'MINSK_CITY'
        && (int)($coordStats['data']['stats']['total_requests'] ?? 0) < (int)($adminStats['data']['stats']['total_requests'] ?? 0),
    'coord=' . ($coordStats['data']['stats']['total_requests'] ?? 'null') . ', admin=' . ($adminStats['data']['stats']['total_requests'] ?? 'null')
);

$coordEducation = $harness->apiCall('POST', '/api/education', ['title' => 'x', 'content' => 'x'], ["Authorization: Bearer {$coordToken}"]);
$adminEducation = $harness->apiCall('POST', '/api/education', [], ["Authorization: Bearer {$adminToken}"]);
assertRegion(
    'Coordinator cannot manage educational materials',
    $coordEducation['code'] === 403 && $adminEducation['code'] === 400,
    "coord={$coordEducation['code']}, admin={$adminEducation['code']}"
);

$coordReset = $harness->apiCall('POST', '/api/coordinator/reset-code', [], ["Authorization: Bearer {$coordToken}"]);
$adminReset = $harness->apiCall('POST', '/api/coordinator/reset-code', [], ["Authorization: Bearer {$adminToken}"]);
assertRegion(
    'Coordinator cannot issue password-reset codes; admin can reach the endpoint',
    $coordReset['code'] === 403 && $adminReset['code'] === 400,
    "coord={$coordReset['code']}, admin={$adminReset['code']}"
);

$coordCategory = $harness->apiCall('POST', '/api/coordinator/categories', [], ["Authorization: Bearer {$coordToken}"]);
$adminCategory = $harness->apiCall('POST', '/api/coordinator/categories', [], ["Authorization: Bearer {$adminToken}"]);
assertRegion(
    'Coordinator cannot manage request categories; admin can reach the endpoint',
    $coordCategory['code'] === 403 && $adminCategory['code'] === 400,
    "coord={$coordCategory['code']}, admin={$adminCategory['code']}"
);

$coordAdminList = $harness->apiCall('GET', '/api/admin/coordinators', null, ["Authorization: Bearer {$coordToken}"]);
$adminAdminList = $harness->apiCall('GET', '/api/admin/coordinators', null, ["Authorization: Bearer {$adminToken}"]);
assertRegion(
    'Coordinator cannot access user/region administration',
    $coordAdminList['code'] === 403 && $adminAdminList['code'] === 200,
    "coord={$coordAdminList['code']}, admin={$adminAdminList['code']}"
);

// Admin can move the coordinator to Brest; the same token immediately sees Brest.
$coordinators = $adminAdminList['data']['data'] ?? [];
$coordinatorId = 0;
foreach ($coordinators as $row) {
    if (($row['phone'] ?? '') === '+375295556677') {
        $coordinatorId = (int)$row['id'];
        break;
    }
}
$coordinatorRegion = $harness->apiCall(
    'PUT',
    "/api/admin/users/{$coordinatorId}/region",
    ['region_code' => 'BREST'],
    ["Authorization: Bearer {$adminToken}"]
);
$brestScope = $harness->apiCall('GET', '/api/coordinator/requests', null, ["Authorization: Bearer {$coordToken}"]);
$brestScopeIds = array_column($brestScope['data']['data'] ?? [], 'id');
assertRegion(
    'Admin can assign a coordinator region and scoping follows immediately',
    $coordinatorRegion['code'] === 200
        && ($brestScope['data']['region'] ?? null) === 'BREST'
        && in_array($brestRequestId, array_map('intval', $brestScopeIds), true),
    "HTTP {$coordinatorRegion['code']}, region=" . ($brestScope['data']['region'] ?? 'null')
);

// Restore the demo coordinator and remove test data.
$harness->apiCall(
    'PUT',
    "/api/admin/users/{$coordinatorId}/region",
    ['region_code' => 'MINSK_CITY'],
    ["Authorization: Bearer {$adminToken}"]
);
$pdo->prepare('DELETE FROM help_requests WHERE id = :id')->execute(['id' => $brestRequestId]);
$pdo->prepare("DELETE FROM fraud_alerts WHERE title LIKE '[TEST-REGION]%'")->execute();
foreach ([$brestElderlyId, $brestVolunteerId] as $testUserId) {
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $testUserId]);
}

echo "\n--------------------------------------------------------\n";
echo "TEST RESULTS: Total: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================\n";

exit($failed === 0 ? 0 : 1);
