<?php

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Model.php';
require_once __DIR__ . '/../app/Models/Role.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/ElderlyProfile.php';
require_once __DIR__ . '/../app/Models/VolunteerProfile.php';
require_once __DIR__ . '/../app/Models/Category.php';
require_once __DIR__ . '/../app/Models/HelpRequest.php';
require_once __DIR__ . '/../app/Models/EducationalMaterial.php';
require_once __DIR__ . '/../app/Models/FraudAlert.php';

use App\Core\Database;
use App\Models\Role;
use App\Models\User;
use App\Models\ElderlyProfile;
use App\Models\VolunteerProfile;
use App\Models\Category;
use App\Models\HelpRequest;
use App\Models\EducationalMaterial;
use App\Models\FraudAlert;

echo "[SEED] Seeding demo data...\n";

$pdo = Database::getConnection();

$roles = [
    ['code' => 'elderly', 'name' => 'Пожилой человек'],
    ['code' => 'volunteer', 'name' => 'Волонтёр'],
    ['code' => 'coordinator', 'name' => 'Координатор соцслужбы'],
    ['code' => 'admin', 'name' => 'Администратор']
];
foreach ($roles as $r) {
    if (!Role::where('code', $r['code'])) {
        Role::create($r);
    }
}

$categories = [
    ['code' => 'groceries', 'title' => 'Покупка продуктов', 'icon' => '🛒', 'description' => 'Доставка базового набора продуктов из магазина'],
    ['code' => 'pharmacy', 'title' => 'Лекарства из аптеки', 'icon' => '💊', 'description' => 'Покупка и доставка лекарств по рецепту или списку'],
    ['code' => 'household', 'title' => 'Бытовая помощь', 'icon' => '🧹', 'description' => 'Вынос мусора, замена лампочки, мелкая помощь по дому'],
    ['code' => 'tech', 'title' => 'Помощь с телефоном', 'icon' => '📱', 'description' => 'Настройка смартфона, звонки близким, оплата услуг ЖКХ'],
    ['code' => 'escort', 'title' => 'Сопровождение', 'icon' => '🚶', 'description' => 'Сопровождение в поликлинику, банк или на прогулку'],
    ['code' => 'chat', 'title' => 'Душевное общение', 'icon' => '☕', 'description' => 'Беседа за чаем, чтение книг, социальная поддержка']
];
foreach ($categories as $c) {
    if (!Category::where('code', $c['code'])) {
        Category::create($c);
    }
}

$password = password_hash('password123', PASSWORD_DEFAULT);

if (!User::findByPhone('+375291112233')) {
    $uid = User::create([
        'role_id' => 1,
        'phone' => '+375291112233',
        'name' => 'Анна Ивановна Ковалёва',
        'password_hash' => $password
    ]);
    ElderlyProfile::create([
        'user_id' => $uid,
        'address' => 'г. Минск, пр-т Независимости, 45, кв. 12',
        'latitude' => 53.9168,
        'longitude' => 27.5862,
        'birth_year' => 1948,
        'mobility_notes' => 'Трудно спускаться по лестнице, 4 этаж',
        'emergency_contact' => 'Дочь Ольга (+375293334455)'
    ]);
}

if (!User::findByPhone('+375292223344')) {
    $vid = User::create([
        'role_id' => 2,
        'phone' => '+375292223344',
        'name' => 'Алексей Смирнов',
        'password_hash' => $password
    ]);
    VolunteerProfile::create([
        'user_id' => $vid,
        'is_verified' => 1,
        'rating' => 4.9,
        'points' => 180,
        'latitude' => 53.9180,
        'longitude' => 27.5900,
        'radius_km' => 5.0,
        'skills' => 'автомобиль, покупка продуктов, мелкий ремонт',
        'completed_tasks_count' => 14
    ]);
}

if (!User::findByPhone('+375295556677')) {
    User::create([
        'role_id' => 3,
        'phone' => '+375295556677',
        'name' => 'Светлана Михайловна (ТЦСОН)',
        'password_hash' => $password
    ]);
}

if (!User::findByPhone('+375299999999')) {
    User::create([
        'role_id' => 4,
        'phone' => '+375299999999',
        'name' => 'Администратор Системы',
        'password_hash' => $password
    ]);
}

if (empty(HelpRequest::all())) {
    HelpRequest::create([
        'elderly_id' => 1,
        'category_id' => 1,
        'title' => 'Доставка молока, хлеба и овсяных хлопьев',
        'description' => 'Пожалуйста, купите 1 л молока 2.5%, батон нарезной и овсяные хлопья. Деньги отдам наличными.',
        'latitude' => 53.9168,
        'longitude' => 27.5862,
        'address' => 'пр-т Независимости, 45, кв. 12',
        'secret_code' => 'Василёк',
        'status' => 'open'
    ]);
}

if (empty(EducationalMaterial::all())) {
    EducationalMaterial::create([
        'title' => 'Как распознать звонок лже-банковского работника',
        'type' => 'memo',
        'category' => 'Безопасность',
        'content' => 'Запомните главное правило: сотрудники банка НИКОГДА не звонят через мессенджеры (Вайбер, Телеграм) и НИКОГДА не спрашивают три цифры с оборота карты или код из СМС!'
    ]);
}

if (empty(FraudAlert::all())) {
    FraudAlert::create([
        'title' => 'Внимание: активизировались лже-сантехники!',
        'description' => 'В Советском районе неизвестные предлагают платную проверку вентиляции и фильтров по завышенным ценам.',
        'severity' => 'danger',
        'is_active' => 1
    ]);
}

echo "[SEED] Success!\n";
