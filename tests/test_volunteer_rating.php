<?php

declare(strict_types=1);

/**
 * Regression suite for post-visit volunteer ratings and low-rating limits.
 *
 * The senior rates a completed visit 1..5. An average below 3.0 must block new
 * task acceptance until a coordinator lifts the restriction.
 */

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Model.php';
require_once __DIR__ . '/../app/Models/VolunteerProfile.php';
require_once __DIR__ . '/e2e_suite/TestHarness.php';

use App\Core\Database;
use App\Models\VolunteerProfile;
use Tests\E2ESuite\TestHarness;

$harness = TestHarness::getInstance();
$passed = 0;
$failed = 0;

function assertRating(string $name, bool $condition, string $details = ''): void
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
echo "   VOLUNTEER RATING & LOW-RATING LIMIT TESTS\n";
echo "========================================================\n\n";

$elderlyToken = $harness->getElderlyToken();
$coordinatorToken = $harness->getCoordinatorToken();
$volunteer = $harness->registerUser(2, 'Тест Оценки Волонтёра');
$uid = (int)($volunteer['user']['id'] ?? 0);
$volunteerToken = (string)($volunteer['token'] ?? '');

assertRating('Test volunteer registered', $uid > 0 && $volunteerToken !== '');

$verify = $harness->apiCall(
    'POST',
    "/api/coordinator/volunteers/{$uid}/verify",
    null,
    ["Authorization: Bearer {$coordinatorToken}"]
);
assertRating('Volunteer is verified before taking tasks', $verify['code'] === 200, "HTTP {$verify['code']}");

$requestIds = [];
$create = $harness->createHelpRequest(1, 1, '[TEST-RATING] Доставка продуктов', 'Проверка оценки после визита', 'г. Минск, пр-т Независимости, 45', 53.9168, 27.5862, $elderlyToken);
$requestId = (int)($create['data']['request_id'] ?? 0);
$secret = (string)($create['data']['secret_code'] ?? '');
$requestIds[] = $requestId;
assertRating('Elderly creates a request for rating flow', $requestId > 0 && $secret !== '');

$accept = $harness->apiCall(
    'POST',
    "/api/requests/{$requestId}/accept",
    [],
    ["Authorization: Bearer {$volunteerToken}"]
);
assertRating('Volunteer accepts the request', $accept['code'] === 200, "HTTP {$accept['code']}");

$complete = $harness->apiCall(
    'POST',
    "/api/requests/{$requestId}/complete",
    ['secret_code' => $secret, 'latitude' => 53.9168, 'longitude' => 27.5862],
    ["Authorization: Bearer {$volunteerToken}"]
);
assertRating('Volunteer completes the visit with the secret word', $complete['code'] === 200, "HTTP {$complete['code']}");

$rate = $harness->apiCall(
    'POST',
    "/api/requests/{$requestId}/rate",
    ['rating' => 1],
    ["Authorization: Bearer {$elderlyToken}"]
);
assertRating('Elderly rates the volunteer 1/5', $rate['code'] === 200, "HTTP {$rate['code']}");
assertRating(
    'Low rating is returned to the caller',
    (int)($rate['data']['volunteer_rating'] ?? 0) === 1
        && ($rate['data']['volunteer_is_restricted'] ?? false) === true,
    'rating=' . ($rate['data']['volunteer_rating'] ?? 'null')
);

$rateAgain = $harness->apiCall(
    'POST',
    "/api/requests/{$requestId}/rate",
    ['rating' => 2],
    ["Authorization: Bearer {$elderlyToken}"]
);
assertRating('The same request cannot be rated twice', $rateAgain['code'] === 409, "HTTP {$rateAgain['code']}");

$profile = VolunteerProfile::find($uid);
assertRating(
    'Profile stores the low rating and restriction',
    $profile !== null
        && (float)($profile['rating'] ?? 5) === 1.0
        && (int)($profile['is_restricted'] ?? 0) === 1,
    'rating=' . ($profile['rating'] ?? 'null') . ', restricted=' . ($profile['is_restricted'] ?? 'null')
);

$second = $harness->createHelpRequest(1, 1, '[TEST-RATING] Вторая заявка', 'Проверка блокировки', 'г. Минск, пр-т Независимости, 45', 53.9168, 27.5862, $elderlyToken);
$secondId = (int)($second['data']['request_id'] ?? 0);
$requestIds[] = $secondId;
$blocked = $harness->apiCall(
    'POST',
    "/api/requests/{$secondId}/accept",
    [],
    ["Authorization: Bearer {$volunteerToken}"]
);
assertRating(
    'Restricted volunteer cannot accept a new request',
    $blocked['code'] === 403 && ($blocked['data']['code'] ?? '') === 'VOLUNTEER_RESTRICTED',
    "HTTP {$blocked['code']}"
);

$unrestrict = $harness->apiCall(
    'POST',
    "/api/coordinator/volunteers/{$uid}/unrestrict",
    null,
    ["Authorization: Bearer {$coordinatorToken}"]
);
assertRating('Coordinator lifts the restriction', $unrestrict['code'] === 200, "HTTP {$unrestrict['code']}");

$profileAfter = VolunteerProfile::find($uid);
assertRating(
    'Restriction flag is cleared',
    $profileAfter !== null && (int)($profileAfter['is_restricted'] ?? 1) === 0
);

$acceptAfter = $harness->apiCall(
    'POST',
    "/api/requests/{$secondId}/accept",
    [],
    ["Authorization: Bearer {$volunteerToken}"]
);
assertRating('Unrestricted volunteer can accept again', $acceptAfter['code'] === 200, "HTTP {$acceptAfter['code']}");

// Cleanup for standalone runs.
$pdo = Database::getConnection();
foreach (array_filter($requestIds) as $id) {
    $pdo->prepare('DELETE FROM help_requests WHERE id = :id')->execute(['id' => $id]);
}
$pdo->prepare('DELETE FROM volunteer_training_assignments WHERE volunteer_id = :id')->execute(['id' => $uid]);
$pdo->prepare('DELETE FROM volunteer_profiles WHERE user_id = :id')->execute(['id' => $uid]);
$pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $uid]);

echo "\n--------------------------------------------------------\n";
echo "TEST RESULTS: Total: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================\n";

exit($failed === 0 ? 0 : 1);
