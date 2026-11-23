<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Router;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Services\AuthService;
use App\Services\PasswordResetService;
use App\Services\RegionService;
use App\Models\User;
use App\Models\ElderlyProfile;
use App\Models\VolunteerProfile;

class AuthController
{
    public function login(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!RateLimiter::check("login_attempts:{$ip}", 10, 300)) {
            Logger::warning("Rate limit exceeded for login on IP {$ip}");
            Router::json(['error' => 'Слишком много попыток входа. Пожалуйста, подождите 5 минут.'], 429);
        }

        $input = Router::getJsonInput();
        $phone = is_string($input['phone'] ?? null) ? trim($input['phone']) : '';
        $password = is_string($input['password'] ?? null) ? (string)$input['password'] : '';

        if (!$phone || !$password) {
            Router::json(['error' => 'Укажите номер телефона и пароль'], 400);
        }

        $user = User::findByPhone($phone);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            Logger::warning("Failed login attempt from IP {$ip}");
            Router::json(['error' => 'Неверный номер телефона или пароль'], 401);
        }

        RateLimiter::clear("login_attempts:{$ip}");

        $withProfile = User::getWithProfile((int)$user['id']);
        if ($withProfile) {
            unset($withProfile['password_hash']);
        }

        $remember = !empty($input['remember']) && ($withProfile['role_code'] ?? '') === 'elderly';
        $ttl = $remember ? AuthService::TTL_DEVICE : AuthService::TTL_SESSION;
        $token = AuthService::createToken($withProfile, $ttl);
        // Always establish the HttpOnly auth cookie: the server uses it to render only
        // this account's role workspace. "Remember" merely extends the TTL for seniors.
        @setcookie('auth_token', $token, [
            'expires' => time() + $ttl,
            'path' => '/',
            'httponly' => true,
            'secure' => AuthService::cookieSecure(),
            'samesite' => 'Lax'
        ]);

        Logger::info("User #{$user['id']} ({$user['name']}) logged in successfully");

        Router::json([
            'success' => true,
            'message' => 'Успешный вход в систему',
            'token' => $token,
            'user' => $withProfile
        ]);
    }

    /**
     * Explicit passwordless demo login for a senior device.
     *
     * This is intentionally opt-in (ALLOW_DEMO_AUTH=true in local/testing) and
     * never available in production. Kiosk deployments that need completely
     * automatic provisioning must set ELDERLY_AUTO_LOGIN=true explicitly.
     */
    public function deviceLogin(): void
    {
        if (!AuthService::isDemoAuthAllowed()) {
            Router::json(['error' => 'Вход по устройству недоступен', 'code' => 'FORBIDDEN'], 403);
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!RateLimiter::check("device_login:{$ip}", 10, 300)) {
            Logger::warning("Rate limit exceeded for device login on IP {$ip}");
            Router::json(['error' => 'Слишком много попыток входа. Подождите 5 минут.'], 429);
        }

        $phone = getenv('ELDERLY_DEMO_PHONE') ?: '+375291112233';
        $user = User::findByPhone($phone);
        $profile = $user ? User::getWithProfile((int)$user['id']) : null;

        if (!$profile || ($profile['role_code'] ?? '') !== 'elderly') {
            Router::json(['error' => 'Демонстрационный профиль подопечного не найден'], 404);
        }

        unset($profile['password_hash']);
        $token = AuthService::createToken($profile, AuthService::TTL_DEVICE);
        @setcookie('auth_token', $token, [
            'expires' => time() + AuthService::TTL_DEVICE,
            'path' => '/',
            'httponly' => true,
            'secure' => AuthService::cookieSecure(),
            'samesite' => 'Lax'
        ]);

        RateLimiter::clear("device_login:{$ip}");
        Logger::info("Demo senior device login for user #{$profile['id']}");

        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            Router::json([
                'success' => true,
                'message' => 'Вход по устройству выполнен',
                'token' => $token,
                'user' => $profile
            ]);
        }

        header('Location: /', true, 303);
        exit;
    }

    public function changePassword(): void
    {
        $current = AuthService::requireAuth();
        $userId = (int)$current['user_id'];

        if (!RateLimiter::check("password_change:{$userId}", 5, 300)) {
            Router::json(['error' => 'Слишком много попыток. Подождите 5 минут.'], 429);
        }

        $input = Router::getJsonInput();
        $currentPassword = is_string($input['current_password'] ?? null) ? (string)$input['current_password'] : '';
        $newPassword = is_string($input['new_password'] ?? null) ? (string)$input['new_password'] : '';

        if (mb_strlen($newPassword) < 8) {
            Router::json(['error' => 'Новый пароль должен содержать не менее 8 символов'], 400);
        }
        if (mb_strlen($newPassword) > 72) {
            Router::json(['error' => 'Новый пароль не должен превышать 72 символа'], 400);
        }

        $user = User::find($userId);
        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            Logger::warning("Failed password change for user #{$userId}");
            Router::json(['error' => 'Текущий пароль указан неверно'], 400);
        }

        $newVersion = (int)($user['token_version'] ?? 1) + 1;
        User::update($userId, [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'token_version' => $newVersion
        ]);
        RateLimiter::clear("password_change:{$userId}");

        $withProfile = User::getWithProfile($userId);
        if ($withProfile) {
            unset($withProfile['password_hash']);
        }

        $remember = !empty($input['remember']) && ($withProfile['role_code'] ?? '') === 'elderly';
        $ttl = $remember ? AuthService::TTL_DEVICE : AuthService::TTL_SESSION;
        $token = AuthService::createToken($withProfile, $ttl);
        // Always establish the HttpOnly auth cookie: the server uses it to render only
        // this account's role workspace. "Remember" merely extends the TTL for seniors.
        @setcookie('auth_token', $token, [
            'expires' => time() + $ttl,
            'path' => '/',
            'httponly' => true,
            'secure' => AuthService::cookieSecure(),
            'samesite' => 'Lax'
        ]);

        Logger::info("User #{$userId} changed password; other sessions revoked");

        Router::json([
            'success' => true,
            'message' => 'Пароль изменён. Остальные устройства вышли из системы.',
            'token' => $token,
            'user' => $withProfile
        ]);
    }

    public function logoutAll(): void
    {
        $current = AuthService::requireAuth();
        $user = User::find((int)$current['user_id']);
        if ($user) {
            User::update((int)$user['id'], [
                'token_version' => (int)($user['token_version'] ?? 1) + 1
            ]);
        }

        @setcookie('auth_token', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'secure' => AuthService::cookieSecure(),
            'samesite' => 'Lax'
        ]);

        Logger::info("User #{$current['user_id']} logged out from all devices");

        Router::json([
            'success' => true,
            'message' => 'Все устройства вышли из системы'
        ]);
    }

    /**
     * Coordinator issues a one-time code for a pensioner or volunteer.
     */
    public function requestPasswordReset(): void
    {
        $admin = AuthService::requireAuth(['coordinator', 'admin']);
        $adminId = (int)$admin['user_id'];

        if (!RateLimiter::check("reset_code:{$adminId}", 10, 300)) {
            Router::json(['error' => 'Слишком много запросов кодов. Подождите 5 минут.'], 429);
        }

        $input = Router::getJsonInput();
        $phone = is_string($input['phone'] ?? null) ? trim($input['phone']) : '';
        if ($phone === '') {
            Router::json(['error' => 'Укажите номер телефона'], 400);
        }

        $user = User::findByPhone($phone);
        if (!$user) {
            Router::json(['error' => 'Пользователь не найден или восстановление недоступно'], 404);
        }

        $profile = User::getWithProfile((int)$user['id']);
        if (!in_array($profile['role_code'] ?? '', ['elderly', 'volunteer'], true)) {
            Router::json(['error' => 'Пользователь не найден или восстановление недоступно'], 404);
        }

        $issued = PasswordResetService::createCode((int)$user['id'], $adminId);

        Router::json([
            'success' => true,
            'code' => $issued['code'],
            'expires_in' => $issued['expires_in'],
            'user' => [
                'id' => (int)$user['id'],
                'name' => $user['name']
            ]
        ]);
    }

    /**
     * Public: set a new password using a one-time coordinator code.
     */
    public function resetPassword(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!RateLimiter::check("reset_password:{$ip}", 20, 300)) {
            Router::json(['error' => 'Слишком много попыток. Подождите 5 минут.'], 429);
        }

        $input = Router::getJsonInput();
        $phone = is_string($input['phone'] ?? null) ? trim($input['phone']) : '';
        $code = is_string($input['code'] ?? null) ? trim($input['code']) : '';
        $newPassword = is_string($input['new_password'] ?? null) ? (string)$input['new_password'] : '';

        if ($phone === '' || $code === '') {
            Router::json(['error' => 'Укажите телефон и код подтверждения'], 400);
        }
        if (mb_strlen($newPassword) < 8) {
            Router::json(['error' => 'Новый пароль должен содержать не менее 8 символов'], 400);
        }
        if (mb_strlen($newPassword) > 72) {
            Router::json(['error' => 'Новый пароль не должен превышать 72 символа'], 400);
        }

        $result = PasswordResetService::resetPassword($phone, $code, $newPassword);
        if (!$result['success']) {
            Router::json(['error' => $result['error']], 400);
        }

        RateLimiter::clear("reset_password:{$ip}");

        Router::json([
            'success' => true,
            'message' => 'Пароль изменён. Войдите с новым паролем.'
        ]);
    }

    public function register(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $env = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'local');
        $maxAttempts = in_array($env, ['local', 'testing'], true) ? 1000 : 50;
        if (!RateLimiter::check("register:{$ip}", $maxAttempts, 300)) {
            Router::json(['error' => 'Слишком много попыток регистрации. Подождите 5 минут.'], 429);
        }

        $input = Router::getJsonInput();
        $phone = is_string($input['phone'] ?? null) ? trim($input['phone']) : '';
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        $password = is_string($input['password'] ?? null) ? (string)$input['password'] : '';
        $roleId = (int)($input['role_id'] ?? 1); // 1 = elderly, 2 = volunteer
        // Security: prevent self-registration as coordinator/admin
        if (!in_array($roleId, [1, 2], true)) {
            $roleId = 1;
        }

        if (!$phone || !$name || !$password) {
            Router::json(['error' => 'Заполните все обязательные поля'], 400);
        }

        if (mb_strlen($phone) > 30 || !preg_match('/^\+?[0-9()\-\s]{7,30}$/', $phone)) {
            Router::json(['error' => 'Некорректный формат номера телефона'], 400);
        }
        if (mb_strlen($name) > 150) {
            Router::json(['error' => 'Имя не должно превышать 150 символов'], 400);
        }
        if (mb_strlen($password) < 8 || mb_strlen($password) > 72) {
            Router::json(['error' => 'Пароль должен содержать от 8 до 72 символов'], 400);
        }

        if (User::findByPhone($phone)) {
            Router::json(['error' => 'Пользователь с таким номером уже зарегистрирован'], 400);
        }

        $pdo = \App\Core\Database::getConnection();
        try {
            $pdo->beginTransaction();

            $userId = User::create([
                'role_id' => $roleId,
                'phone' => $phone,
                'name' => $name,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT)
            ]);

            // Country-wide: region is explicit or derived from coordinates (nearest regional centre).
            $profileLat = isset($input['latitude']) && is_numeric($input['latitude']) ? (float)$input['latitude'] : null;
            $profileLon = isset($input['longitude']) && is_numeric($input['longitude']) ? (float)$input['longitude'] : null;
            $regionCode = RegionService::normalize(
                isset($input['region_code']) ? (string)$input['region_code'] : null,
                $profileLat,
                $profileLon
            );
            $regionCenter = RegionService::center($regionCode);
            $profileLat = $profileLat ?? $regionCenter['lat'];
            $profileLon = $profileLon ?? $regionCenter['lon'];
            $birthYear = (int)($input['birth_year'] ?? 1950);
            if ($birthYear < 1900 || $birthYear > (int)date('Y')) {
                $birthYear = 1950;
            }

            if ($roleId === 1) { // Elderly
                ElderlyProfile::create([
                    'user_id' => (int)$userId,
                    'address' => mb_substr(trim((string)($input['address'] ?? ('Республика Беларусь, ' . RegionService::name($regionCode)))), 0, 500, 'UTF-8'),
                    'latitude' => $profileLat,
                    'longitude' => $profileLon,
                    'region_code' => $regionCode,
                    'birth_year' => $birthYear,
                    'mobility_notes' => mb_substr(trim((string)($input['mobility_notes'] ?? '')), 0, 2000, 'UTF-8'),
                    'emergency_contact' => mb_substr(trim((string)($input['emergency_contact'] ?? '')), 0, 100, 'UTF-8')
                ]);
            } elseif ($roleId === 2) { // Volunteer
                $radius = (float)($input['radius_km'] ?? 5.0);
                if ($radius <= 0 || $radius > 100) {
                    $radius = 5.0;
                }
                VolunteerProfile::create([
                    'user_id' => (int)$userId,
                    'is_verified' => 0, // Pending verification
                    'rating' => 5.0,
                    'points' => 0,
                    'latitude' => $profileLat,
                    'longitude' => $profileLon,
                    'region_code' => $regionCode,
                    'radius_km' => $radius,
                    'skills' => mb_substr(trim((string)($input['skills'] ?? 'помощь по дому, покупка продуктов')), 0, 1000, 'UTF-8'),
                    'completed_tasks_count' => 0
                ]);
            }

            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::warning('Registration failed', ['phone' => $phone, 'error' => $e->getMessage()]);
            Router::json(['error' => $e->getCode() === '23000'
                ? 'Пользователь с таким номером уже зарегистрирован'
                : 'Не удалось завершить регистрацию'], $e->getCode() === '23000' ? 400 : 500);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error('Registration failed', ['phone' => $phone, 'error' => $e->getMessage()]);
            Router::json(['error' => 'Не удалось завершить регистрацию'], 500);
        }

        $user = User::getWithProfile((int)$userId);
        if ($user) {
            unset($user['password_hash']);
        }

        $token = AuthService::createToken($user);
        @setcookie('auth_token', $token, [
            'expires' => time() + AuthService::TTL_SESSION,
            'path' => '/',
            'httponly' => true,
            'secure' => AuthService::cookieSecure(),
            'samesite' => 'Lax'
        ]);

        Logger::info("New user registered: #{$userId}, role {$roleId}");

        Router::json([
            'success' => true,
            'message' => 'Регистрация прошла успешно',
            'token' => $token,
            'user' => $user
        ], 201);
    }

    public function me(): void
    {
        $currentUser = AuthService::getCurrentUser();
        if (!$currentUser) {
            Router::json(['error' => 'Не авторизован'], 401);
        }

        $profile = User::getWithProfile((int)$currentUser['user_id']);
        if ($profile) {
            unset($profile['password_hash']);
        }

        Router::json([
            'success' => true,
            'user' => $profile
        ]);
    }

    public function logout(): void
    {
        // Stateless tokens cannot be individually invalidated; bumping
        // token_version revokes every token issued for this account.
        $hasExplicitAuth = !empty($_SERVER['HTTP_AUTHORIZATION'])
            || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
            || !empty($_SERVER['HTTP_X_AUTH_TOKEN'])
            || !empty($_COOKIE['auth_token']);
        $current = $hasExplicitAuth ? AuthService::getCurrentUser() : null;
        if ($current) {
            $user = User::find((int)$current['user_id']);
            if ($user) {
                User::update((int)$user['id'], [
                    'token_version' => (int)($user['token_version'] ?? 1) + 1
                ]);
            }
        }

        @setcookie('auth_token', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'secure' => AuthService::cookieSecure(),
            'samesite' => 'Lax'
        ]);

        Router::json([
            'success' => true,
            'message' => 'Сессия завершена'
        ]);
    }
}
