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
require_once __DIR__ . '/../app/Core/Logger.php';
require_once __DIR__ . '/../app/Core/RateLimiter.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/HelpRequest.php';
require_once __DIR__ . '/../app/Models/ElderlyProfile.php';
require_once __DIR__ . '/../app/Models/VolunteerProfile.php';
require_once __DIR__ . '/../app/Models/SosAlert.php';
require_once __DIR__ . '/../app/Services/GeoMatchingService.php';
require_once __DIR__ . '/../app/Services/SecurityService.php';
require_once __DIR__ . '/../app/Services/Emergency/EmergencyAlertAdapterInterface.php';
require_once __DIR__ . '/../app/Services/Emergency/MchsEmergencyAdapter.php';
require_once __DIR__ . '/../app/Services/TaskWatchdogService.php';
require_once __DIR__ . '/../app/Services/AuthService.php';
require_once __DIR__ . '/e2e_suite/TestHarness.php';

use App\Services\GeoMatchingService;
use App\Services\SecurityService;
use App\Services\Emergency\EmergencyAlertAdapterInterface;
use App\Services\Emergency\MchsEmergencyAdapter;
use App\Services\TaskWatchdogService;
use App\Models\HelpRequest;
use App\Models\SosAlert;
use App\Models\VolunteerProfile;
use App\Models\ElderlyProfile;
use App\Core\Database;
use Tests\E2ESuite\TestHarness;

echo "========================================================\n";
echo "   MILESTONE 3: GEOFENCING, SOS & WATCHDOG TEST SUITE\n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assertM3(string $name, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$name} - {$details}\n";
        $failed++;
    }
}

$harness = TestHarness::getInstance('http://127.0.0.1:8000');
$elderlyToken = $harness->getElderlyToken();
$volunteerToken = $harness->getVolunteerToken();
$coordinatorToken = $harness->getCoordinatorToken();
$adminToken = $harness->getAdminToken();




echo "[SUITE 1] Haversine Distance, Meters Precision & Geofence Boundaries:\n";


$dist0Km = GeoMatchingService::calculateDistance(53.9168, 27.5862, 53.9168, 27.5862);
assertM3('SG-GEO-01: Identical coordinates yield 0.0 km', $dist0Km === 0.0, "Got: {$dist0Km}");

$dist0Meters = GeoMatchingService::calculateDistanceMeters(53.9168, 27.5862, 53.9168, 27.5862);
assertM3('SG-GEO-02: Identical coordinates yield 0.0 meters', $dist0Meters === 0.0, "Got: {$dist0Meters}");


$distMicroKm = GeoMatchingService::calculateDistance(53.9168, 27.5862, 53.916809, 27.5862);
$distMicroM = GeoMatchingService::calculateDistanceMeters(53.9168, 27.5862, 53.916809, 27.5862);
assertM3('SG-GEO-03: Sub-meter precision maintained (~1m non-zero)', $distMicroM > 0.5 && $distMicroM < 2.0, "Got: {$distMicroM}m");



$latBase = 53.9168;
$lonBase = 27.5862;
$lat149m = $latBase + (149.0 / 111139.0);
$m149 = GeoMatchingService::calculateDistanceMeters($latBase, $lonBase, $lat149m, $lonBase);
assertM3('SG-GEO-04: Distance ~149m evaluates as inside 150m geofence',
    GeoMatchingService::isWithinGeofence($latBase, $lonBase, $lat149m, $lonBase, 150.0) === true && $m149 < 150.0,
    "Got: {$m149}m"
);


$lat150m = $latBase + (150.0 / (GeoMatchingService::EARTH_RADIUS_METERS * M_PI / 180.0));
$m150 = GeoMatchingService::calculateDistanceMeters($latBase, $lonBase, $lat150m, $lonBase);
assertM3('SG-GEO-05: Exact boundary 150.0m evaluates as within geofence',
    GeoMatchingService::isWithinGeofence($latBase, $lonBase, $lat150m, $lonBase, 150.0) === true,
    "Got: {$m150}m"
);


$lat151m = $latBase + (152.0 / 111139.0);
$m151 = GeoMatchingService::calculateDistanceMeters($latBase, $lonBase, $lat151m, $lonBase);
assertM3('SG-GEO-06: Out-of-radius distance (~152m) rejected by geofence',
    GeoMatchingService::isWithinGeofence($latBase, $lonBase, $lat151m, $lonBase, 150.0) === false && $m151 > 150.0,
    "Got: {$m151}m"
);


$lat320m = $latBase + (320.0 / 111139.0);
$m320 = GeoMatchingService::calculateDistanceMeters($latBase, $lonBase, $lat320m, $lonBase);
assertM3('SG-GEO-07: Remote distance (~320m) strictly rejected by geofence',
    GeoMatchingService::isWithinGeofence($latBase, $lonBase, $lat320m, $lonBase, 150.0) === false,
    "Got: {$m320}m"
);


$distAntipodal = GeoMatchingService::calculateDistance(0.0, 0.0, 0.0, 180.0);
assertM3('SG-GEO-08: Antipodal points clamping eliminates NaN (distance > 19000 km)',
    !is_nan($distAntipodal) && $distAntipodal > 19000.0,
    "Got: {$distAntipodal}"
);


$aliasCheck = GeoMatchingService::isWithinRadius($latBase, $lonBase, $lat149m, $lonBase, 150.0)
    === GeoMatchingService::isWithinGeofence($latBase, $lonBase, $lat149m, $lonBase, 150.0);
assertM3('SG-GEO-09: isWithinRadius is exact functional alias to isWithinGeofence', $aliasCheck);





echo "\n[SUITE 2] Cyrillic Secret Word Hardening & Normalization Pipeline:\n";

$target = 'Ромашка';
assertM3('SG-SEC-01: Exact Cyrillic match returns true', SecurityService::verifyCode('Ромашка', $target));
assertM3('SG-SEC-02: Case-insensitive lowercase match returns true', SecurityService::verifyCode('ромашка', $target));
assertM3('SG-SEC-03: Case-insensitive uppercase match returns true', SecurityService::verifyCode('РОМАШКА', $target));
assertM3('SG-SEC-04: Cyrillic "ё" and "е" normalized during verification (Василек vs Василёк)',
    SecurityService::verifyCode('василек', 'Василёк') && SecurityService::verifyCode('БЕРЁЗА', 'береза')
);
assertM3('SG-SEC-05: Leading and trailing whitespace trimmed during verification',
    SecurityService::verifyCode('   Ромашка   ', $target)
);
assertM3('SG-SEC-06: Surrounding quotation marks («», "", \'\', “”, ‘’) stripped',
    SecurityService::verifyCode('«Ромашка»', $target) &&
    SecurityService::verifyCode('"Ромашка"', $target) &&
    SecurityService::verifyCode('\'Ромашка\'', $target) &&
    SecurityService::verifyCode('“Ромашка”', $target)
);
assertM3('SG-SEC-07: Empty string strictly rejected as secret code', !SecurityService::verifyCode('', $target));
assertM3('SG-SEC-08: Distinct incorrect word rejected', !SecurityService::verifyCode('Колокольчик', $target));





echo "\n[SUITE 3] Geofenced Complete API & Brute-Force Lockout Defense:\n";


$req1 = $harness->createHelpRequest(1, 1, 'M3 Тест Геозонирования Завершения', 'Описание', 'пр-т Независимости 45', 53.9168, 27.5862);
$req1Id = $req1['data']['request_id'];
$secret1 = $req1['data']['secret_code'];


$harness->apiCall('POST', "/api/requests/{$req1Id}/accept", ['volunteer_id' => 2]);


$resOutZone = $harness->apiCall('POST', "/api/requests/{$req1Id}/complete", [
    'secret_code' => $secret1,
    'latitude' => 53.9213,
    'longitude' => 27.5862
]);
assertM3('SG-CMP-01: Remote completion (>150m) returns HTTP 400', $resOutZone['code'] === 400, "Code: {$resOutZone['code']}");
assertM3('SG-CMP-02: Remote error response includes user-friendly out-of-zone message',
    strpos($resOutZone['data']['error'] ?? '', 'Волонтёр находится вне допустимой зоны') !== false,
    "Message: " . ($resOutZone['data']['error'] ?? '')
);


$resInZone = $harness->apiCall('POST', "/api/requests/{$req1Id}/complete", [
    'secret_code' => $secret1,
    'latitude' => 53.9170,
    'longitude' => 27.5863
]);
assertM3('SG-CMP-03: In-zone completion (<=150m) succeeds with HTTP 200', $resInZone['code'] === 200, "Code: {$resInZone['code']}");
assertM3('SG-CMP-04: In-zone response contains distance_meters confirmation',
    isset($resInZone['data']['distance_meters']) && $resInZone['data']['distance_meters'] < 150.0,
    "Distance: " . ($resInZone['data']['distance_meters'] ?? 'null')
);


$savedReq = HelpRequest::find((int)$req1Id);
assertM3('SG-CMP-05: Database records completed_lat, completed_lon and distance_to_target',
    $savedReq['status'] === 'completed' &&
    $savedReq['completed_lat'] !== null &&
    $savedReq['completed_lon'] !== null &&
    $savedReq['distance_to_target'] !== null
);


$req2 = $harness->createHelpRequest(1, 1, 'M3 Geofenced completion test', 'Описание', 'пр-т Независимости 45', 53.9168, 27.5862);
$req2Id = $req2['data']['request_id'];
$secret2 = $req2['data']['secret_code'];
$harness->apiCall('POST', "/api/requests/{$req2Id}/accept", ['volunteer_id' => 2]);

$resGeo = $harness->apiCall('POST', "/api/requests/{$req2Id}/complete", [
    'secret_code' => $secret2,
    'latitude' => 53.9168,
    'longitude' => 27.5862
]);
assertM3('SG-CMP-06: Geofenced completion (coords provided) succeeds with HTTP 200',
    $resGeo['code'] === 200 && ($resGeo['data']['success'] ?? false) === true
);


$reqLock = $harness->createHelpRequest(1, 1, 'M3 Lockout Test');
$lockId = $reqLock['data']['request_id'];
$correctSecret = $reqLock['data']['secret_code'];
$harness->apiCall('POST', "/api/requests/{$lockId}/accept", ['volunteer_id' => 2]);

for ($i = 1; $i <= 4; $i++) {
    $resWrong = $harness->apiCall('POST', "/api/requests/{$lockId}/complete", ['secret_code' => "WrongCode{$i}"]);
}
assertM3('SG-CMP-07: Failed attempts incremented and tracked in database',
    (int)(HelpRequest::find((int)$lockId)['failed_attempts'] ?? 0) === 4
);


$resLockout = $harness->apiCall('POST', "/api/requests/{$lockId}/complete", ['secret_code' => "WrongCode5"]);
assertM3('SG-CMP-08: 5th failed attempt triggers brute-force lockout (HTTP 400)',
    $resLockout['code'] === 400 && strpos($resLockout['data']['error'] ?? '', 'Превышено максимальное количество') !== false
);


$resPostLock = $harness->apiCall('POST', "/api/requests/{$lockId}/complete", ['secret_code' => $correctSecret]);
assertM3('SG-CMP-09: Locked task rejects completion even with authentic secret code',
    $resPostLock['code'] === 400 && strpos($resPostLock['data']['error'] ?? '', 'заблокирована') !== false
);





echo "\n[SUITE 4] Modular EMERCOM (МЧС РБ / 112) Mock Adapter:\n";

$adapter = new MchsEmergencyAdapter();
assertM3('SG-EMR-01: Adapter implements EmergencyAlertAdapterInterface', $adapter instanceof EmergencyAlertAdapterInterface);
assertM3('SG-EMR-02: getProviderName returns EMERCOM_112_BY', $adapter->getProviderName() === 'EMERCOM_112_BY');

$genTicket = MchsEmergencyAdapter::generateTicketId();
assertM3('SG-EMR-03: Ticket ID matches EMERCOM-112-BY-YYYY-XXXX format',
    (bool)preg_match('/^EMERCOM-112-BY-\d{4}-[A-Z0-9]{4,}$/', $genTicket),
    "Generated: {$genTicket}"
);
assertM3('SG-EMR-04: verifyTicketId validates correct format', MchsEmergencyAdapter::verifyTicketId($genTicket));
assertM3('SG-EMR-05: verifyTicketId rejects invalid arbitrary string', !MchsEmergencyAdapter::verifyTicketId('INVALID-TICKET-123'));

$dispatch = $adapter->dispatchEmergency([
    'elderly_id' => 1,
    'latitude' => 53.9168,
    'longitude' => 27.5862,
    'address' => 'г. Минск, Советский район',
    'reason' => 'Упала, подозрение на перелом'
]);
assertM3('SG-EMR-06: dispatchEmergency returns status DISPATCHED with crew assignment',
    ($dispatch['status'] ?? '') === 'DISPATCHED' && !empty($dispatch['assigned_crew']) && !empty($dispatch['ticket_id'])
);


$offlineDispatch = $adapter->dispatchEmergency([
    'elderly_id' => 1,
    'simulate_network_failure' => true
]);
assertM3('SG-EMR-07: Network disconnect triggers offline resilient queue',
    ($offlineDispatch['queued_offline'] ?? false) === true && count(MchsEmergencyAdapter::getOfflineQueue()) > 0
);

$cancelRes = $adapter->cancelIncident($genTicket, 'Ложная тревога подопечного');
assertM3('SG-EMR-08: cancelIncident returns status CANCELLED with audit reason',
    ($cancelRes['status'] ?? '') === 'CANCELLED' && ($cancelRes['reason'] ?? '') === 'Ложная тревога подопечного'
);





echo "\n[SUITE 5] Priority 0 Emergency SOS API Lifecycle:\n";


$resAnon = $harness->apiCall('POST', '/api/sos', ['latitude' => 53.9168, 'longitude' => 27.5862]);
assertM3('SG-SOS-01: Unauthenticated POST /api/sos strictly returns HTTP 401', $resAnon['code'] === 401, "Code: {$resAnon['code']}");


$resSos = $harness->apiCall('POST', '/api/sos', [
    'latitude' => 53.9168,
    'longitude' => 27.5862,
    'reason' => 'Тест SOS экстренного реагирования'
], ["Authorization: Bearer {$elderlyToken}"]);
assertM3('SG-SOS-02: Elderly POST /api/sos returns HTTP 201 Created', $resSos['code'] === 201, "Code: {$resSos['code']}");
assertM3('SG-SOS-03: SOS response returns status ACTIVE and Priority 0',
    ($resSos['data']['status'] ?? '') === 'ACTIVE' && ($resSos['data']['priority'] ?? -1) === 0
);
assertM3('SG-SOS-04: SOS response returns EMERCOM 112 ticket ID',
    !empty($resSos['data']['emercom_ticket_id']) && MchsEmergencyAdapter::verifyTicketId($resSos['data']['emercom_ticket_id'])
);
$sosId = (int)($resSos['data']['sos_id'] ?? 0);


$resMinimal = $harness->apiCall('POST', '/api/sos', [], ["Authorization: Bearer {$elderlyToken}"]);
assertM3('SG-SOS-05: Empty payload resolves default coordinates from elderly profile (HTTP 201)',
    $resMinimal['code'] === 201 && !empty($resMinimal['data']['sos_id'])
);


$resActive = $harness->apiCall('GET', '/api/sos/active', null, ["Authorization: Bearer {$coordinatorToken}"]);
assertM3('SG-SOS-06: Coordinator GET /api/sos/active returns 200 with active alerts',
    $resActive['code'] === 200 && is_array($resActive['data']['data'] ?? null)
);


$resResolve = $harness->apiCall('POST', "/api/sos/{$sosId}/resolve", null, ["Authorization: Bearer {$coordinatorToken}"]);
assertM3('SG-SOS-07: Coordinator POST /api/sos/{id}/resolve marks alert as RESOLVED',
    $resResolve['code'] === 200 && ($resResolve['data']['status'] ?? '') === 'RESOLVED'
);


$res404 = $harness->apiCall('POST', '/api/sos/999999/resolve', null, ["Authorization: Bearer {$coordinatorToken}"]);
assertM3('SG-SOS-08: Resolving non-existent SOS ID returns HTTP 404', $res404['code'] === 404, "Code: {$res404['code']}");


$resAlias1 = $harness->apiCall('GET', '/api/coordinator/sos-alerts', null, ["Authorization: Bearer {$coordinatorToken}"]);
assertM3('SG-SOS-09: Coordinator alias GET /api/coordinator/sos-alerts returns 200', $resAlias1['code'] === 200);

$resAlias2 = $harness->apiCall('GET', '/api/coordinator/alerts', null, ["Authorization: Bearer {$coordinatorToken}"]);
assertM3('SG-SOS-10: Coordinator alias GET /api/coordinator/alerts returns 200', $resAlias2['code'] === 200);





echo "\n[SUITE 6] Task Watchdog Service & Overdue Reassignment:\n";


assertM3('SG-WDC-01: Pharmacy timeout is 3600 seconds (60 min)',
    TaskWatchdogService::getTimeoutForCategory('pharmacy') === 3600 &&
    TaskWatchdogService::getTimeoutForCategory('аптека') === 3600
);
assertM3('SG-WDC-02: Groceries timeout is 7200 seconds (120 min)',
    TaskWatchdogService::getTimeoutForCategory('groceries') === 7200 &&
    TaskWatchdogService::getTimeoutForCategory('продукты') === 7200
);
assertM3('SG-WDC-03: Default/household timeout is 10800 seconds (180 min)',
    TaskWatchdogService::getTimeoutForCategory('household') === 10800 &&
    TaskWatchdogService::getTimeoutForCategory('unknown') === 10800
);


$reqWdc = $harness->createHelpRequest(1, 2, 'Watchdog Timeout Test Pharmacy');
$wdcId = (int)$reqWdc['data']['request_id'];
$harness->apiCall('POST', "/api/requests/{$wdcId}/accept", ['volunteer_id' => 2]);
$freshReq = HelpRequest::find($wdcId);
assertM3('SG-WDC-04: Accepting request records non-null assigned_at timestamp',
    !empty($freshReq['assigned_at']),
    "Assigned at: " . ($freshReq['assigned_at'] ?? 'null')
);


$pdo = Database::getConnection();
$twoHoursAgo = date('Y-m-d H:i:s', time() - 7500);
$pdo->exec("UPDATE help_requests SET assigned_at = '{$twoHoursAgo}', is_overdue = 0 WHERE id = {$wdcId}");

$scanResult = TaskWatchdogService::checkOverdueTasks();
assertM3('SG-WDC-05: checkOverdueTasks identifies expired assignment and sets is_overdue = 1',
    (int)HelpRequest::find($wdcId)['is_overdue'] === 1 && $scanResult['overdue_found'] > 0
);


$resWdc = $harness->apiCall('GET', '/api/coordinator/watchdog', null, ["Authorization: Bearer {$coordinatorToken}"]);
assertM3('SG-WDC-06: GET /api/coordinator/watchdog returns 200 with overdue tasks and elapsed_minutes',
    $resWdc['code'] === 200 &&
    is_array($resWdc['data']['data'] ?? null) &&
    count($resWdc['data']['data']) > 0 &&
    isset($resWdc['data']['data'][0]['elapsed_minutes'])
);


$resWdcAlias = $harness->apiCall('GET', '/api/coordinator/tasks/overdue', null, ["Authorization: Bearer {$coordinatorToken}"]);
assertM3('SG-WDC-07: Alias GET /api/coordinator/tasks/overdue returns 200', $resWdcAlias['code'] === 200);

$resReqAlias = $harness->apiCall('GET', '/api/requests/overdue', null, ["Authorization: Bearer {$coordinatorToken}"]);
assertM3('SG-WDC-08: Alias GET /api/requests/overdue returns 200', $resReqAlias['code'] === 200);


$resRun = $harness->apiCall('POST', '/api/tasks/watchdog/run', null, ["Authorization: Bearer {$coordinatorToken}"]);
assertM3('SG-WDC-09: POST /api/tasks/watchdog/run executes scan and returns summary',
    $resRun['code'] === 200 && ($resRun['data']['success'] ?? false) === true
);


$resReassign = $harness->apiCall('POST', "/api/coordinator/requests/{$wdcId}/reassign", null, ["Authorization: Bearer {$coordinatorToken}"]);
assertM3('SG-WDC-10: Coordinator reassign endpoint unassigns volunteer and resets status to open',
    $resReassign['code'] === 200 && ($resReassign['data']['status'] ?? '') === 'open'
);

$reassignedReq = HelpRequest::find($wdcId);
assertM3('SG-WDC-11: Database record after reassign has volunteer_id = NULL, is_overdue = 0, assigned_at = NULL',
    $reassignedReq['volunteer_id'] === null &&
    (int)$reassignedReq['is_overdue'] === 0 &&
    $reassignedReq['assigned_at'] === null &&
    $reassignedReq['status'] === 'open'
);

echo "\n--------------------------------------------------------\n";
echo "TEST RESULTS: Total: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
