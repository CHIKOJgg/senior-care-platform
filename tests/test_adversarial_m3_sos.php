<?php

declare(strict_types=1);

/**
 * ADVERSARIAL STRESS TEST SUITE — MILESTONE 3: EMERGENCY SOS & TASK WATCHDOG
 *
 * Scopes Challenged Empirically:
 * 1. Emergency SOS Security & RBAC:
 *    - Unauthorized POST /api/sos without Bearer token strictly returns HTTP 401.
 *    - Empty Authorization header rejected with HTTP 401.
 *    - Invalid coordinates rejected with HTTP 400.
 *    - RBAC: Volunteer / Elderly cannot access active alerts or resolve alerts (HTTP 403).
 * 2. SOS Creation, Priority 0 & EMERCOM-112 Modular Dispatch:
 *    - Senior user creates SOS alert -> DB priority = 0, status = ACTIVE.
 *    - Valid ticket generated in format EMERCOM-112-BY-2026-XXXX.
 *    - Adapter checkIncidentStatus verifies ticket format and crew/ETA assignment.
 *    - Offline fallback queue: adapter catches simulated network disconnect and buffers incident.
 *    - Fallback to elderly profile coordinates when payload coordinates omitted.
 * 3. Alert Resolution & Active Query Filtering:
 *    - Active alert listed in GET /api/sos/active.
 *    - Coordinator resolves alert -> status = RESOLVED, resolved_at recorded.
 *    - Resolved alert strictly excluded from GET /api/sos/active and aliases.
 * 4. Task Watchdog Category Timeout Boundaries:
 *    - Pharmacy (3600s): 3599s (not overdue) vs 3600s (boundary) vs 3601s (overdue).
 *    - Groceries (7200s): 7199s (not overdue) vs 7200s (boundary) vs 7201s (overdue).
 *    - Household/default (10800s): 10799s (not overdue) vs 10800s (boundary) vs 10801s (overdue).
 *    - HTTP runner POST /api/tasks/watchdog/run & GET /api/coordinator/watchdog verification.
 * 5. Stale Task Reassignment Lifecycle:
 *    - RBAC enforcement: Volunteer & Elderly cannot reassign (HTTP 403).
 *    - Coordinator reassigns: status -> open, volunteer_id -> NULL, is_overdue -> 0, assigned_at -> NULL.
 *    - Immediately appears in public feed GET /api/requests for other volunteers.
 *    - Volunteer accepts reassigned request successfully.
 * 6. SQLite Database Cleanup & Foreign Key Integrity:
 *    - Purge all test SOS alerts and test help requests.
 *    - Zero orphaned records.
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
require_once __DIR__ . '/../app/Models/HelpRequest.php';
require_once __DIR__ . '/../app/Models/ElderlyProfile.php';
require_once __DIR__ . '/../app/Models/VolunteerProfile.php';
require_once __DIR__ . '/../app/Models/SosAlert.php';
require_once __DIR__ . '/../app/Services/GeoMatchingService.php';
require_once __DIR__ . '/../app/Services/Emergency/EmergencyAlertAdapterInterface.php';
require_once __DIR__ . '/../app/Services/Emergency/MchsEmergencyAdapter.php';
require_once __DIR__ . '/../app/Services/TaskWatchdogService.php';
require_once __DIR__ . '/../app/Services/AuthService.php';
require_once __DIR__ . '/e2e_suite/TestHarness.php';

use App\Core\Database;
use App\Models\HelpRequest;
use App\Models\SosAlert;
use App\Models\ElderlyProfile;
use App\Models\VolunteerProfile;
use App\Services\TaskWatchdogService;
use App\Services\Emergency\MchsEmergencyAdapter;
use App\Services\Emergency\EmergencyAlertAdapterInterface;
use Tests\E2ESuite\TestHarness;

echo "================================================================================\n";
echo "   ADVERSARIAL STRESS TEST SUITE: MILESTONE 3 (SOS & TASK WATCHDOG)            \n";
echo "================================================================================\n\n";

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;
$startTime = microtime(true);

$createdSosIds = [];
$createdRequestIds = [];

function assertAdv(string $code, string $description, bool $condition, string $details = ''): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  \033[32m[PASS]\033[0m {$code}: {$description}\n";
    } else {
        $failedTests++;
        echo "  \033[31m[FAIL]\033[0m {$code}: {$description} - Details: {$details}\n";
    }
}

$harness = TestHarness::getInstance('http://127.0.0.1:8000');
$elderlyToken = $harness->getElderlyToken();
$volunteerToken = $harness->getVolunteerToken();
$coordinatorToken = $harness->getCoordinatorToken();
$adminToken = $harness->getAdminToken();

$pdo = Database::getConnection();

try {
    // =========================================================================
    // SUITE 1: Emergency SOS Security & RBAC Enforcement
    // =========================================================================
    echo "[SUITE 1] Emergency SOS Security & RBAC Enforcement:\n";

    // 1.1: Anonymous POST /api/sos without Bearer token strictly returns HTTP 401
    $resAnon = $harness->apiCall('POST', '/api/sos', [
        'latitude' => 53.9168,
        'longitude' => 27.5862,
        'reason' => 'Anonymous attack test'
    ]);
    assertAdv('ADV-SOS-SEC-01', 'Anonymous POST /api/sos without Bearer token strictly returns HTTP 401',
        $resAnon['code'] === 401 && isset($resAnon['data']['error']),
        "HTTP {$resAnon['code']}, response: " . ($resAnon['raw'] ?? '')
    );

    // 1.2: POST /api/sos with empty string Authorization header returns HTTP 401
    $resEmptyAuth = $harness->apiCall('POST', '/api/sos', [
        'latitude' => 53.9168,
        'longitude' => 27.5862
    ], ['Authorization: ']);
    assertAdv('ADV-SOS-SEC-02', 'POST /api/sos with empty Authorization header returns HTTP 401',
        $resEmptyAuth['code'] === 401,
        "HTTP {$resEmptyAuth['code']}"
    );

    // 1.3: POST /api/sos with invalid geographical coordinates returns HTTP 400
    $resBadLat = $harness->apiCall('POST', '/api/sos', [
        'latitude' => 95.0, // Invalid latitude (> 90)
        'longitude' => 27.5862
    ], ["Authorization: Bearer {$elderlyToken}"]);
    assertAdv('ADV-SOS-SEC-03', 'POST /api/sos with out-of-range latitude (95.0) strictly rejected with HTTP 400',
        $resBadLat['code'] === 400 && str_contains($resBadLat['data']['error'] ?? '', 'координаты'),
        "HTTP {$resBadLat['code']}, err: " . ($resBadLat['data']['error'] ?? '')
    );

    $resBadLon = $harness->apiCall('POST', '/api/sos', [
        'latitude' => 53.9168,
        'longitude' => -185.0 // Invalid longitude (< -180)
    ], ["Authorization: Bearer {$elderlyToken}"]);
    assertAdv('ADV-SOS-SEC-04', 'POST /api/sos with out-of-range longitude (-185.0) strictly rejected with HTTP 400',
        $resBadLon['code'] === 400,
        "HTTP {$resBadLon['code']}"
    );

    // 1.4: Non-coordinator (Volunteer) cannot list active SOS alerts (HTTP 403)
    $resVolActive = $harness->apiCall('GET', '/api/sos/active', null, ["Authorization: Bearer {$volunteerToken}"]);
    assertAdv('ADV-SOS-SEC-05', 'Volunteer cannot query GET /api/sos/active (returns HTTP 403 Forbidden)',
        $resVolActive['code'] === 403,
        "HTTP {$resVolActive['code']}"
    );

    // 1.5: Non-coordinator (Volunteer) cannot resolve SOS alerts (HTTP 403)
    $resVolResolve = $harness->apiCall('POST', '/api/sos/1/resolve', null, ["Authorization: Bearer {$volunteerToken}"]);
    assertAdv('ADV-SOS-SEC-06', 'Volunteer cannot call POST /api/sos/{id}/resolve (returns HTTP 403 Forbidden)',
        $resVolResolve['code'] === 403,
        "HTTP {$resVolResolve['code']}"
    );

    // 1.6: Non-coordinator (Elderly) cannot resolve SOS alerts (HTTP 403)
    $resElderlyResolve = $harness->apiCall('POST', '/api/sos/1/resolve', null, ["Authorization: Bearer {$elderlyToken}"]);
    assertAdv('ADV-SOS-SEC-07', 'Elderly user cannot call POST /api/sos/{id}/resolve (returns HTTP 403 Forbidden)',
        $resElderlyResolve['code'] === 403,
        "HTTP {$resElderlyResolve['code']}"
    );

    // 1.7: Coordinator resolving non-existent SOS ID returns HTTP 404
    $res404 = $harness->apiCall('POST', '/api/sos/99999999/resolve', null, ["Authorization: Bearer {$coordinatorToken}"]);
    assertAdv('ADV-SOS-SEC-08', 'Resolving non-existent SOS alert returns HTTP 404 Not Found',
        $res404['code'] === 404,
        "HTTP {$res404['code']}"
    );


    // =========================================================================
    // SUITE 2: SOS Creation, Priority 0, EMERCOM Dispatch & Offline Fallback Queue
    // =========================================================================
    echo "\n[SUITE 2] Emergency SOS Creation, Priority 0, EMERCOM Dispatch & Offline Fallback Queue:\n";

    // 2.1: Senior user creates SOS alert with explicit coordinates
    $testReason1 = '[ADV-TEST-M3-SOS-01] Экстренная помощь: головокружение и падение';
    $resSos1 = $harness->apiCall('POST', '/api/sos', [
        'latitude' => 53.9168,
        'longitude' => 27.5862,
        'address' => 'г. Минск, ул. Кульман, д. 15, кв. 42',
        'reason' => $testReason1
    ], ["Authorization: Bearer {$elderlyToken}"]);

    assertAdv('ADV-SOS-DSP-01', 'Senior user POST /api/sos returns HTTP 201 Created',
        $resSos1['code'] === 201 && ($resSos1['data']['success'] ?? false) === true,
        "HTTP {$resSos1['code']}"
    );

    $sosId1 = (int)($resSos1['data']['sos_id'] ?? 0);
    $createdSosIds[] = $sosId1;

    // 2.2: Verify response metadata: priority = 0, status = ACTIVE
    assertAdv('ADV-SOS-DSP-02', 'SOS alert has priority = 0 and status = ACTIVE in response',
        ($resSos1['data']['priority'] ?? -1) === 0 && ($resSos1['data']['status'] ?? '') === 'ACTIVE',
        "Priority: " . ($resSos1['data']['priority'] ?? 'null') . ", Status: " . ($resSos1['data']['status'] ?? 'null')
    );

    // 2.3: Verify SQLite database record directly
    $dbAlert1 = SosAlert::find($sosId1);
    assertAdv('ADV-SOS-DSP-03', 'Database record matches: priority = 0, status = ACTIVE, non-null created_at',
        $dbAlert1 !== null &&
        (int)$dbAlert1['priority'] === 0 &&
        $dbAlert1['status'] === 'ACTIVE' &&
        !empty($dbAlert1['created_at']) &&
        $dbAlert1['resolved_at'] === null,
        "DB row: " . json_encode($dbAlert1, JSON_UNESCAPED_UNICODE)
    );

    // 2.4: Verify EMERCOM ticket code format
    $ticketId1 = (string)($resSos1['data']['emercom_ticket_id'] ?? '');
    $ticketValid = MchsEmergencyAdapter::verifyTicketId($ticketId1);
    assertAdv('ADV-SOS-DSP-04', 'EMERCOM ticket ID strictly conforms to EMERCOM-112-BY-YYYY-XXXX regex',
        $ticketValid && str_starts_with($ticketId1, 'EMERCOM-112-BY-') && preg_match('/^EMERCOM-112-BY-\d{4}-[A-Z0-9]{4,}$/', $ticketId1) === 1,
        "Ticket: {$ticketId1}"
    );

    // 2.5: Verify adapter checkIncidentStatus method
    $adapter = new MchsEmergencyAdapter();
    $statusCheck = $adapter->checkIncidentStatus($ticketId1);
    assertAdv('ADV-SOS-DSP-05', 'MchsEmergencyAdapter::checkIncidentStatus returns DISPATCHED with crew and ETA',
        ($statusCheck['status'] ?? '') === 'DISPATCHED' &&
        !empty($statusCheck['assigned_crew']) &&
        isset($statusCheck['eta_minutes']),
        "Status: " . json_encode($statusCheck, JSON_UNESCAPED_UNICODE)
    );

    // 2.6: Offline Fallback Queue when dispatch simulated offline/failure via HTTP API
    $testReason2 = '[ADV-TEST-M3-SOS-02] Экстренный вызов при симуляции обрыва сети МЧС';
    $resSosOffline = $harness->apiCall('POST', '/api/sos', [
        'latitude' => 53.9168,
        'longitude' => 27.5862,
        'reason' => $testReason2,
        'simulate_network_failure' => true
    ], ["Authorization: Bearer {$elderlyToken}"]);

    $sosIdOffline = (int)($resSosOffline['data']['sos_id'] ?? 0);
    $createdSosIds[] = $sosIdOffline;

    assertAdv('ADV-SOS-DSP-06', 'Offline fallback HTTP API: network failure response indicates queued_offline = true and fallback note',
        $resSosOffline['code'] === 201 &&
        ($resSosOffline['data']['dispatch']['queued_offline'] ?? false) === true &&
        str_contains($resSosOffline['data']['dispatch']['note'] ?? '', 'очередь'),
        "Response: " . json_encode($resSosOffline['data'], JSON_UNESCAPED_UNICODE)
    );

    // 2.7: Adapter In-Process Offline Fallback Queue Verification
    $adapterDirect = new MchsEmergencyAdapter();
    $offlineDispatchDirect = $adapterDirect->dispatchEmergency([
        'elderly_id' => 1,
        'latitude' => 53.9168,
        'longitude' => 27.5862,
        'simulate_network_failure' => true
    ]);
    $offlineQueueItems = MchsEmergencyAdapter::getOfflineQueue();
    assertAdv('ADV-SOS-DSP-07', 'MchsEmergencyAdapter resiliently buffers failed dispatch into internal offline retry queue',
        ($offlineDispatchDirect['queued_offline'] ?? false) === true &&
        count($offlineQueueItems) > 0 &&
        !empty($offlineQueueItems[count($offlineQueueItems) - 1]['ticket_id']),
        "Queue count: " . count($offlineQueueItems)
    );

    // 2.8: Profile Fallback Coordinates when empty payload is submitted
    $resSosProfile = $harness->apiCall('POST', '/api/sos', [], ["Authorization: Bearer {$elderlyToken}"]);
    $sosIdProfile = (int)($resSosProfile['data']['sos_id'] ?? 0);
    $createdSosIds[] = $sosIdProfile;
    $dbAlertProfile = SosAlert::find($sosIdProfile);

    assertAdv('ADV-SOS-DSP-08', 'Profile fallback: empty payload derives coordinates and address from elderly profile',
        $resSosProfile['code'] === 201 &&
        $dbAlertProfile !== null &&
        abs((float)$dbAlertProfile['latitude'] - 53.9168) < 0.001 &&
        abs((float)$dbAlertProfile['longitude'] - 27.5862) < 0.001,
        "Lat: " . ($dbAlertProfile['latitude'] ?? 'null') . ", Lon: " . ($dbAlertProfile['longitude'] ?? 'null')
    );


    // =========================================================================
    // SUITE 3: SOS Alert Resolution & Active Filter Query Verification
    // =========================================================================
    echo "\n[SUITE 3] SOS Alert Resolution & Active Filter Query Verification:\n";

    // 3.1: Active alerts query includes newly created active alerts
    $resActiveBefore = $harness->apiCall('GET', '/api/sos/active', null, ["Authorization: Bearer {$coordinatorToken}"]);
    $activeListBefore = $resActiveBefore['data']['data'] ?? [];
    $activeIdsBefore = array_column($activeListBefore, 'id');

    assertAdv('ADV-SOS-RES-01', 'GET /api/sos/active contains newly triggered SOS alert #' . $sosId1,
        in_array($sosId1, $activeIdsBefore, false),
        "Active IDs: " . implode(', ', $activeIdsBefore)
    );

    // 3.2: Coordinator resolves SOS alert #sosId1
    $resResolve = $harness->apiCall('POST', "/api/sos/{$sosId1}/resolve", null, ["Authorization: Bearer {$coordinatorToken}"]);
    assertAdv('ADV-SOS-RES-02', 'Coordinator POST /api/sos/{id}/resolve returns HTTP 200 with status RESOLVED',
        $resResolve['code'] === 200 &&
        ($resResolve['data']['status'] ?? '') === 'RESOLVED' &&
        !empty($resResolve['data']['resolved_at']),
        "Response: " . ($resResolve['raw'] ?? '')
    );

    // 3.3: Verify database state after resolution
    $dbAlertResolved = SosAlert::find($sosId1);
    assertAdv('ADV-SOS-RES-03', 'Database record transitions to status = RESOLVED and records resolved_at timestamp',
        $dbAlertResolved !== null &&
        $dbAlertResolved['status'] === 'RESOLVED' &&
        !empty($dbAlertResolved['resolved_at']),
        "Status: " . ($dbAlertResolved['status'] ?? 'null') . ", resolved_at: " . ($dbAlertResolved['resolved_at'] ?? 'null')
    );

    // 3.4: Filter query verification: resolved alert is EXCLUDED from GET /api/sos/active
    $resActiveAfter = $harness->apiCall('GET', '/api/sos/active', null, ["Authorization: Bearer {$coordinatorToken}"]);
    $activeListAfter = $resActiveAfter['data']['data'] ?? [];
    $activeIdsAfter = array_column($activeListAfter, 'id');

    assertAdv('ADV-SOS-RES-04', 'Resolved SOS alert #' . $sosId1 . ' is strictly excluded from GET /api/sos/active',
        !in_array($sosId1, $activeIdsAfter, false),
        "Active IDs after resolution: " . implode(', ', $activeIdsAfter)
    );

    // 3.5: Coordinator alias routes also exclude resolved alert
    $resAlias1 = $harness->apiCall('GET', '/api/coordinator/sos-alerts', null, ["Authorization: Bearer {$coordinatorToken}"]);
    $aliasIds1 = array_column($resAlias1['data']['data'] ?? [], 'id');
    assertAdv('ADV-SOS-RES-05', 'Coordinator alias GET /api/coordinator/sos-alerts excludes resolved alert',
        !in_array($sosId1, $aliasIds1, false),
        "Alias IDs: " . implode(', ', $aliasIds1)
    );

    // 3.6: Resolve remaining test alerts
    $harness->apiCall('POST', "/api/sos/{$sosIdOffline}/resolve", null, ["Authorization: Bearer {$coordinatorToken}"]);
    $harness->apiCall('POST', "/api/sos/{$sosIdProfile}/resolve", null, ["Authorization: Bearer {$coordinatorToken}"]);
    assertAdv('ADV-SOS-RES-06', 'All test alerts successfully resolved by coordinator',
        SosAlert::find($sosIdOffline)['status'] === 'RESOLVED' &&
        SosAlert::find($sosIdProfile)['status'] === 'RESOLVED'
    );


    // =========================================================================
    // SUITE 4: Task Watchdog Boundary Conditions — Category Timeouts
    // =========================================================================
    echo "\n[SUITE 4] Task Watchdog Boundary Conditions — Category Timeouts:\n";

    /**
     * Categories:
     * - Pharmacy (id = 2, code = 'pharmacy'): timeout = 3600 seconds
     * - Groceries (id = 1, code = 'groceries'): timeout = 7200 seconds
     * - Household (id = 3, code = 'household'): timeout = 10800 seconds
     */

    // Subsuite 4.1: Pharmacy / Medical (Timeout: 3600s)
    echo "  -- Category: Pharmacy (3600s):\n";

    $reqPharm = $harness->createHelpRequest(1, 2, '[ADV-TEST-M3-WDC] Тест аптеки 3600с');
    $pharmId = (int)$reqPharm['data']['request_id'];
    $createdRequestIds[] = $pharmId;

    $harness->apiCall('POST', "/api/requests/{$pharmId}/accept", ['volunteer_id' => 2]);

    $nowRef = 1730000000;

    // Boundary 4.1.1: Assigned 3599s ago -> NOT overdue (is_overdue = 0)
    $time3599 = date('Y-m-d H:i:s', $nowRef - 3599);
    $pdo->exec("UPDATE help_requests SET assigned_at = '{$time3599}', is_overdue = 0 WHERE id = {$pharmId}");
    TaskWatchdogService::checkOverdueTasks($nowRef);
    $req3599 = HelpRequest::find($pharmId);
    assertAdv('ADV-WDC-BND-01', 'Pharmacy (3600s): assigned 3599s ago -> is_overdue MUST be false (0)',
        (int)$req3599['is_overdue'] === 0,
        "is_overdue: {$req3599['is_overdue']}"
    );

    // Boundary 4.1.2: Assigned exactly 3600s ago -> NOT overdue (boundary condition: 3600 > 3600 is false)
    $time3600 = date('Y-m-d H:i:s', $nowRef - 3600);
    $pdo->exec("UPDATE help_requests SET assigned_at = '{$time3600}', is_overdue = 0 WHERE id = {$pharmId}");
    TaskWatchdogService::checkOverdueTasks($nowRef);
    $req3600 = HelpRequest::find($pharmId);
    assertAdv('ADV-WDC-BND-02', 'Pharmacy (3600s): assigned exactly 3600s ago -> is_overdue MUST be false (0)',
        (int)$req3600['is_overdue'] === 0,
        "is_overdue: {$req3600['is_overdue']}"
    );

    // Boundary 4.1.3: Assigned 3601s ago -> OVERDUE (is_overdue = 1)
    $time3601 = date('Y-m-d H:i:s', $nowRef - 3601);
    $pdo->exec("UPDATE help_requests SET assigned_at = '{$time3601}', is_overdue = 0 WHERE id = {$pharmId}");
    $scanPharm = TaskWatchdogService::checkOverdueTasks($nowRef);
    $req3601 = HelpRequest::find($pharmId);
    assertAdv('ADV-WDC-BND-03', 'Pharmacy (3600s): assigned 3601s ago -> is_overdue MUST be true (1)',
        (int)$req3601['is_overdue'] === 1,
        "is_overdue: {$req3601['is_overdue']}, scan result: " . json_encode($scanPharm)
    );


    // Subsuite 4.2: Groceries / Food (Timeout: 7200s)
    echo "  -- Category: Groceries (7200s):\n";

    $reqGroc = $harness->createHelpRequest(1, 1, '[ADV-TEST-M3-WDC] Тест продуктов 7200с');
    $grocId = (int)$reqGroc['data']['request_id'];
    $createdRequestIds[] = $grocId;
    $harness->apiCall('POST', "/api/requests/{$grocId}/accept", ['volunteer_id' => 2]);

    // Boundary 4.2.1: Assigned 7199s ago -> NOT overdue (is_overdue = 0)
    $time7199 = date('Y-m-d H:i:s', $nowRef - 7199);
    $pdo->exec("UPDATE help_requests SET assigned_at = '{$time7199}', is_overdue = 0 WHERE id = {$grocId}");
    TaskWatchdogService::checkOverdueTasks($nowRef);
    $req7199 = HelpRequest::find($grocId);
    assertAdv('ADV-WDC-BND-04', 'Groceries (7200s): assigned 7199s ago -> is_overdue MUST be false (0)',
        (int)$req7199['is_overdue'] === 0,
        "is_overdue: {$req7199['is_overdue']}"
    );

    // Boundary 4.2.2: Assigned exactly 7200s ago -> NOT overdue (boundary condition)
    $time7200 = date('Y-m-d H:i:s', $nowRef - 7200);
    $pdo->exec("UPDATE help_requests SET assigned_at = '{$time7200}', is_overdue = 0 WHERE id = {$grocId}");
    TaskWatchdogService::checkOverdueTasks($nowRef);
    $req7200 = HelpRequest::find($grocId);
    assertAdv('ADV-WDC-BND-05', 'Groceries (7200s): assigned exactly 7200s ago -> is_overdue MUST be false (0)',
        (int)$req7200['is_overdue'] === 0,
        "is_overdue: {$req7200['is_overdue']}"
    );

    // Boundary 4.2.3: Assigned 7201s ago -> OVERDUE (is_overdue = 1)
    $time7201 = date('Y-m-d H:i:s', $nowRef - 7201);
    $pdo->exec("UPDATE help_requests SET assigned_at = '{$time7201}', is_overdue = 0 WHERE id = {$grocId}");
    $scanGroc = TaskWatchdogService::checkOverdueTasks($nowRef);
    $req7201 = HelpRequest::find($grocId);
    assertAdv('ADV-WDC-BND-06', 'Groceries (7200s): assigned 7201s ago -> is_overdue MUST be true (1)',
        (int)$req7201['is_overdue'] === 1,
        "is_overdue: {$req7201['is_overdue']}"
    );


    // Subsuite 4.3: Household / Default (Timeout: 10800s)
    echo "  -- Category: Household/Default (10800s):\n";

    $reqHouse = $harness->createHelpRequest(1, 3, '[ADV-TEST-M3-WDC] Тест бытовой помощи 10800с');
    $houseId = (int)$reqHouse['data']['request_id'];
    $createdRequestIds[] = $houseId;
    $harness->apiCall('POST', "/api/requests/{$houseId}/accept", ['volunteer_id' => 2]);

    // Boundary 4.3.1: Assigned 10799s ago -> NOT overdue (is_overdue = 0)
    $time10799 = date('Y-m-d H:i:s', $nowRef - 10799);
    $pdo->exec("UPDATE help_requests SET assigned_at = '{$time10799}', is_overdue = 0 WHERE id = {$houseId}");
    TaskWatchdogService::checkOverdueTasks($nowRef);
    $req10799 = HelpRequest::find($houseId);
    assertAdv('ADV-WDC-BND-07', 'Household (10800s): assigned 10799s ago -> is_overdue MUST be false (0)',
        (int)$req10799['is_overdue'] === 0,
        "is_overdue: {$req10799['is_overdue']}"
    );

    // Boundary 4.3.2: Assigned exactly 10800s ago -> NOT overdue (boundary condition)
    $time10800 = date('Y-m-d H:i:s', $nowRef - 10800);
    $pdo->exec("UPDATE help_requests SET assigned_at = '{$time10800}', is_overdue = 0 WHERE id = {$houseId}");
    TaskWatchdogService::checkOverdueTasks($nowRef);
    $req10800 = HelpRequest::find($houseId);
    assertAdv('ADV-WDC-BND-08', 'Household (10800s): assigned exactly 10800s ago -> is_overdue MUST be false (0)',
        (int)$req10800['is_overdue'] === 0,
        "is_overdue: {$req10800['is_overdue']}"
    );

    // Boundary 4.3.3: Assigned 10801s ago -> OVERDUE (is_overdue = 1)
    $time10801 = date('Y-m-d H:i:s', $nowRef - 10801);
    $pdo->exec("UPDATE help_requests SET assigned_at = '{$time10801}', is_overdue = 0 WHERE id = {$houseId}");
    $scanHouse = TaskWatchdogService::checkOverdueTasks($nowRef);
    $req10801 = HelpRequest::find($houseId);
    assertAdv('ADV-WDC-BND-09', 'Household (10800s): assigned 10801s ago -> is_overdue MUST be true (1)',
        (int)$req10801['is_overdue'] === 1,
        "is_overdue: {$req10801['is_overdue']}"
    );


    // Subsuite 4.4: Watchdog HTTP Endpoint Scan & Coordinator Feed
    echo "  -- HTTP Watchdog Endpoints:\n";

    // Set real-time overdue timestamps against current time() for HTTP endpoint test
    $realOverdueTime = date('Y-m-d H:i:s', time() - 4000); // 4000s > 3600s
    $pdo->exec("UPDATE help_requests SET assigned_at = '{$realOverdueTime}', is_overdue = 0 WHERE id = {$pharmId}");

    // Call POST /api/tasks/watchdog/run
    $resRun = $harness->apiCall('POST', '/api/tasks/watchdog/run', null, ["Authorization: Bearer {$coordinatorToken}"]);
    assertAdv('ADV-WDC-BND-10', 'POST /api/tasks/watchdog/run executes scan and returns overdue summary',
        $resRun['code'] === 200 && ($resRun['data']['success'] ?? false) === true && ($resRun['data']['overdue_found'] ?? 0) >= 1,
        "Response: " . json_encode($resRun['data'], JSON_UNESCAPED_UNICODE)
    );

    // Call GET /api/coordinator/watchdog
    $resWdcFeed = $harness->apiCall('GET', '/api/coordinator/watchdog', null, ["Authorization: Bearer {$coordinatorToken}"]);
    $wdcItems = $resWdcFeed['data']['data'] ?? [];
    $wdcPharmItem = null;
    foreach ($wdcItems as $item) {
        if ((int)$item['id'] === $pharmId) {
            $wdcPharmItem = $item;
            break;
        }
    }

    assertAdv('ADV-WDC-BND-11', 'GET /api/coordinator/watchdog lists overdue task with computed elapsed_minutes and overdue_by_minutes',
        $wdcPharmItem !== null &&
        (int)$wdcPharmItem['is_overdue'] === 1 &&
        ($wdcPharmItem['elapsed_minutes'] ?? 0) >= 60 &&
        isset($wdcPharmItem['overdue_by_minutes']),
        "Task in feed: " . json_encode($wdcPharmItem, JSON_UNESCAPED_UNICODE)
    );


    // =========================================================================
    // SUITE 5: Stale Task Reassignment Lifecycle & Concurrency
    // =========================================================================
    echo "\n[SUITE 5] Stale Task Reassignment Lifecycle & Concurrency:\n";

    // 5.1: RBAC: Volunteer reassign call rejected with HTTP 403
    $resReassignVol = $harness->apiCall('POST', "/api/coordinator/requests/{$pharmId}/reassign", null, ["Authorization: Bearer {$volunteerToken}"]);
    assertAdv('ADV-WDC-REA-01', 'Volunteer POST /api/coordinator/requests/{id}/reassign strictly rejected with HTTP 403 Forbidden',
        $resReassignVol['code'] === 403,
        "HTTP {$resReassignVol['code']}"
    );

    // 5.2: RBAC: Elderly reassign call rejected with HTTP 403
    $resReassignElderly = $harness->apiCall('POST', "/api/coordinator/requests/{$pharmId}/reassign", null, ["Authorization: Bearer {$elderlyToken}"]);
    assertAdv('ADV-WDC-REA-02', 'Elderly POST /api/coordinator/requests/{id}/reassign strictly rejected with HTTP 403 Forbidden',
        $resReassignElderly['code'] === 403,
        "HTTP {$resReassignElderly['code']}"
    );

    // 5.3: Reassign non-existent request returns HTTP 404
    $resReassign404 = $harness->apiCall('POST', '/api/coordinator/requests/999999/reassign', null, ["Authorization: Bearer {$coordinatorToken}"]);
    assertAdv('ADV-WDC-REA-03', 'Reassigning non-existent request returns HTTP 404 Not Found',
        $resReassign404['code'] === 404,
        "HTTP {$resReassign404['code']}"
    );

    // 5.4: Coordinator executes reassignment of overdue task
    $resReassign = $harness->apiCall('POST', "/api/coordinator/requests/{$pharmId}/reassign", null, ["Authorization: Bearer {$coordinatorToken}"]);
    assertAdv('ADV-WDC-REA-04', 'Coordinator POST /api/coordinator/requests/{id}/reassign returns HTTP 200 with status open',
        $resReassign['code'] === 200 && ($resReassign['data']['status'] ?? '') === 'open',
        "HTTP {$resReassign['code']}, body: " . ($resReassign['raw'] ?? '')
    );

    // 5.5: Verify complete database reset:
    // status -> open, volunteer_id -> NULL, is_overdue -> 0, assigned_at -> NULL
    $reassignedDb = HelpRequest::find($pharmId);
    assertAdv('ADV-WDC-REA-05', 'Database: status transitioned back to open',
        $reassignedDb['status'] === 'open',
        "Status: " . ($reassignedDb['status'] ?? 'null')
    );

    assertAdv('ADV-WDC-REA-06', 'Database: volunteer_id set to NULL',
        $reassignedDb['volunteer_id'] === null,
        "volunteer_id: " . var_export($reassignedDb['volunteer_id'], true)
    );

    assertAdv('ADV-WDC-REA-07', 'Database: is_overdue reset to 0',
        (int)$reassignedDb['is_overdue'] === 0,
        "is_overdue: " . ($reassignedDb['is_overdue'] ?? 'null')
    );

    assertAdv('ADV-WDC-REA-08', 'Database: assigned_at reset to NULL',
        $reassignedDb['assigned_at'] === null,
        "assigned_at: " . var_export($reassignedDb['assigned_at'], true)
    );

    // 5.6: Reassigned task immediately appears in public feed GET /api/requests
    $resFeed = $harness->apiCall('GET', '/api/requests', null, ["Authorization: Bearer {$volunteerToken}"]);
    $openFeed = $resFeed['data']['data'] ?? [];
    $feedIds = array_column($openFeed, 'id');
    assertAdv('ADV-WDC-REA-09', 'Task is immediately visible in open feed GET /api/requests for volunteers',
        in_array($pharmId, $feedIds, false),
        "Open feed count: " . count($openFeed)
    );

    // 5.7: Reassigned task can be accepted again by volunteer
    $resAcceptAgain = $harness->apiCall('POST', "/api/requests/{$pharmId}/accept", ['volunteer_id' => 2], ["Authorization: Bearer {$volunteerToken}"]);
    $acceptedDb = HelpRequest::find($pharmId);
    assertAdv('ADV-WDC-REA-10', 'Volunteer can accept reassigned task: status becomes in_progress and assigned_at is updated',
        $resAcceptAgain['code'] === 200 &&
        $acceptedDb['status'] === 'in_progress' &&
        (int)$acceptedDb['volunteer_id'] === 2 &&
        !empty($acceptedDb['assigned_at']),
        "Status: {$acceptedDb['status']}, assigned_at: {$acceptedDb['assigned_at']}"
    );

} finally {
    // =========================================================================
    // SUITE 6: SQLite Database Cleanup & Foreign Key Integrity
    // =========================================================================
    echo "\n[SUITE 6] SQLite Database Cleanup & State Integrity Verification:\n";

    // 6.1: Clean up temporary SOS alerts created during this run
    $deletedSosCount = 0;
    if (!empty($createdSosIds)) {
        $inSos = implode(',', array_map('intval', $createdSosIds));
        $deletedSosCount = $pdo->exec("DELETE FROM sos_alerts WHERE id IN ({$inSos})");
    }
    // Also clean up any SOS alerts matching adversarial test reason prefix
    $deletedExtraSos = $pdo->exec("DELETE FROM sos_alerts WHERE reason LIKE '[ADV-TEST-M3%'");

    assertAdv('ADV-CLN-01', 'Temporary SOS alerts purged from SQLite sos_alerts table',
        ($deletedSosCount + $deletedExtraSos) >= count($createdSosIds),
        "Deleted count: " . ($deletedSosCount + $deletedExtraSos)
    );

    // 6.2: Clean up temporary help requests created during this run
    $deletedReqCount = 0;
    if (!empty($createdRequestIds)) {
        $inReq = implode(',', array_map('intval', $createdRequestIds));
        $deletedReqCount = $pdo->exec("DELETE FROM help_requests WHERE id IN ({$inReq})");
    }
    $deletedExtraReq = $pdo->exec("DELETE FROM help_requests WHERE title LIKE '[ADV-TEST-M3%'");

    assertAdv('ADV-CLN-02', 'Temporary help requests purged from SQLite help_requests table',
        ($deletedReqCount + $deletedExtraReq) >= count($createdRequestIds),
        "Deleted count: " . ($deletedReqCount + $deletedExtraReq)
    );

    // 6.3: Foreign key check: verify zero orphaned records
    $orphanedReqQuery = $pdo->query("SELECT COUNT(*) FROM help_requests hr LEFT JOIN users u ON u.id = hr.elderly_id WHERE u.id IS NULL");
    $orphanedReq = (int)$orphanedReqQuery->fetchColumn();

    $orphanedSosQuery = $pdo->query("SELECT COUNT(*) FROM sos_alerts sa LEFT JOIN users u ON u.id = sa.elderly_id WHERE u.id IS NULL");
    $orphanedSos = (int)$orphanedSosQuery->fetchColumn();

    assertAdv('ADV-CLN-03', 'Database foreign key integrity preserved: 0 orphaned rows in help_requests and sos_alerts',
        $orphanedReq === 0 && $orphanedSos === 0,
        "Orphaned requests: {$orphanedReq}, orphaned SOS: {$orphanedSos}"
    );
}

// =============================================================================
// SUMMARY & VERDICT
// =============================================================================
$elapsed = round(microtime(true) - $startTime, 3);
echo "\n================================================================================\n";
echo "   ADVERSARIAL STRESS TEST SUMMARY (MILESTONE 3: SOS & WATCHDOG)\n";
echo "================================================================================\n";
echo "Total Assertions : {$totalTests}\n";
echo "Passed           : \033[32m{$passedTests}\033[0m\n";
echo "Failed           : " . ($failedTests > 0 ? "\033[31m{$failedTests}\033[0m" : "0") . "\n";
echo "Execution Time   : {$elapsed}s\n";
echo "Verdict          : " . ($failedTests === 0 ? "\033[32mCONFIRM\033[0m" : "\033[31mDEFECT_FOUND\033[0m") . "\n";
echo "================================================================================\n\n";

if ($failedTests > 0) {
    exit(1);
}
exit(0);
