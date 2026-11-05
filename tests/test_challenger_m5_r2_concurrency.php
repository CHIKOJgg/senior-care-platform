<?php

declare(strict_types=1);

/**
 * Challenger M5-r2-2: Concurrency & SOS Priority 0 Empirical Adversarial Harness
 * 
 * Objectives:
 * 1. Empirically verify SQLite WAL mode and concurrent write resilience:
 *    - PRAGMA checks: journal_mode = WAL, busy_timeout >= 5000, synchronous = 1 (NORMAL), foreign_keys = ON.
 *    - Multi-process / burst load stress tests against SQLite database inside container.
 *    - Rapid sequential and concurrent transactions (SOS creation, task assignment, completion, profile anonymization, consent logging)
 *      with ZERO SQLITE_BUSY / database is locked errors.
 * 2. Empirically verify SOS Emergency Priority 0:
 *    - SOS alerts default to Priority 0 both in API response and in SQLite sos_alerts.priority.
 *    - Resolving SOS alert with valid resolution note properly transitions status to RESOLVED and records resolution timestamp.
 */

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Model.php';
require_once __DIR__ . '/../app/Core/Logger.php';
require_once __DIR__ . '/../app/Core/RateLimiter.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/ElderlyProfile.php';
require_once __DIR__ . '/../app/Models/VolunteerProfile.php';
require_once __DIR__ . '/../app/Models/HelpRequest.php';
require_once __DIR__ . '/../app/Models/SosAlert.php';
require_once __DIR__ . '/../app/Services/CryptoService.php';
require_once __DIR__ . '/../app/Services/DataMaskingService.php';
require_once __DIR__ . '/../app/Services/PrivacyService.php';
require_once __DIR__ . '/../app/Services/BadgeService.php';
require_once __DIR__ . '/../app/Services/Emergency/EmergencyAlertAdapterInterface.php';
require_once __DIR__ . '/../app/Services/Emergency/MchsEmergencyAdapter.php';
require_once __DIR__ . '/../app/Services/AuthService.php';
require_once __DIR__ . '/e2e_suite/TestHarness.php';

use App\Core\Database;
use App\Core\RateLimiter;
use App\Models\User;
use App\Models\ElderlyProfile;
use App\Models\VolunteerProfile;
use App\Models\HelpRequest;
use App\Models\SosAlert;
use App\Services\CryptoService;
use App\Services\PrivacyService;
use App\Services\BadgeService;
use App\Services\Emergency\MchsEmergencyAdapter;
use App\Services\AuthService;
use Tests\E2ESuite\TestHarness;

$harness = TestHarness::getInstance();
$passed = 0;
$failed = 0;
$total = 0;
$failures = [];

function assertChallenger(string $code, string $description, bool $condition, string $details = ''): bool
{
    global $passed, $failed, $total, $failures;
    $total++;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$code}: {$description}" . ($details !== '' ? " :: {$details}" : '') . "\n";
        return true;
    } else {
        $failed++;
        $failures[] = "{$code}: {$description} :: {$details}";
        echo "  [FAIL] {$code}: {$description} :: {$details}\n";
        return false;
    }
}

echo "\n================================================================================\n";
echo "   CHALLENGER M5-r2-2: EMPIRICAL CONCURRENCY & SOS HARNESS\n";
echo "================================================================================\n\n";

$pdo = Database::getConnection();

// =============================================================================
// SECTION 1: SQLite WAL Mode & Pragma Configuration Verification
// =============================================================================
echo "--- [SECTION 1] SQLite WAL Mode & Pragma Verification ---\n";

$journalMode = strtolower((string)$pdo->query("PRAGMA journal_mode")->fetchColumn());
assertChallenger(
    'CHAL-WAL-01',
    'SQLite journal_mode is configured as WAL (Write-Ahead Logging)',
    $journalMode === 'wal',
    "Actual journal_mode: {$journalMode}"
);

$busyTimeout = (int)$pdo->query("PRAGMA busy_timeout")->fetchColumn();
assertChallenger(
    'CHAL-WAL-02',
    'SQLite busy_timeout is set to at least 5000ms',
    $busyTimeout >= 5000,
    "Actual busy_timeout: {$busyTimeout}ms"
);

$synchronous = (int)$pdo->query("PRAGMA synchronous")->fetchColumn();
assertChallenger(
    'CHAL-WAL-03',
    'SQLite synchronous pragma is NORMAL (1) for high-performance WAL writes',
    $synchronous === 1,
    "Actual synchronous: {$synchronous}"
);

$foreignKeys = (int)$pdo->query("PRAGMA foreign_keys")->fetchColumn();
assertChallenger(
    'CHAL-WAL-04',
    'SQLite foreign_keys pragma is enabled (1)',
    $foreignKeys === 1,
    "Actual foreign_keys: {$foreignKeys}"
);

// =============================================================================
// SECTION 2: SOS Emergency Priority 0 & Resolution Lifecycle
// =============================================================================
echo "\n--- [SECTION 2] SOS Emergency Priority 0 & Resolution Lifecycle ---\n";

$elderlyUser = $harness->registerUser(1, 'Пенсионер Приоритет Ноль');
$elderlyId = (int)$elderlyUser['user']['id'];
$elderlyToken = $elderlyUser['token'];

$coordinatorToken = $harness->getCoordinatorToken();
$volunteerToken = $harness->getVolunteerToken();

// 2.1 SOS Creation via API: default Priority 0 in HTTP API response
$sosApiRes = $harness->apiCall('POST', '/api/sos', [
    'latitude' => 53.9168,
    'longitude' => 27.5862,
    'reason' => 'Проверка дефолтного приоритета 0'
], ["Authorization: Bearer {$elderlyToken}"]);

$sosId = (int)($sosApiRes['data']['sos_id'] ?? 0);
assertChallenger(
    'CHAL-SOS-PRIO-01',
    'POST /api/sos response defaults to priority 0 and status ACTIVE',
    $sosApiRes['code'] === 201 
        && ($sosApiRes['data']['success'] ?? false) === true
        && ($sosApiRes['data']['priority'] ?? -1) === 0
        && ($sosApiRes['data']['status'] ?? '') === 'ACTIVE',
    "HTTP {$sosApiRes['code']}, Priority=" . var_export($sosApiRes['data']['priority'] ?? null, true)
);

// 2.2 Direct SQLite verification of Priority 0 in database
$dbSos = SosAlert::find($sosId);
assertChallenger(
    'CHAL-SOS-PRIO-02',
    'SQLite database table sos_alerts stores priority = 0 and status = ACTIVE',
    $dbSos !== null
        && (int)$dbSos['priority'] === 0
        && $dbSos['status'] === 'ACTIVE'
        && $dbSos['resolved_at'] === null,
    "DB priority=" . var_export($dbSos['priority'] ?? null, true) . ", status=" . ($dbSos['status'] ?? 'null')
);

// 2.3 Table schema constraint default check
$schemaStmt = $pdo->prepare("INSERT INTO sos_alerts (elderly_id, latitude, longitude, address, reason, status) VALUES (:eid, 53.9168, 27.5862, 'Тестовый адрес', 'Тест схемы', 'ACTIVE')");
$schemaStmt->execute(['eid' => $elderlyId]);
$schemaAlertId = (int)$pdo->lastInsertId();
$dbSchemaAlert = SosAlert::find($schemaAlertId);

assertChallenger(
    'CHAL-SOS-PRIO-03',
    'SQLite DDL default constraint automatically assigns priority 0 when omitted from INSERT',
    $dbSchemaAlert !== null && (int)$dbSchemaAlert['priority'] === 0,
    "Schema default priority: " . var_export($dbSchemaAlert['priority'] ?? null, true)
);

// 2.4 Priority sorting in active alerts feed
$activeAlertsRes = $harness->apiCall('GET', '/api/sos/active', null, ["Authorization: Bearer {$coordinatorToken}"]);
$activeAlerts = $activeAlertsRes['data']['data'] ?? [];
$isFirstPriority0 = count($activeAlerts) > 0 && (int)$activeAlerts[0]['priority'] === 0;

assertChallenger(
    'CHAL-SOS-PRIO-04',
    'GET /api/sos/active prioritizes Priority 0 alerts at the head of the feed',
    $activeAlertsRes['code'] === 200 && $isFirstPriority0,
    "Count: " . count($activeAlerts) . ", Head Priority: " . ($activeAlerts[0]['priority'] ?? 'none')
);

// 2.5 SOS Alert Resolution with valid resolution note
$resolveRes = $harness->apiCall('POST', "/api/sos/{$sosId}/resolve", [
    'resolution_note' => 'Медицинская бригада СМП прибыла на место, состояние гражданина стабилизировано',
    'resolved_by' => 2
], ["Authorization: Bearer {$coordinatorToken}"]);

$dbSosResolved = SosAlert::find($sosId);
$nowTs = time();
$resolvedTs = !empty($dbSosResolved['resolved_at']) ? strtotime($dbSosResolved['resolved_at']) : 0;
$timeDeltaOk = abs($nowTs - $resolvedTs) <= 10;

assertChallenger(
    'CHAL-SOS-RESOLVE-01',
    'POST /api/sos/{id}/resolve transitions alert to status RESOLVED and records resolved_at timestamp',
    $resolveRes['code'] === 200
        && ($resolveRes['data']['status'] ?? '') === 'RESOLVED'
        && !empty($resolveRes['data']['resolved_at'])
        && $dbSosResolved['status'] === 'RESOLVED'
        && $timeDeltaOk,
    "HTTP {$resolveRes['code']}, DB status: {$dbSosResolved['status']}, resolved_at: {$dbSosResolved['resolved_at']}, delta: " . abs($nowTs - $resolvedTs) . "s"
);

// 2.6 Resolved alert is excluded from active feeds
$activeAfterRes = $harness->apiCall('GET', '/api/sos/active', null, ["Authorization: Bearer {$coordinatorToken}"]);
$activeIdsAfter = array_column($activeAfterRes['data']['data'] ?? [], 'id');

assertChallenger(
    'CHAL-SOS-RESOLVE-02',
    'Resolved SOS alert is strictly excluded from active coordinator alerts feed',
    !in_array($sosId, $activeIdsAfter, false),
    "Resolved ID {$sosId} present in active list: " . var_export(in_array($sosId, $activeIdsAfter, false), true)
);

// 2.7 Security RBAC: non-coordinator/admin cannot resolve SOS alert
$unauthResolveRes = $harness->apiCall('POST', "/api/sos/{$schemaAlertId}/resolve", [
    'resolution_note' => 'Попытка закрытия волонтёром'
], ["Authorization: Bearer {$volunteerToken}"]);

assertChallenger(
    'CHAL-SOS-RESOLVE-03',
    'Volunteer role is rejected with HTTP 403 when attempting to resolve SOS alert',
    $unauthResolveRes['code'] === 403,
    "HTTP {$unauthResolveRes['code']}"
);

// 2.8 Resolving non-existent SOS alert returns HTTP 404
$notFoundResolveRes = $harness->apiCall('POST', "/api/sos/999999/resolve", [
    'resolution_note' => 'Несуществующая тревога'
], ["Authorization: Bearer {$coordinatorToken}"]);

assertChallenger(
    'CHAL-SOS-RESOLVE-04',
    'Resolving non-existent alert returns HTTP 404 without exception or lock',
    $notFoundResolveRes['code'] === 404,
    "HTTP {$notFoundResolveRes['code']}"
);

// =============================================================================
// SECTION 3: Multi-Process & Burst Load Concurrency Resilience
// =============================================================================
echo "\n--- [SECTION 3] Multi-Process & Burst Load Concurrency Resilience ---\n";

// Clear rate limits before running burst test
RateLimiter::clear('create_request:127.0.0.1');

// Setup verified volunteer for task assignment and completion
$burstVolUser = $harness->registerUser(2, 'Волонтёр Стресс-Теста Смирнов');
$burstVolId = (int)$burstVolUser['user']['id'];
$burstVolToken = $burstVolUser['token'];
$harness->apiCall('POST', "/api/coordinator/verify-volunteer/{$burstVolId}");

// 3.1: Sequential cross-domain transaction chain (SOS, Task, Complete, Anonymize, Consent)
$chainErrors = [];
try {
    // A: SOS Creation
    $chainSosId = SosAlert::create([
        'elderly_id' => $elderlyId,
        'latitude' => 53.9168,
        'longitude' => 27.5862,
        'address' => 'г. Минск, пр-т Независимости, 45',
        'reason' => 'Стресс цепь: экстренный вызов',
        'status' => 'ACTIVE',
        'priority' => 0,
        'emercom_ticket_id' => MchsEmergencyAdapter::generateTicketId(),
        'created_at' => date('Y-m-d H:i:s')
    ]);

    // B: Task Creation & Assignment
    $chainReqId = HelpRequest::create([
        'elderly_id' => $elderlyId,
        'category_id' => 1,
        'title' => 'Стресс цепь: Доставка продуктов',
        'description' => 'Проверка целостности транзакций',
        'latitude' => 53.9168,
        'longitude' => 27.5862,
        'address' => 'г. Минск, пр-т Независимости, 45, кв. 12',
        'secret_code' => 'берёза',
        'status' => 'in_progress',
        'volunteer_id' => $burstVolId,
        'assigned_at' => date('Y-m-d H:i:s')
    ]);

    // C: Task Completion
    HelpRequest::update($chainReqId, [
        'status' => 'completed',
        'completed_at' => date('Y-m-d H:i:s')
    ]);

    // D: Electronic Consent Logging
    $privacyService = new PrivacyService();
    $consentRecorded = $privacyService->recordConsent(
        $elderlyId,
        PrivacyService::CONSENT_DATA_PROCESSING,
        PrivacyService::CURRENT_POLICY_VERSION,
        '127.0.0.1',
        'Challenger M5-r2-2 Concurrency Harness'
    );

    // E: User Anonymization on a dedicated user
    $anonUser = $harness->registerUser(1, 'Гражданин Для Забвения');
    $anonUid = (int)$anonUser['user']['id'];
    $anonRes = $privacyService->anonymizeUser($anonUid);
    $anonSuccess = ($anonRes['success'] ?? false) === true;

} catch (\Throwable $e) {
    $chainErrors[] = $e->getMessage();
}

assertChallenger(
    'CHAL-CONCUR-01',
    'Sequential rapid multi-table transactions (SOS, Task, Consent, Anonymize) succeed with 0 lock errors',
    empty($chainErrors) && !empty($consentRecorded['id']) && $anonSuccess,
    "Errors: " . implode('; ', $chainErrors)
);

// 3.2: Multi-Process Concurrent Stress Test: 20 Parallel CLI Processes
// Each process opens its own SQLite PDO connection and performs multi-statement write transactions
$workerScript = __DIR__ . '/_tmp_concurrency_worker.php';
$workerCode = <<<'PHP'
<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Model.php';
require_once __DIR__ . '/../app/Core/Logger.php';
require_once __DIR__ . '/../app/Models/SosAlert.php';
require_once __DIR__ . '/../app/Models/HelpRequest.php';
require_once __DIR__ . '/../app/Services/PrivacyService.php';

use App\Core\Database;
use App\Models\SosAlert;
use App\Models\HelpRequest;
use App\Services\PrivacyService;

$workerId = (int)($argv[1] ?? 0);
$elderlyId = (int)($argv[2] ?? 1);
$volunteerId = (int)($argv[3] ?? 2);

$pdo = Database::getConnection();
$errors = [];

try {
    // 1. Insert SOS Alert
    $sosId = SosAlert::create([
        'elderly_id' => $elderlyId,
        'latitude' => 53.9168 + ($workerId * 0.0001),
        'longitude' => 27.5862 + ($workerId * 0.0001),
        'address' => "Адрес процесса #{$workerId}",
        'reason' => "Параллельная транзакция процесса #{$workerId}",
        'status' => 'ACTIVE',
        'priority' => 0,
        'emercom_ticket_id' => "EMERCOM-112-BY-2026-P{$workerId}",
        'created_at' => date('Y-m-d H:i:s')
    ]);

    // 2. Insert Help Request
    $reqId = HelpRequest::create([
        'elderly_id' => $elderlyId,
        'category_id' => 1,
        'title' => "Параллельная заявка #{$workerId}",
        'description' => "Описание задачи процесса #{$workerId}",
        'latitude' => 53.9168,
        'longitude' => 27.5862,
        'address' => 'г. Минск, пр-т Независимости, 45, кв. 10',
        'secret_code' => 'колокол',
        'status' => 'open'
    ]);

    // 3. Assign task to volunteer
    HelpRequest::update((int)$reqId, [
        'volunteer_id' => $volunteerId,
        'status' => 'in_progress',
        'assigned_at' => date('Y-m-d H:i:s')
    ]);

    // 4. Complete task
    HelpRequest::update((int)$reqId, [
        'status' => 'completed',
        'completed_at' => date('Y-m-d H:i:s')
    ]);

    // 5. Consent record in ledger
    $privSvc = new PrivacyService();
    $privSvc->recordConsent(
        $elderlyId,
        PrivacyService::CONSENT_DATA_PROCESSING,
        PrivacyService::CURRENT_POLICY_VERSION,
        '127.0.0.1',
        "Process-{$workerId}-Worker"
    );

    // 6. Resolve SOS Alert
    SosAlert::resolveAlert((int)$sosId);

    echo "OK:{$workerId}:{$sosId}:{$reqId}\n";
    exit(0);

} catch (\Throwable $e) {
    echo "ERR:{$workerId}:" . $e->getMessage() . "\n";
    exit(1);
}
PHP;

file_put_contents($workerScript, $workerCode);

$numProcesses = 20;
$procHandles = [];
$procPipes = [];
$cmdBase = 'php ' . escapeshellarg($workerScript) . ' ';

// Spawn 20 processes in parallel
for ($i = 1; $i <= $numProcesses; $i++) {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $cmd = $cmdBase . "{$i} {$elderlyId} {$burstVolId}";
    $proc = proc_open($cmd, $descriptors, $procPipes[$i]);
    if (is_resource($proc)) {
        $procHandles[$i] = $proc;
    }
}

$successCount = 0;
$lockBusyErrors = 0;
$otherErrors = [];
$createdWorkerSosIds = [];
$createdWorkerReqIds = [];

foreach ($procHandles as $i => $proc) {
    $stdout = stream_get_contents($procPipes[$i][1]);
    $stderr = stream_get_contents($procPipes[$i][2]);
    fclose($procPipes[$i][0]);
    fclose($procPipes[$i][1]);
    fclose($procPipes[$i][2]);

    $exitCode = proc_close($proc);
    $outTrim = trim($stdout);

    if ($exitCode === 0 && str_starts_with($outTrim, 'OK:')) {
        $successCount++;
        $parts = explode(':', $outTrim);
        $createdWorkerSosIds[] = (int)($parts[2] ?? 0);
        $createdWorkerReqIds[] = (int)($parts[3] ?? 0);
    } else {
        if (str_contains($outTrim, 'database is locked') || str_contains($stderr, 'database is locked')
            || str_contains($outTrim, 'SQLITE_BUSY') || str_contains($stderr, 'SQLITE_BUSY')) {
            $lockBusyErrors++;
        }
        $otherErrors[] = "Worker {$i}: exit={$exitCode}, out={$outTrim}, err={$stderr}";
    }
}

@unlink($workerScript);

assertChallenger(
    'CHAL-CONCUR-02',
    '20 simultaneous parallel processes execute multi-table write transactions with ZERO lock failures',
    $successCount === $numProcesses && $lockBusyErrors === 0,
    "Successful: {$successCount}/{$numProcesses}, Lock/Busy errors: {$lockBusyErrors}" . (!empty($otherErrors) ? ", Other errors: " . implode('; ', $otherErrors) : '')
);

assertChallenger(
    'CHAL-CONCUR-03',
    'Zero SQLITE_BUSY or database is locked occurrences during parallel 20-process burst',
    $lockBusyErrors === 0,
    "Lock Busy Count: {$lockBusyErrors}"
);

// 3.3 Verify database state from multi-process burst
$persistedSosCount = 0;
$persistedReqCount = 0;

if (count($createdWorkerSosIds) === $numProcesses) {
    $placeholders = implode(',', array_fill(0, count($createdWorkerSosIds), '?'));
    $stmtSos = $pdo->prepare("SELECT COUNT(*) FROM sos_alerts WHERE id IN ({$placeholders}) AND status = 'RESOLVED'");
    $stmtSos->execute($createdWorkerSosIds);
    $persistedSosCount = (int)$stmtSos->fetchColumn();

    $stmtReq = $pdo->prepare("SELECT COUNT(*) FROM help_requests WHERE id IN ({$placeholders}) AND status = 'completed'");
    $stmtReq->execute($createdWorkerReqIds);
    $persistedReqCount = (int)$stmtReq->fetchColumn();
}

assertChallenger(
    'CHAL-CONCUR-04',
    'All 20 parallel transactions successfully committed and verified in database (100% integrity)',
    $persistedSosCount === $numProcesses && $persistedReqCount === $numProcesses,
    "Persisted SOS: {$persistedSosCount}/{$numProcesses}, Persisted Requests: {$persistedReqCount}/{$numProcesses}"
);

// 3.4 HTTP curl_multi concurrent API burst: 10 parallel HTTP requests simultaneously
$mh = curl_multi_init();
$curls = [];
$httpSosIds = [];

for ($i = 0; $i < 10; $i++) {
    $ch = curl_init($harness->getBaseUrl() . '/api/sos');
    $payload = json_encode([
        'latitude' => 53.9168 + ($i * 0.0002),
        'longitude' => 27.5862 + ($i * 0.0002),
        'reason' => "HTTP Burst call #{$i}"
    ], JSON_UNESCAPED_UNICODE);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        "Authorization: Bearer {$elderlyToken}"
    ]);

    curl_multi_add_handle($mh, $ch);
    $curls[$i] = $ch;
}

$running = null;
do {
    curl_multi_exec($mh, $running);
    if ($running > 0) {
        curl_multi_select($mh, 0.05);
    }
} while ($running > 0);

$httpSuccessCount = 0;
$httpErrors = [];

foreach ($curls as $i => $ch) {
    $resp = curl_multi_getcontent($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $data = json_decode((string)$resp, true);

    if ($code === 201 && ($data['success'] ?? false) === true && !empty($data['sos_id'])) {
        $httpSuccessCount++;
        $httpSosIds[] = (int)$data['sos_id'];
    } else {
        $httpErrors[] = "HTTP #{$i}: code={$code}, resp={$resp}";
    }
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);

assertChallenger(
    'CHAL-CONCUR-05',
    'HTTP curl_multi 10 concurrent requests return HTTP 201 with unique IDs and 0 lock errors',
    $httpSuccessCount === 10 && count(array_unique($httpSosIds)) === 10,
    "HTTP 201: {$httpSuccessCount}/10, Unique IDs: " . count(array_unique($httpSosIds))
);

// 3.5 Cleanup test records
unset($stmtSos, $stmtReq, $schemaStmt);
Database::resetConnection();
$cleanupPdo = Database::getConnection();
$cleanupPdo->exec("DELETE FROM sos_alerts WHERE reason LIKE 'Стресс%' OR reason LIKE 'Параллель%' OR reason LIKE 'HTTP Burst%' OR reason LIKE 'Тест%'");

echo "\n================================================================================\n";
echo "CHALLENGER SUMMARY: Total: {$total} | Passed: {$passed} | Failed: {$failed}\n";
echo "VERDICT: " . ($failed === 0 ? "CONFIRM" : "DEFECT_FOUND") . "\n";
echo "================================================================================\n\n";

exit($failed === 0 ? 0 : 1);
