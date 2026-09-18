<?php

namespace App\Controllers;

use App\Core\Router;
use App\Models\User;
use App\Models\ElderlyProfile;
use App\Models\VolunteerProfile;

class AuthController
{
    public function login(): void
    {
        $input = Router::getJsonInput();
        $phone = trim($input['phone'] ?? '');
        $password = trim($input['password'] ?? '');

        if (!$phone || !$password) {
            Router::json(['error' => 'Укажите номер телефона и пароль'], 400);
        }

        $user = User::findByPhone($phone);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            Router::json(['error' => 'Неверный номер телефона или пароль'], 401);
        }

        $withProfile = User::getWithProfile((int)$user['id']);
        unset($withProfile['password_hash']);

        Router::json([
            'success' => true,
            'message' => 'Успешный вход в систему',
            'user' => $withProfile
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
                'user_id' => $userId,
                'address' => $input['address'] ?? 'г. Минск',
                'latitude' => (float)($input['latitude'] ?? 53.9000),
                'longitude' => (float)($input['longitude'] ?? 27.5667),
                'birth_year' => (int)($input['birth_year'] ?? 1950),
                'mobility_notes' => $input['mobility_notes'] ?? '',
                'emergency_contact' => $input['emergency_contact'] ?? ''
            ]);
        } elseif ($roleId === 2) { // Volunteer
            VolunteerProfile::create([
                'user_id' => $userId,
                'is_verified' => 0,
                'rating' => 5.0,
                'points' => 0,
                'latitude' => (float)($input['latitude'] ?? 53.9000),
                'longitude' => (float)($input['longitude'] ?? 27.5667),
                'radius_km' => (float)($input['radius_km'] ?? 5.0),
                'skills' => $input['skills'] ?? '',
                'completed_tasks_count' => 0
            ]);
        }

        $user = User::getWithProfile((int)$userId);
        unset($user['password_hash']);

        Router::json([
            'success' => true,
            'message' => 'Регистрация прошла успешно',
            'user' => $user
        ], 201);
    }
}
