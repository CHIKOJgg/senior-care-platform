<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Router;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Services\AuthService;
use App\Services\PasswordResetService;
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
        $phone = trim($input['phone'] ?? '');
        $password = trim($input['password'] ?? '');

        if (!$phone || !$password) {
            Router::json(['error' => 'Укажите номер телефона и пароль'], 400);
        }

        $user = User::findByPhone($phone);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            Logger::warning("Failed login attempt for phone {$phone} from IP {$ip}");
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
        if ($remember) {
            @setcookie('auth_token', $token, [
                'expires' => time() + $ttl,
                'path' => '/',
                'httponly' => true,
                'secure' => !empty($_SERVER['HTTPS']),
                'samesite' => 'Lax'
            ]);
        }

        Logger::info("User #{$user['id']} ({$user['name']}) logged in successfully");

        Router::json([
            'success' => true,
            'message' => 'Успешный вход в систему',
            'token' => $token,
            'user' => $withProfile
        ]);
    }

    public function changePassword(): void
    {
        $current = AuthService::requireAuth();
        $userId = (int)$current['user_id'];

        if (!RateLimiter::check("password_change:{$userId}", 5, 300)) {
            Router::json(['error' => 'Слишком много попыток. Подождите 5 минут.'], 429);
        }

        $input = Router::getJsonInput();
        $currentPassword = (string)($input['current_password'] ?? '');
        $newPassword = (string)($input['new_password'] ?? '');

        if (mb_strlen($newPassword) < 8) {
            Router::json(['error' => 'Новый пароль должен содержать не менее 8 символов'], 400);
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
        if ($remember) {
            @setcookie('auth_token', $token, [
                'expires' => time() + $ttl,
                'path' => '/',
                'httponly' => true,
                'secure' => !empty($_SERVER['HTTPS']),
                'samesite' => 'Lax'
            ]);
        }

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
        $phone = trim((string)($input['phone'] ?? ''));
        if ($phone === '') {
            Router::json(['error' => 'Укажите номер телефона'], 400);
        }

        $user = User::findByPhone($phone);
        if (!$user) {
            Router::json(['error' => 'Пользователь с таким номером не найден'], 404);
        }

        $profile = User::getWithProfile((int)$user['id']);
        if (!in_array($profile['role_code'] ?? '', ['elderly', 'volunteer'], true)) {
            Router::json(['error' => 'Для этой роли восстановление через координатора недоступно'], 403);
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
        $phone = trim((string)($input['phone'] ?? ''));
        $code = trim((string)($input['code'] ?? ''));
        $newPassword = (string)($input['new_password'] ?? '');

        if ($phone === '' || $code === '') {
            Router::json(['error' => 'Укажите телефон и код подтверждения'], 400);
        }
        if (mb_strlen($newPassword) < 8) {
            Router::json(['error' => 'Новый пароль должен содержать не менее 8 символов'], 400);
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
        $input = Router::getJsonInput();
        $phone = trim($input['phone'] ?? '');
        $name = trim($input['name'] ?? '');
        $password = trim($input['password'] ?? '');
        $roleId = (int)($input['role_id'] ?? 1); // 1 = elderly, 2 = volunteer

        if (!$phone || !$name || !$password) {
            Router::json(['error' => 'Заполните все обязательные поля'], 400);
        }

        if (User::findByPhone($phone)) {
            Router::json(['error' => 'Пользователь с таким номером уже зарегистрирован'], 400);
        }

        $userId = User::create([
            'role_id' => $roleId,
            'phone' => $phone,
            'name' => $name,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT)
        ]);

        if ($roleId === 1) { // Elderly
            ElderlyProfile::create([
                'user_id' => (int)$userId,
                'address' => $input['address'] ?? 'г. Минск, пр-т Независимости, 45, кв. 12',
                'latitude' => (float)($input['latitude'] ?? 53.9168),
                'longitude' => (float)($input['longitude'] ?? 27.5862),
                'birth_year' => (int)($input['birth_year'] ?? 1950),
                'mobility_notes' => $input['mobility_notes'] ?? '',
                'emergency_contact' => $input['emergency_contact'] ?? ''
            ]);
        } elseif ($roleId === 2) { // Volunteer
            VolunteerProfile::create([
                'user_id' => (int)$userId,
                'is_verified' => 0, // Pending verification
                'rating' => 5.0,
                'points' => 0,
                'latitude' => (float)($input['latitude'] ?? 53.9180),
                'longitude' => (float)($input['longitude'] ?? 27.5900),
                'radius_km' => (float)($input['radius_km'] ?? 5.0),
                'skills' => $input['skills'] ?? 'помощь по дому, покупка продуктов',
                'completed_tasks_count' => 0
            ]);
        }

        $user = User::getWithProfile((int)$userId);
        if ($user) {
            unset($user['password_hash']);
        }

        $token = AuthService::createToken($user);
        @setcookie('auth_token', $token, [
            'expires' => time() + 86400 * 7,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        Logger::info("New user registered: #{$userId} ({$name}, role {$roleId})");

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
        @setcookie('auth_token', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        Router::json([
            'success' => true,
            'message' => 'Сессия завершена'
        ]);
    }
}
