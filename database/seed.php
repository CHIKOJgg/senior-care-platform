<?php

declare(strict_types=1);

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
require_once __DIR__ . '/../app/Services/CryptoService.php';
require_once __DIR__ . '/../app/Services/SecurityService.php';

use App\Core\Database;
use App\Models\Role;
use App\Models\User;
use App\Models\ElderlyProfile;
use App\Models\VolunteerProfile;
use App\Models\Category;
use App\Models\HelpRequest;
use App\Models\EducationalMaterial;
use App\Models\FraudAlert;
use App\Services\SecurityService;

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
    ['code' => 'groceries', 'title' => 'Покупка продуктов', 'icon' => 'shopping-bag', 'description' => 'Доставка базового набора продуктов из магазина'],
    ['code' => 'pharmacy', 'title' => 'Лекарства из аптеки', 'icon' => 'pill', 'description' => 'Покупка и доставка лекарств по рецепту или списку'],
    ['code' => 'household', 'title' => 'Бытовая помощь', 'icon' => 'broom', 'description' => 'Вынос мусора, замена лампочки, мелкая помощь по дому'],
    ['code' => 'tech', 'title' => 'Помощь с телефоном', 'icon' => 'phone', 'description' => 'Настройка смартфона, звонки близким, оплата услуг ЖКХ'],
    ['code' => 'escort', 'title' => 'Сопровождение', 'icon' => 'walking', 'description' => 'Сопровождение в поликлинику, банк или на прогулку'],
    ['code' => 'chat', 'title' => 'Душевное общение', 'icon' => 'coffee', 'description' => 'Беседа за чаем, чтение книг, социальная поддержка']
];
foreach ($categories as $c) {
    $existing = Category::where('code', $c['code']);
    if (!$existing) {
        Category::create($c);
    } else {
        Category::update($existing[0]['id'], ['icon' => $c['icon']]);
    }
}

$isProduction = (getenv('APP_ENV') ?: 'local') === 'production';
$seedDemoUsers = !$isProduction || getenv('SEED_DEMO') === 'true';
$password = password_hash('password123', PASSWORD_DEFAULT);
$demoElderlyId = null;

if ($seedDemoUsers && !User::findByPhone('+375291112233')) {
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
        'region_code' => 'MINSK_CITY',
        'birth_year' => 1948,
        'mobility_notes' => 'Трудно спускаться по лестнице, 4 этаж',
        'emergency_contact' => 'Дочь Ольга (+375293334455)'
    ]);
    $demoElderlyId = (int)$uid;
} else {
    $existingDemoElderly = User::findByPhone('+375291112233');
    $demoElderlyId = $existingDemoElderly ? (int)$existingDemoElderly['id'] : null;
}

if ($seedDemoUsers && !User::findByPhone('+375292223344')) {
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
        'region_code' => 'MINSK_CITY',
        'radius_km' => 5.0,
        'skills' => 'автомобиль, покупка продуктов, мелкий ремонт',
        'completed_tasks_count' => 14
    ]);
}

if ($seedDemoUsers && !User::findByPhone('+375295556677')) {
    User::create([
        'role_id' => 3,
        'phone' => '+375295556677',
        'name' => 'Светлана Михайловна (ТЦСОН)',
        'password_hash' => $password,
        'region_code' => 'MINSK_CITY'
    ]);
}

if ($seedDemoUsers && !User::findByPhone('+375299999999')) {
    User::create([
        'role_id' => 4,
        'phone' => '+375299999999',
        'name' => 'Администратор Системы',
        'password_hash' => $password
    ]);
}

// Administrator from environment: created last so demo IDs stay stable (1=elderly, 2=volunteer,
// 3=coordinator, 4=admin). If ADMIN_PHONE matches the demo admin, this block is a no-op.
$adminPhone = getenv('ADMIN_PHONE') ?: '';
$adminPassword = getenv('ADMIN_PASSWORD') ?: '';
if ($adminPhone !== '' && $adminPassword !== '') {
    $existingAdmin = User::findByPhone($adminPhone);
    if (!$existingAdmin) {
        User::create([
            'role_id' => 4,
            'phone' => $adminPhone,
            'name' => 'Администратор ТЦСОН',
            'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT)
        ]);
        echo "[SEED] Admin created from ADMIN_PHONE\n";
    } elseif ($seedDemoUsers) {
        // Demo/staging only: keep the ADMIN_PHONE credentials authoritative.
        // Production (SEED_DEMO=false) never overwrites a rotated admin password.
        User::update((int)$existingAdmin['id'], [
            'role_id' => 4,
            'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT)
        ]);
        echo "[SEED] Admin credentials synchronized from ADMIN_PHONE\n";
    }
}

if ($seedDemoUsers && empty(HelpRequest::all()) && $demoElderlyId !== null) {
    HelpRequest::create([
        'elderly_id' => $demoElderlyId,
        'category_id' => 1,
        'title' => 'Доставка молока, хлеба и овсяных хлопьев',
        'description' => 'Пожалуйста, купите 1 л молока 2.5%, батон нарезной и овсяные хлопья. Деньги отдам наличными.',
        'latitude' => 53.9168,
        'longitude' => 27.5862,
        'region_code' => 'MINSK_CITY',
        'address' => 'пр-т Независимости, 45, кв. 12',
        'secret_code' => 'Василёк',
        'status' => 'open'
    ]);
}

// Country-wide demo coverage: one senior and one open request in every region of Belarus.
if ($seedDemoUsers) {
    $regionalDemo = [
        [
            'phone' => '+375291000001', 'name' => 'Мария Петровна Ковальчук',
            'address' => 'г. Брест, ул. Советская, 12', 'lat' => 52.0976, 'lon' => 23.7341,
            'region' => 'BREST', 'category' => 2,
            'title' => 'Лекарства из аптеки в Бресте',
            'description' => 'Нужны капли для сердца и обезболивающее по рецепту. Аптека у площади Ленина.'
        ],
        [
            'phone' => '+375291000002', 'name' => 'Иван Аркадьевич Петров',
            'address' => 'г. Витебск, пр-т Фрунзе, 20', 'lat' => 55.1904, 'lon' => 30.2049,
            'region' => 'VITEBSK', 'category' => 3,
            'title' => 'Помощь по дому в Витебске',
            'description' => 'Помогите заменить перегоревшую лампочку и вынести мусор.'
        ],
        [
            'phone' => '+375291000003', 'name' => 'Екатерина Семёновна Гомельская',
            'address' => 'г. Гомель, ул. Ленина, 10', 'lat' => 52.4345, 'lon' => 30.9754,
            'region' => 'GOMEL', 'category' => 1,
            'title' => 'Продукты для пожилой жительницы Гомеля',
            'description' => 'Купите, пожалуйста, хлеб, кефир, творог и яйца.'
        ],
        [
            'phone' => '+375291000004', 'name' => 'Софья Михайловна Гродненская',
            'address' => 'г. Гродно, ул. Кирова, 5', 'lat' => 53.6667, 'lon' => 23.8167,
            'region' => 'GRODNO', 'category' => 6,
            'title' => 'Поговорить и помочь по дому в Гродно',
            'description' => 'Хочется общения и помощи с настройкой телевизора.'
        ],
        [
            'phone' => '+375291000005', 'name' => 'Николай Фёдорович Могилёвский',
            'address' => 'г. Могилёв, ул. Первомайская, 15', 'lat' => 53.9006, 'lon' => 30.3319,
            'region' => 'MOGILEV', 'category' => 5,
            'title' => 'Сопровождение в поликлинику, Могилёв',
            'description' => 'Нужно сопроводить к врачу во вторник утром.'
        ],
        [
            'phone' => '+375291000006', 'name' => 'Вера Павловна Борисовская',
            'address' => 'г. Борисов, ул. Чапаева, 8', 'lat' => 54.2279, 'lon' => 28.5050,
            'region' => 'MINSK_OBLAST', 'category' => 4,
            'title' => 'Помочь с оплатой ЖКХ в Борисове',
            'description' => 'Помогите разобраться с квитанцией и оплатить через ЕРИП.'
        ],
    ];

    foreach ($regionalDemo as $demo) {
        if (User::findByPhone($demo['phone'])) {
            continue;
        }
        $regionalUid = User::create([
            'role_id' => 1,
            'phone' => $demo['phone'],
            'name' => $demo['name'],
            'password_hash' => $password
        ]);
        ElderlyProfile::create([
            'user_id' => $regionalUid,
            'address' => $demo['address'],
            'latitude' => $demo['lat'],
            'longitude' => $demo['lon'],
            'region_code' => $demo['region'],
            'birth_year' => 1945,
            'mobility_notes' => 'Требуется помощь по дому',
            'emergency_contact' => 'Родственники'
        ]);
        HelpRequest::create([
            'elderly_id' => $regionalUid,
            'category_id' => $demo['category'],
            'title' => $demo['title'],
            'description' => $demo['description'],
            'latitude' => $demo['lat'],
            'longitude' => $demo['lon'],
            'region_code' => $demo['region'],
            'address' => $demo['address'],
            'secret_code' => SecurityService::generateSecretCode(),
            'status' => 'open'
        ]);
    }
}

$materials = require __DIR__ . '/materials_catalog.php';

foreach ($materials as $m) {
    if (!EducationalMaterial::where('title', $m['title'])) {
        EducationalMaterial::create($m);
    }
}

if ($seedDemoUsers && empty(FraudAlert::all())) {
    FraudAlert::create([
        'title' => 'Внимание: активизировались лже-сантехники!',
        'description' => 'В регионах Беларуси неизвестные предлагают платную проверку вентиляции и фильтров по завышенным ценам.',
        'severity' => 'danger',
        'is_active' => 1
    ]);
}

echo "[SEED] Success!\n";
