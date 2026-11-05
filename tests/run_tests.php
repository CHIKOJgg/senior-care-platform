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
require_once __DIR__ . '/../app/Models/Role.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/ElderlyProfile.php';
require_once __DIR__ . '/../app/Models/VolunteerProfile.php';
require_once __DIR__ . '/../app/Models/Category.php';
require_once __DIR__ . '/../app/Models/HelpRequest.php';
require_once __DIR__ . '/../app/Models/FraudAlert.php';
require_once __DIR__ . '/../app/Services/CryptoService.php';
require_once __DIR__ . '/../app/Services/GeoMatchingService.php';
require_once __DIR__ . '/../app/Services/SecurityService.php';
require_once __DIR__ . '/../app/Services/AntiFraudService.php';
require_once __DIR__ . '/../app/Services/AuthService.php';
require_once __DIR__ . '/../app/Services/PasswordResetService.php';
require_once __DIR__ . '/../app/Services/CryptoService.php';

use App\Services\GeoMatchingService;
use App\Services\SecurityService;
use App\Services\AntiFraudService;
use App\Services\AuthService;
use App\Services\PasswordResetService;
use App\Core\RateLimiter;
use App\Core\Database;
use App\Models\User;
use App\Models\HelpRequest;
use App\Models\VolunteerProfile;
use App\Models\ElderlyProfile;
use App\Models\Category;
use App\Models\FraudAlert;

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

echo "\n[TEST SUITE 4] User Model & Profile Integration:\n";
$elderlyUser = User::getWithProfile(1);
assertTest('Elderly user profile loaded correctly', $elderlyUser !== null && $elderlyUser['profile_type'] === 'elderly');

$volunteerUser = User::getWithProfile(2);
assertTest('Volunteer user profile loaded correctly', $volunteerUser !== null && $volunteerUser['profile_type'] === 'volunteer');

echo "\n[TEST SUITE 5] Volunteer Verification & Protection Logic:\n";
// Create test volunteer
$testPhone = '+37529' . rand(1000000, 9999999);
$testVid = (int)User::create([
    'role_id' => 2,
    'phone' => $testPhone,
    'name' => 'Тестовый Волонтёр Проверки',
    'password_hash' => password_hash('pass123', PASSWORD_DEFAULT)
]);
VolunteerProfile::create([
    'user_id' => $testVid,
    'is_verified' => 0, // initially unverified
    'rating' => 5.0,
    'points' => 0,
    'latitude' => 53.9180,
    'longitude' => 27.5900,
    'radius_km' => 5.0,
    'skills' => 'тестирование',
    'completed_tasks_count' => 0
]);

$unverifiedProfile = VolunteerProfile::find($testVid);
assertTest('New volunteer starts unverified (is_verified = 0)', (int)$unverifiedProfile['is_verified'] === 0);

// Admin approves volunteer
VolunteerProfile::setVerificationStatus($testVid, 1);
$verifiedProfile = VolunteerProfile::find($testVid);
assertTest('Admin verification activates volunteer (is_verified = 1)', (int)$verifiedProfile['is_verified'] === 1);

// Admin rejects volunteer
VolunteerProfile::setVerificationStatus($testVid, -1);
$rejectedProfile = VolunteerProfile::find($testVid);
assertTest('Admin rejection disables volunteer (is_verified = -1)', (int)$rejectedProfile['is_verified'] === -1);

echo "\n[TEST SUITE 6] User Requests & Secret Code Integrity:\n";
$myRequests = HelpRequest::getByElderly(1);
assertTest('Elderly requests list retrieved', is_array($myRequests) && count($myRequests) > 0);
assertTest('Each request contains secret safety code', !empty($myRequests[0]['secret_code']));

echo "\n[TEST SUITE 7] Stateless Token Authentication & HMAC Security:\n";
$token = AuthService::createToken([
    'id' => 4,
    'role_id' => 4,
    'role_code' => 'admin',
    'name' => 'Админ',
    'phone' => '+375299999999'
]);
assertTest('Token successfully generated', !empty($token) && str_contains($token, '.'));

$decoded = AuthService::verifyToken($token);
assertTest('Token verified and payload extracted', $decoded !== null && $decoded['user_id'] === 4 && $decoded['role_code'] === 'admin');

// Tampered token test
$tamperedToken = $token . 'tamper';
$tamperedResult = AuthService::verifyToken($tamperedToken);
assertTest('Tampered token is rejected', $tamperedResult === null);

echo "\n[TEST SUITE 8] Rate Limiter Security:\n";
$testKey = 'unit_test_limiter_' . time();
RateLimiter::clear($testKey);
assertTest('First check succeeds', RateLimiter::check($testKey, 2, 10) === true);
assertTest('Second check succeeds', RateLimiter::check($testKey, 2, 10) === true);
assertTest('Third check fails (rate limit exceeded)', RateLimiter::check($testKey, 2, 10) === false);
RateLimiter::clear($testKey);

echo "\n[TEST SUITE 9] Token Revocation & Session Security:\n";
$revPhone = '+37599' . substr((string)time(), -7);
$revId = (int)User::create([
    'role_id' => 1,
    'phone' => $revPhone,
    'name' => 'Тест Отзыва Токена',
    'password_hash' => password_hash('RevokeTest!2026', PASSWORD_DEFAULT)
]);
$revToken = AuthService::createToken(User::getWithProfile($revId));
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $revToken;
$beforeRevoke = AuthService::getCurrentUser();
assertTest('Active token authenticates user', $beforeRevoke !== null && (int)$beforeRevoke['user_id'] === $revId);

User::update($revId, ['token_version' => 2]);
$afterRevoke = AuthService::getCurrentUser();
assertTest('Token revoked after token_version increment', $afterRevoke === null);

unset($_SERVER['HTTP_AUTHORIZATION']);
User::delete($revId);

echo "\n[TEST SUITE 10] Password Recovery with One-Time Code:\n";
$prPhone = '+37598' . substr((string)(time() + 1), -7);
$prId = (int)User::create([
    'role_id' => 1,
    'phone' => $prPhone,
    'name' => 'Тест Восстановления',
    'password_hash' => password_hash('StartPass!2026', PASSWORD_DEFAULT)
]);
$prToken = AuthService::createToken(User::getWithProfile($prId));

$issuedExpired = PasswordResetService::createCode($prId, 4);
assertTest('Reset code has 6 digits', (bool)preg_match('/^\d{6}$/', $issuedExpired['code']));
$pdo = Database::getConnection();
$pdo->prepare("UPDATE password_resets SET expires_at = :past WHERE user_id = :uid AND used_at IS NULL")
    ->execute(['past' => date('Y-m-d H:i:s', time() - 60), 'uid' => $prId]);
$expiredResult = PasswordResetService::resetPassword($prPhone, $issuedExpired['code'], 'NewPass!2026');
assertTest('Expired reset code is rejected', $expiredResult['success'] === false);

$issued = PasswordResetService::createCode($prId, 4);
$wrongCode = $issued['code'] === '000000' ? '111111' : '000000';
$wrongResult = PasswordResetService::resetPassword($prPhone, $wrongCode, 'NewPass!2026');
assertTest('Wrong reset code is rejected', $wrongResult['success'] === false);

$okResult = PasswordResetService::resetPassword($prPhone, $issued['code'], 'NewPass!2026');
assertTest('Valid reset code changes password', $okResult['success'] === true);
$afterReset = User::find($prId);
assertTest('New password hash is stored', password_verify('NewPass!2026', $afterReset['password_hash']));

$reuseResult = PasswordResetService::resetPassword($prPhone, $issued['code'], 'OtherPass!2026');
assertTest('Reset code cannot be reused', $reuseResult['success'] === false);

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $prToken;
assertTest('Old token revoked after password reset', AuthService::getCurrentUser() === null);
unset($_SERVER['HTTP_AUTHORIZATION']);

$pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$prId]);
User::delete($prId);

echo "\n--------------------------------------------------------\n";
echo "TEST RESULTS: Total: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
