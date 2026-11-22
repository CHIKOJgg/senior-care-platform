<?php

declare(strict_types=1);

/**
 * Race-condition regression test: two volunteers must not be able to claim the
 * same request, and a completed request must not award karma twice.
 */

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Model.php';
require_once __DIR__ . '/../app/Core/Logger.php';
require_once __DIR__ . '/../app/Core/RateLimiter.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/VolunteerProfile.php';
require_once __DIR__ . '/../app/Models/HelpRequest.php';
require_once __DIR__ . '/e2e_suite/TestHarness.php';

use App\Models\HelpRequest;
use App\Models\VolunteerProfile;
use Tests\E2ESuite\TestHarness;

$harness = TestHarness::getInstance();
$passed = 0;
$failed = 0;

function assertRace(string $name, bool $condition, string $details = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$name}" . ($details !== '' ? " :: {$details}" : '') . PHP_EOL;
    } else {
        $failed++;
        echo "  [FAIL] {$name}" . ($details !== '' ? " :: {$details}" : '') . PHP_EOL;
    }
}

echo "========================================================\n";
echo "   RACE CONDITIONS: ATOMIC CLAIM & COMPLETION\n";
echo "========================================================\n\n";

$elderly = $harness->registerUser(1, 'Гонка Подопечная');
$elderlyToken = (string)$elderly['token'];

$volunteerA = $harness->registerUser(2, 'Гонка Волонтёр А');
$volunteerB = $harness->registerUser(2, 'Гонка Волонтёр Б');
$volunteerAId = (int)($volunteerA['user']['id'] ?? 0);
$volunteerBId = (int)($volunteerB['user']['id'] ?? 0);

$adminToken = $harness->getAdminToken();
$harness->apiCall('POST', "/api/coordinator/verify-volunteer/{$volunteerAId}", null, ["Authorization: Bearer {$adminToken}"]);
$harness->apiCall('POST', "/api/coordinator/verify-volunteer/{$volunteerBId}", null, ["Authorization: Bearer {$adminToken}"]);

$create = $harness->createHelpRequest(1, 1, 'Гонка за одну заявку', 'Проверка атомарного принятия', 'г. Минск, пр-т Независимости, 45, кв. 12', 53.9168, 27.5862, $elderlyToken);
$requestId = (int)($create['data']['request_id'] ?? 0);
$secretCode = (string)($create['data']['secret_code'] ?? '');
assertRace('Request created for race test', $create['code'] === 201 && $requestId > 0, "HTTP {$create['code']}");

$tokens = [0 => (string)$volunteerA['token'], 1 => (string)$volunteerB['token']];
$ids = [0 => $volunteerAId, 1 => $volunteerBId];

$multi = curl_multi_init();
$handles = [];
foreach ($tokens as $i => $token) {
    $ch = curl_init($harness->getBaseUrl() . "/api/requests/{$requestId}/accept");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        "Authorization: Bearer {$token}",
    ]);
    curl_multi_add_handle($multi, $ch);
    $handles[$i] = $ch;
}

$running = null;
do {
    curl_multi_exec($multi, $running);
    if ($running > 0) {
        curl_multi_select($multi, 0.05);
    }
} while ($running > 0);

$codes = [];
foreach ($handles as $i => $ch) {
    $codes[$i] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($multi, $ch);
    curl_close($ch);
}
curl_multi_close($multi);

$successes = array_keys(array_filter($codes, static fn(int $code): bool => $code === 200));
$conflicts = array_keys(array_filter($codes, static fn(int $code): bool => in_array($code, [400, 409], true)));
sort($successes);

$dbRequest = HelpRequest::find($requestId);
$winnerId = (int)($dbRequest['volunteer_id'] ?? 0);
$winnerIndex = $winnerId === $ids[0] ? 0 : ($winnerId === $ids[1] ? 1 : -1);

assertRace('Exactly one volunteer wins the claim', count($successes) === 1 && count($conflicts) === 1, 'HTTP codes: ' . implode(',', $codes));
assertRace('Database stores the winning volunteer', $winnerIndex >= 0, "winner_id={$winnerId}");
assertRace('Request is in_progress after the claim', ($dbRequest['status'] ?? '') === 'in_progress');

$winnerToken = $winnerIndex >= 0 ? $tokens[$winnerIndex] : '';
$winnerProfileBefore = VolunteerProfile::find($winnerId);
$pointsBefore = (int)($winnerProfileBefore['points'] ?? 0);

$completePayload = json_encode([
    'secret_code' => $secretCode,
    'latitude' => 53.9168,
    'longitude' => 27.5862,
], JSON_UNESCAPED_UNICODE);

$firstCompletion = $harness->apiCall('POST', "/api/requests/{$requestId}/complete", json_decode($completePayload, true), ["Authorization: Bearer {$winnerToken}"]);
$secondCompletion = $harness->apiCall('POST', "/api/requests/{$requestId}/complete", json_decode($completePayload, true), ["Authorization: Bearer {$winnerToken}"]);

$dbAfter = HelpRequest::find($requestId);
$winnerProfileAfter = VolunteerProfile::find($winnerId);
$pointsAfter = (int)($winnerProfileAfter['points'] ?? 0);

assertRace('First completion succeeds', $firstCompletion['code'] === 200 && ($firstCompletion['data']['success'] ?? false) === true, 'HTTP ' . $firstCompletion['code']);
assertRace('Replay completion is rejected', $secondCompletion['code'] === 400, 'HTTP ' . $secondCompletion['code']);
assertRace('Request is completed in database', ($dbAfter['status'] ?? '') === 'completed');
assertRace('Karma awarded exactly once', $pointsAfter - $pointsBefore === 50, "delta=" . ($pointsAfter - $pointsBefore));

echo "\n--------------------------------------------------------\n";
echo "RACE RESULTS: Total: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================\n";

exit($failed === 0 ? 0 : 1);
