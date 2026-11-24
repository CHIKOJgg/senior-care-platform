<?php

declare(strict_types=1);

/**
 * Regression suite for volunteer rehabilitation assignments.
 *
 * When a coordinator sends a low-rated volunteer to retraining, the selected
 * educational materials must be persisted, returned to the coordinator, and
 * visible/completable by the volunteer after reload.
 */

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Model.php';
require_once __DIR__ . '/../app/Models/VolunteerProfile.php';
require_once __DIR__ . '/../app/Models/VolunteerTrainingAssignment.php';
require_once __DIR__ . '/e2e_suite/TestHarness.php';

use App\Core\Database;
use App\Models\VolunteerProfile;
use App\Models\VolunteerTrainingAssignment;
use Tests\E2ESuite\TestHarness;

$harness = TestHarness::getInstance();
$passed = 0;
$failed = 0;

function assertRehab(string $name, bool $condition, string $details = ''): void
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
echo "   VOLUNTEER REHABILITATION MATERIALS TESTS\n";
echo "========================================================\n\n";

$volunteer = $harness->registerUser(2, 'Тест Реабилитации Материалов');
$uid = (int)($volunteer['user']['id'] ?? 0);
$volunteerToken = (string)($volunteer['token'] ?? '');
$coordinatorToken = $harness->getCoordinatorToken();

assertRehab('Test volunteer registered', $uid > 0 && $volunteerToken !== '');

VolunteerProfile::update($uid, ['rating' => 2.0]);

$rehab = $harness->apiCall(
    'POST',
    "/api/coordinator/volunteers/{$uid}/rehabilitate",
    [],
    ["Authorization: Bearer {$coordinatorToken}"]
);

$training = $rehab['data']['training_materials'] ?? [];
$assignedCount = (int)($rehab['data']['assigned_count'] ?? 0);
assertRehab(
    'Low-rated volunteer is sent to rehabilitation',
    $rehab['code'] === 200
        && ($rehab['data']['needs_rehabilitation'] ?? false) === true
        && ($rehab['data']['new_status'] ?? '') === 'REHABILITATION',
    "HTTP {$rehab['code']}"
);
assertRehab('Retraining materials are assigned and returned', $assignedCount > 0 && count($training) === $assignedCount);

$dbRows = VolunteerTrainingAssignment::getForVolunteer($uid);
assertRehab(
    'Assignments are persisted in the database',
    count($dbRows) === $assignedCount && count($dbRows) > 0,
    'DB rows: ' . count($dbRows)
);
assertRehab(
    'Assigned materials contain titles and material IDs',
    array_reduce($dbRows, static fn(bool $ok, array $row): bool => $ok
        && (int)($row['material_id'] ?? 0) > 0
        && trim((string)($row['title'] ?? '')) !== '', true)
);

$volunteerView = $harness->apiCall(
    'GET',
    '/api/volunteers/training',
    null,
    ["Authorization: Bearer {$volunteerToken}"]
);
$volunteerMaterials = $volunteerView['data']['data'] ?? [];
assertRehab(
    'Volunteer sees the assigned materials after reload',
    $volunteerView['code'] === 200
        && count($volunteerMaterials) === $assignedCount
        && count($volunteerMaterials) > 0,
    "HTTP {$volunteerView['code']}, count=" . count($volunteerMaterials)
);

$coordinatorView = $harness->apiCall(
    'GET',
    "/api/coordinator/volunteers/{$uid}/training",
    null,
    ["Authorization: Bearer {$coordinatorToken}"]
);
$coordinatorMaterials = $coordinatorView['data']['data'] ?? [];
assertRehab(
    'Coordinator can reopen the persisted assignments',
    $coordinatorView['code'] === 200 && count($coordinatorMaterials) === $assignedCount,
    "HTTP {$coordinatorView['code']}, count=" . count($coordinatorMaterials)
);

$allVolunteers = VolunteerProfile::getAllWithDetails();
$volunteerRow = null;
foreach ($allVolunteers as $row) {
    if ((int)($row['user_id'] ?? 0) === $uid) {
        $volunteerRow = $row;
        break;
    }
}
assertRehab(
    'Volunteer list exposes assigned/completed training counters',
    $volunteerRow !== null
        && (int)($volunteerRow['training_assigned'] ?? 0) === $assignedCount
        && (int)($volunteerRow['training_completed'] ?? -1) === 0
);

$materialId = (int)($volunteerMaterials[0]['material_id'] ?? 0);
$complete = $harness->apiCall(
    'POST',
    "/api/volunteers/training/{$materialId}/complete",
    null,
    ["Authorization: Bearer {$volunteerToken}"]
);
assertRehab(
    'Volunteer can mark a material as completed',
    $complete['code'] === 200 && ($complete['data']['success'] ?? false) === true,
    "HTTP {$complete['code']}"
);

$afterComplete = VolunteerTrainingAssignment::getForVolunteer($uid);
$completedRows = array_values(array_filter(
    $afterComplete,
    static fn(array $row): bool => (int)($row['material_id'] ?? 0) === $materialId
));
assertRehab(
    'Completion timestamp is persisted',
    $completedRows !== [] && !empty($completedRows[0]['completed_at'])
);

// Re-running rehabilitation must not duplicate assignments. Reset the rating
// below the threshold again because the first run moved it to 4.0.
VolunteerProfile::update($uid, ['rating' => 2.0]);
$rehabAgain = $harness->apiCall(
    'POST',
    "/api/coordinator/volunteers/{$uid}/rehabilitate",
    [],
    ["Authorization: Bearer {$coordinatorToken}"]
);
$dbRowsAgain = VolunteerTrainingAssignment::getForVolunteer($uid);
assertRehab(
    'Rehabilitation is idempotent for the same materials',
    count($dbRowsAgain) === count($dbRows)
        && (int)($rehabAgain['data']['assigned_count'] ?? 0) === count($dbRows),
    'Before: ' . count($dbRows) . ', after: ' . count($dbRowsAgain)
);

$profile = VolunteerProfile::find($uid);
assertRehab(
    'Volunteer profile stays in rehabilitation status',
    $profile !== null
        && (int)($profile['is_verified'] ?? 1) === 0
        && ($profile['verification_status'] ?? '') === 'REHABILITATION'
);

// Cleanup for standalone runs.
$pdo = Database::getConnection();
$pdo->prepare('DELETE FROM volunteer_training_assignments WHERE volunteer_id = :id')->execute(['id' => $uid]);
$pdo->prepare('DELETE FROM volunteer_profiles WHERE user_id = :id')->execute(['id' => $uid]);
$pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $uid]);

echo "\n--------------------------------------------------------\n";
echo "TEST RESULTS: Total: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================\n";

exit($failed === 0 ? 0 : 1);
