<?php

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Model.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/HelpRequest.php';
require_once __DIR__ . '/../app/Services/GeoMatchingService.php';
require_once __DIR__ . '/../app/Services/SecurityService.php';
require_once __DIR__ . '/../app/Services/AntiFraudService.php';

use App\Services\GeoMatchingService;
use App\Services\SecurityService;
use App\Services\AntiFraudService;
use App\Models\User;
use App\Models\HelpRequest;

echo "========================================================\n";
echo "   RUNNING AUTOMATED TEST SUITE (PHP 8+)\n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$name} - {$details}\n";
        $failed++;
    }
}

echo "[TEST SUITE 1] Geo-Matching & Haversine Distance:\n";
$distKm = GeoMatchingService::calculateDistance(53.9168, 27.5862, 53.9180, 27.5900);
assertTest('Distance between nearby Minsk points is ~0.3 km', $distKm >= 0.25 && $distKm <= 0.45, "Got: {$distKm} km");

$zeroDist = GeoMatchingService::calculateDistance(53.9000, 27.5600, 53.9000, 27.5600);
assertTest('Identical points give 0.0 km', $zeroDist === 0.0, "Got: {$zeroDist}");

$request = ['latitude' => 53.9168, 'longitude' => 27.5862];
$volunteers = [
    ['latitude' => 53.9180, 'longitude' => 27.5900, 'rating' => 4.9, 'points' => 200, 'user_id' => 2],
    ['latitude' => 53.9500, 'longitude' => 27.6500, 'rating' => 5.0, 'points' => 50, 'user_id' => 3]
];
$ranked = GeoMatchingService::rankVolunteers($request, $volunteers, 10.0);
assertTest('Closer volunteer gets ranked higher', $ranked[0]['user_id'] === 2);

echo "\n[TEST SUITE 2] Security Verification Service:\n";
$code = SecurityService::generateSecretCode();
assertTest('Generated secret code is non-empty', !empty($code));
assertTest('Case-insensitive code verification passes', SecurityService::verifyCode(mb_strtolower($code), $code));
assertTest('Invalid secret code fails verification', !SecurityService::verifyCode('НеверноеСлово', $code));

echo "\n[TEST SUITE 3] Anti-Fraud Heuristic Analyzer:\n";
$dangerCheck = AntiFraudService::analyze('Здравствуйте! Сообщите пожалуйста код из смс и три цифры на обороте карты');
assertTest('Danger pattern "код из смс" detected', $dangerCheck['risk_level'] === 'danger');
assertTest('Contains matching advice', count($dangerCheck['danger_matches']) >= 2);

$warningCheck = AntiFraudService::analyze('Вам звонят в вайбер по поводу проверки счетчиков');
assertTest('Warning pattern detected', $warningCheck['risk_level'] === 'warning');

$safeCheck = AntiFraudService::analyze('Купите пожалуйста буханку хлеба и пачку гречки');
assertTest('Benign request marked as safe', $safeCheck['risk_level'] === 'safe');

echo "\n--------------------------------------------------------\n";
echo "TEST RESULTS: Total: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}