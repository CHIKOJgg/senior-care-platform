<?php
declare(strict_types=1);


$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';
$requestUri = rtrim($requestUri, '/');
if ($requestUri === '') {
    $requestUri = '/';
}


header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Vary: Cookie');
if (str_starts_with($requestUri, '/api/')) {
    require __DIR__ . '/api.php';
    exit;
}



spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$sessionUser = null;
$authToken = $_COOKIE['auth_token'] ?? '';
$hasAuthCookie = is_string($authToken) && $authToken !== '';
if ($hasAuthCookie) {
    try {

        $sessionUser = \App\Services\AuthService::getCurrentUser();
    } catch (\Throwable $e) {
        $sessionUser = null;
    }
}



if ($hasAuthCookie && $sessionUser === null) {
    setcookie('auth_token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']),
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE['auth_token']);
    $hasAuthCookie = false;
}



$isGuestElderly = ($sessionUser === null && $requestUri === '/');
$roleCode = (string)($sessionUser['role_code'] ?? '');
$viewRole = match ($roleCode) {
    'elderly' => 'user',
    'volunteer' => 'volunteer',
    'admin', 'coordinator' => 'admin',
    default => $isGuestElderly ? 'user' : null,
};
$isAuthed = $viewRole !== null && !$isGuestElderly;
$isGuest = $isGuestElderly;
$homeByRole = [
    'elderly' => '/',
    'volunteer' => '/volunteer',
    'admin' => '/admin',
    'coordinator' => '/admin',
];
$homeUrl = $homeByRole[$roleCode] ?? '/login';
$regionsList = \App\Services\RegionService::all();
$profileRegion = (string)($sessionUser['profile']['region_code'] ?? 'MINSK_CITY');
if (!\App\Services\RegionService::exists($profileRegion)) {
    $profileRegion = 'MINSK_CITY';
}



if ($isAuthed) {
    $guardedPaths = ['/', '/admin', '/volunteer'];
    if (in_array($requestUri, $guardedPaths, true) && $requestUri !== $homeUrl) {
        header('Location: ' . $homeUrl);
        exit;
    }
} elseif ($requestUri === '/admin' || $requestUri === '/volunteer') {
    header('Location: /login');
    exit;
}




if ($requestUri === '/login' || (!$isAuthed && !$isGuest)) {
    $loginNotice = isset($_GET['expired'])
        ? 'Сессия истекла. Войдите снова.'
        : 'Вход для сотрудников ТЦСОН и волонтёров.';
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Вход — ЗаботаРядом</title>
    <link rel="stylesheet" href="/css/accessibility.css?v=<?php echo @filemtime(__DIR__ . '/css/accessibility.css') ?: time(); ?>">
</head>
<body data-initial-role="guest" data-mode="app" class="login-body">
<div class="login-shell">
    <header class="login-header">
        <a href="/" class="brand" aria-label="ЗаботаРядом — на главную">
            <svg class="ui-icon ui-icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>
            </svg>
            ЗаботаРядом
        </a>
        <div class="a11y-controls">
            <span id="a11yModeBadge" class="badge badge-verified" style="display: none;">Режим доступности</span>
            <button id="speakPageBtn" class="btn btn-outline" title="Озвучить страницу">
                <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>
                Озвучить
            </button>
            <button id="toggleNarrationBtn" class="btn btn-outline" aria-pressed="true" title="Включить или выключить озвучку">
                <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
                <span id="toggleNarrationLabel">Озвучка: вкл</span>
            </button>
        </div>
    </header>

    <main class="login-main">
        <section class="login-card" aria-labelledby="loginTitle">
            <h1 id="loginTitle">Вход в систему</h1>
            <p class="login-sub">Для координаторов, администраторов и волонтёров</p>
            <p id="gateMessage" class="login-notice"><?php echo htmlspecialchars($loginNotice); ?></p>

            <form id="sessionGateForm" autocomplete="on" novalidate>
                <div class="form-group">
                    <label class="form-label" for="gatePhone">Номер телефона</label>
                    <input type="tel" id="gatePhone" name="phone" class="form-control" placeholder="+375 29 123-45-67" inputmode="tel" autocomplete="username" autofocus required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="gatePassword">Пароль</label>
                    <div class="password-wrap">
                        <input type="password" id="gatePassword" name="password" class="form-control" autocomplete="current-password" required>
                        <button type="button" class="password-toggle" id="togglePassword" aria-label="Показать пароль">Показать</button>
                    </div>
                </div>
                <div id="gateError" class="alert-banner alert-banner-danger" style="display: none; margin-bottom: 14px;" role="alert"></div>
                <button type="submit" class="btn btn-primary" id="loginSubmitBtn" style="width: 100%; min-height: 56px; font-size: 1.1em;">Войти</button>
            </form>

            <button type="button" id="showResetFormBtn" class="btn btn-outline" style="width: 100%; margin-top: 12px;">Забыли пароль?</button>

            <div id="resetPasswordBox" style="display: none; margin-top: 18px; border-top: 1px solid var(--border-color); padding-top: 16px;">
                <p style="font-size: 0.92em; color: var(--text-muted); margin-bottom: 12px;">
                    Позвоните координатору ТЦСОН и попросите одноразовый код. Код действует 30 минут.
                </p>
                <form id="resetPasswordForm" novalidate>
                    <div class="form-group">
                        <label class="form-label" for="resetPhone">Номер телефона</label>
                        <input type="tel" id="resetPhone" class="form-control" placeholder="+375 29 123-45-67" inputmode="tel" autocomplete="username" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="resetCode">Код подтверждения</label>
                        <input type="text" id="resetCode" class="form-control" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" placeholder="6 цифр" autocomplete="one-time-code" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="resetNewPassword">Новый пароль (не менее 8 символов)</label>
                        <input type="password" id="resetNewPassword" class="form-control" minlength="8" autocomplete="new-password" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="resetNewPasswordRepeat">Повторите новый пароль</label>
                        <input type="password" id="resetNewPasswordRepeat" class="form-control" minlength="8" autocomplete="new-password" required>
                    </div>
                    <div id="resetPasswordMessage" class="alert-banner" style="display: none; margin-bottom: 14px;" role="alert"></div>
                    <button type="submit" class="btn btn-primary" style="width: 100%; min-height: 52px;">Сохранить новый пароль</button>
                    <button type="button" id="cancelResetBtn" class="btn btn-outline" style="width: 100%; margin-top: 10px;">Вернуться ко входу</button>
                </form>
            </div>

            <div class="login-note">
                Вы подопечный? <a href="/">Откройте главную страницу</a> — вход не требуется.
            </div>
        </section>
    </main>

    <footer class="login-footer">
        Платформа взаимной помощи «ЗаботаРядом» • ТЦСОН Республики Беларусь
    </footer>
</div>
<div id="a11yLiveRegion" class="sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
<script>window.APP_CONFIG = <?php echo json_encode(['mode' => 'app', 'role' => 'guest', 'demoAutologin' => false], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;</script>
<script src="/js/speech.js?v=<?php echo @filemtime(__DIR__ . '/js/speech.js') ?: time(); ?>"></script>
<script src="/js/app.js?v=<?php echo @filemtime(__DIR__ . '/js/app.js') ?: time(); ?>"></script>
</body>
</html>
    <?php
    exit;
}

$mode = 'app';
$roleNames = [
    'elderly' => 'Подопечный',
    'volunteer' => 'Волонтёр',
    'coordinator' => 'Координатор',
    'admin' => 'Администратор',
];
$sessionName = $isAuthed ? (string)($sessionUser['name'] ?? '') : '';
$sessionRoleName = $isAuthed ? ($roleNames[$roleCode] ?? $roleCode) : '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Платформа взаимной помощи «ЗаботаРядом»</title>
    <link rel="stylesheet" href="/css/accessibility.css?v=<?php echo @filemtime(__DIR__ . '/css/accessibility.css') ?: time(); ?>">
</head>
<body data-initial-role="<?php echo htmlspecialchars($viewRole ?? 'guest'); ?>" data-mode="<?php echo $mode; ?>" data-is-guest="<?php echo !empty($isGuest) ? '1' : '0'; ?>">


<div class="quick-auth-bar">
    <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
        <span id="currentUserDisplay" style="font-weight: 700; color: var(--primary-color); display: inline-flex; align-items: center; gap: 6px;">
            <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span><?php
                if ($isAuthed) {
                    echo htmlspecialchars($sessionName) . ' (' . htmlspecialchars($sessionRoleName) . ')';
                } elseif (!empty($isGuest)) {
                    echo 'Гость';
                } else {
                    echo 'Вход в систему';
                }
            ?></span>
        </span>
        <?php if ($isAuthed): ?>
        <button type="button" id="btnChangePassword" class="btn btn-outline btn-xs" style="height: 30px;">Изменить пароль</button>
        <button type="button" id="btnLogout" class="btn btn-outline btn-xs" style="height: 30px;">Выйти</button>
        <?php elseif (!empty($isGuest)): ?>
        <a href="/login" class="btn btn-primary btn-xs" style="height: 30px; display:inline-flex; align-items:center;">Войти</a>
        <?php endif; ?>
        <div class="live-indicator">
            <span class="pulse-dot"></span>
            <span>Система онлайн</span>
        </div>
    </div>
</div>
<header>
    <div class="container header-inner">
        <a href="<?php echo $isAuthed ? htmlspecialchars($homeUrl) : '/'; ?>" class="brand">
            <svg class="ui-icon ui-icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>
            </svg>
            ЗаботаРядом
        </a>

        <div class="a11y-controls">
            <span id="a11yModeBadge" class="badge badge-verified" style="display: none;">Режим доступности</span>
            <button id="speakPageBtn" class="btn btn-outline" title="Озвучить страницу">
                <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>
                Озвучить
            </button>
            <button id="toggleNarrationBtn" class="btn btn-outline" aria-pressed="true" title="Включить или выключить озвучку">
                <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
                <span id="toggleNarrationLabel">Озвучка: вкл</span>
            </button>
            <button id="toggleSeniorMode" class="btn btn-outline" title="Переключить крупный режим">
                <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                Крупный шрифт
            </button>
        </div>
    </div>
</header>

<main class="container">

    <div id="urgentAlertBox" class="alert-banner alert-banner-danger" style="display: none;">
        <div>
            <h3 id="alertTitle" style="display: flex; align-items: center; gap: 8px;">
                <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <span>Внимание!</span>
            </h3>
            <p id="alertDesc" style="margin-top: 6px;"></p>
        </div>
        <button class="btn btn-outline btn-sm" onclick="SpeechHelper.speak(document.getElementById('alertTitle').innerText + '. ' + document.getElementById('alertDesc').innerText)">
            <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
            Прослушать
        </button>
    </div>




    <?php if ($viewRole === 'user'): ?>
    <div id="view-user" class="role-view" style="display: block;">

        <div id="activeSosBanner" class="alert-banner alert-banner-danger sos-pulse-banner" style="display: none; margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 12px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 44px; height: 44px; border-radius: 50%; background: #ffffff; color: var(--danger-color); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                        <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                    </div>
                    <div>
                        <div style="font-weight: 800; font-size: 1.15em; color: #ffffff;">СИГНАЛ SOS В ОБРАБОТКЕ (ПРИОРИТЕТ 0)</div>
                        <div style="font-size: 0.95em; color: #ffffff; opacity: 0.95; margin-top: 2px;">
                            Талон МЧС: <span id="sosTicketId" class="badge" style="background: #ffffff; color: #991b1b; font-weight: 800;">EMERCOM-112-BY-2026-XXXX</span>
                            | Дежурная служба 112 и координатор ТЦСОН оповещены
                        </div>
                    </div>
                </div>
                <button type="button" class="btn btn-outline btn-sm" id="cancelSosBtn" style="background: rgba(255,255,255,0.2); color: #fff; border-color: #fff;" onclick="document.getElementById('activeSosBanner').style.display='none';">
                    Скрыть оповещение
                </button>
            </div>
        </div>

        <?php if (empty($isGuest)): ?>

        <div class="card card-accent-danger" style="background: #fef2f2; border: 3px solid var(--danger-color); padding: 16px 20px; margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
                <div style="display: flex; align-items: center; gap: 14px;">
                    <div style="width: 52px; height: 52px; border-radius: 50%; background: #fee2e2; color: var(--danger-color); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                        <svg class="ui-icon ui-icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                    </div>
                    <div>
                        <h2 style="font-size: 1.35em; color: var(--danger-color); margin-bottom: 4px;">Экстренная помощь подопечному?</h2>
                        <p style="font-size: 0.95em; color: #7f1d1d; margin: 0;">Если вам стало плохо или угрожает опасность — нажмите тревожную кнопку. Вызов отправит координаты и данные в службу 112.</p>
                    </div>
                </div>
                <button type="button" id="sosBtn" class="btn btn-danger btn-sos sos-btn" aria-label="Экстренная помощь SOS — вызов координат в МЧС 112" style="min-height: 60px; min-width: 200px; font-size: 1.3em; font-weight: 900; letter-spacing: 1px; box-shadow: 0 4px 14px rgba(220, 38, 38, 0.4);" onclick="triggerSos()">
                    <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                    КНОПКА SOS
                </button>
            </div>
        </div>
        <?php endif; ?>

        <div class="user-functions-nav">
            <button class="function-card-btn active" data-user-func="func-request" id="btnFuncRequest">
                <span class="function-icon">
                    <svg class="ui-icon ui-icon-xl" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                </span>
                <div>
                    <div class="function-title">1. Мне нужна помощь</div>
                    <div class="function-sub">Заказать продукты, лекарства, помощь по дому</div>
                </div>
            </button>
            <button class="function-card-btn" data-user-func="func-safety" id="btnFuncSafety">
                <span class="function-icon">
                    <svg class="ui-icon ui-icon-xl" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                </span>
                <div>
                    <div class="function-title">2. Проверка на мошенничество</div>
                    <div class="function-sub">Проверить подозрительный звонок, СМС или сообщение</div>
                </div>
            </button>
            <button class="function-card-btn" data-user-func="func-education" id="btnFuncEducation">
                <span class="function-icon">
                    <svg class="ui-icon ui-icon-xl" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="M8 7h6"/><path d="M8 11h8"/></svg>
                </span>
                <div>
                    <div class="function-title">3. Уроки безопасности</div>
                    <div class="function-sub">Видео, памятки и простые правила безопасности</div>
                </div>
            </button>
        </div>

        <section id="func-request" class="user-func-pane">
            <?php if (!empty($isGuest)): ?>
            <div class="card" style="border-left: 4px solid var(--primary-color);">
                <h2 style="margin-bottom: 12px;">Войдите, чтобы создать заявку на помощь</h2>
                <p style="color: var(--text-muted); margin-bottom: 16px;">Как гость вы можете пользоваться проверкой на мошенничество и смотреть уроки безопасности. Чтобы создать заявку на помощь, войдите в систему или зарегистрируйтесь.</p>
                <div style="display:flex; gap:12px; flex-wrap:wrap;">
                    <a href="/login" class="btn btn-primary">Войти</a>
                    <a href="/login#register" class="btn btn-outline">Регистрация</a>
                </div>
            </div>
            <?php else: ?>
            <div class="card">
                <h2 style="margin-bottom: 16px;">Какая помощь вам требуется?</h2>

                <input type="hidden" id="selectedCategory" value="1">
                <div class="category-grid" id="categoryGrid">
                    <div class="category-card selected" data-id="1">
                        <span class="category-icon">
                            <svg class="ui-icon ui-icon-xl" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
                        </span>
                        <div class="category-title">Продукты</div>
                    </div>
                    <div class="category-card" data-id="2">
                        <span class="category-icon">
                            <svg class="ui-icon ui-icon-xl" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="m8.5 8.5 7 7"/></svg>
                        </span>
                        <div class="category-title">Лекарства</div>
                    </div>
                    <div class="category-card" data-id="3">
                        <span class="category-icon">
                            <svg class="ui-icon ui-icon-xl" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                        </span>
                        <div class="category-title">Быт и дом</div>
                    </div>
                    <div class="category-card" data-id="4">
                        <span class="category-icon">
                            <svg class="ui-icon ui-icon-xl" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg>
                        </span>
                        <div class="category-title">Телефон / ЖКХ</div>
                    </div>
                    <div class="category-card" data-id="5">
                        <span class="category-icon">
                            <svg class="ui-icon ui-icon-xl" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="13" cy="4" r="2"/><path d="M14.5 9a3 3 0 0 0-3 3v5l-3 4"/><path d="M11.5 14 7 11"/><path d="m15.5 14 3.5 3.5"/></svg>
                        </span>
                        <div class="category-title">Прогулка</div>
                    </div>
                    <div class="category-card" data-id="6">
                        <span class="category-icon">
                            <svg class="ui-icon ui-icon-xl" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        </span>
                        <div class="category-title">Беседа</div>
                    </div>
                </div>


                <div class="card card-accent-primary" style="background: #eff6ff;">
                    <div class="voice-bar">
                        <button type="button" id="micBtn" class="mic-btn" title="Нажмите и говорите">
                            <svg class="ui-icon ui-icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="22"/></svg>
                        </button>
                        <div>
                            <strong>Голосовой ввод заявки</strong>
                            <p style="font-size: 0.9em; color: #1e3a8a;">Нажмите на микрофон и скажите вслух, что вам нужно привезти или сделать.</p>
                        </div>
                    </div>
                </div>

                <form id="helpForm">
                    <div class="form-group">
                        <label class="form-label" for="requestDescription">Опишите вашу просьбу:</label>
                        <textarea id="requestDescription" class="form-control" placeholder="Например: купите, пожалуйста, молоко и хлеб..."></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="requestRegion">Регион Республики Беларусь:</label>
                        <select id="requestRegion" class="form-control">
                            <?php foreach ($regionsList as $rg): ?>
                                <option value="<?php echo htmlspecialchars($rg['code']); ?>" data-lat="<?php echo htmlspecialchars((string)$rg['center_lat']); ?>" data-lon="<?php echo htmlspecialchars((string)$rg['center_lon']); ?>" <?php echo $rg['code'] === $profileRegion ? 'selected' : ''; ?>><?php echo htmlspecialchars($rg['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="requestAddress">Ваш адрес проживания:</label>
                        <input type="text" id="requestAddress" class="form-control" placeholder="Город, улица, дом, квартира">
                    </div>
                    <div class="form-group consent-group" style="margin: 16px 0;">
                        <label class="checkbox-label" for="requestConsentCheckbox" style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer;">
                            <input type="checkbox" id="requestConsentCheckbox" name="consent" required style="width: 24px; height: 24px; min-width: 24px; margin-top: 2px;">
                            <span style="font-size: 0.9em; line-height: 1.4;">
                                Я даю согласие на обработку моих персональных данных (адрес проживания, номер телефона) уполномоченными сотрудниками ТЦСОН и верифицированными волонтёрами в соответствии с <strong>Законом Республики Беларусь № 99-З «О защите персональных данных»</strong>.
                            </span>
                        </label>
                    </div>
                    <button type="submit" class="btn btn-success" style="width: 100%; font-size: 1.2em;">
                        <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Отправить заявку волонтёрам
                    </button>
                </form>


                <div id="secretCodeBanner" class="secret-code-banner" style="display: none;">
                    <h3 style="display: flex; align-items: center; justify-content: center; gap: 8px;">
                        <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        <span>Заявка отправлена! Ваша безопасность:</span>
                    </h3>
                    <p>Когда к вам придёт волонтёр, он ОБЯЗАН назвать кодовое слово:</p>
                    <span id="displaySecretWord" class="secret-word">Василёк</span>
                    <p style="color: var(--danger-color); font-weight: bold; display: flex; align-items: center; justify-content: center; gap: 6px;">
                        <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        Не открывайте дверь, если человек не знает это слово!
                    </p>
                </div>
            </div>


            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <h3 style="display: flex; align-items: center; gap: 8px;">
                        <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>
                        <span>Мои активные заявки и кодовые слова</span>
                    </h3>
                    <button class="btn btn-outline btn-sm" onclick="loadElderlyRequests()">
                        <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.19"/></svg>
                        Обновить
                    </button>
                </div>
                <p style="color: var(--text-muted); margin-bottom: 16px;">Здесь отображаются ваши текущие просьбы и проверочные слова к ним:</p>
                <div id="elderlyRequestsList">
                    <p>Загрузка ваших заявок...</p>
                </div>
            </div>

            <?php endif; ?>
        </section>


        <section id="func-safety" class="user-func-pane" style="display: none;">
            <div class="card card-accent-danger">
                <h2 style="display: flex; align-items: center; gap: 8px;">
                    <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span>Проверка подозрительного звонка или СМС</span>
                </h2>
                <p style="margin: 8px 0 16px 0;">Вам позвонили из банка, милиции или водоканала? Вставьте текст или перескажите фразу сюда для мгновенной проверки:</p>

                <div class="voice-bar">
                    <button type="button" id="micFraudBtn" class="mic-btn" style="width: 50px; height: 50px;" title="Надиктовать голосом">
                        <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="22"/></svg>
                    </button>
                    <span style="font-size: 0.9em; color: var(--text-muted);">Нажмите микрофон, чтобы надиктовать фразу</span>
                </div>

                <div class="form-group">
                    <label class="form-label" for="fraudCheckInput">Текст подозрительного звонка или СМС:</label>
                    <textarea id="fraudCheckInput" class="form-control" placeholder="Например: Позвонили в мессенджер, сказали что на меня берут кредит и просят код из смс..."></textarea>
                </div>

                <div style="margin-bottom: 16px;">
                    <span style="font-size: 0.85em; color: var(--text-muted); font-weight: 600; display: block; margin-bottom: 8px;">Быстрая проверка частых ситуаций (нажмите для теста):</span>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;" id="fraudSampleButtons">
                        <button type="button" class="btn btn-outline btn-xs fraud-sample-btn" data-text="Звонит сотрудник службы безопасности банка, просит срочно продиктовать секретный код из СМС">Код из СМС от «банка»</button>
                        <button type="button" class="btn btn-outline btn-xs fraud-sample-btn" data-text="Звонят из Энергосбыта, говорят срочно заменить счетчик за деньги и назвать данные">Энергосбыт / замена счётчика</button>
                        <button type="button" class="btn btn-outline btn-xs fraud-sample-btn" data-text="Звонит следователь, пугает уголовным делом и требует перевести деньги на безопасный счет">Следователь / «безопасный счёт»</button>
                        <button type="button" class="btn btn-outline btn-xs fraud-sample-btn" data-text="Соседка попросила одолжить соль до вечера">Бытовая просьба (Безопасно)</button>
                    </div>
                </div>
                <button id="runFraudCheckBtn" class="btn btn-danger" style="font-size: 1.1em;">
                    <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    Проверить на мошенничество
                </button>

                <div id="fraudResultBox" style="display: none; margin-top: 16px; padding: 16px; border-radius: 12px; border: 2px solid;"></div>
            </div>

                    </section>


        <section id="func-education" class="user-func-pane" style="display: none;">
            <div class="card">
                <h2 style="display: flex; align-items: center; gap: 8px;">
                    <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="M6 6h10"/><path d="M6 10h10"/></svg>
                    <span>Уроки безопасности</span>
                </h2>
                <p style="margin-bottom: 16px;">Смотрите короткие видео и читайте простые памятки, чтобы защищать себя от мошенников и опасных ситуаций.</p>

                <div id="educationVideoBlock" style="margin-bottom: 20px;">
                    <h3 style="margin-bottom: 12px; display:flex; align-items:center; gap:8px;">
                        <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                        <span>Видео-уроки</span>
                    </h3>
                    <div id="educationVideoList" class="education-video-list">
                        <p class="text-muted">Видео пока не добавлены.</p>
                    </div>
                </div>

                <hr style="margin: 20px 0; border: none; border-top: 1px solid var(--border-color);">

                <h3 style="margin-bottom: 12px;">Памятки и уроки безопасности</h3>
                <div style="margin-bottom: 14px; display: flex; gap: 8px;">
                    <input type="text" id="educationSearchInput" class="form-control" placeholder="Поиск по памяткам (например: мошенники, аптека, банк, доставка, лекарства)..." oninput="handleEducationSearch(this.value)">
                    <button type="button" class="btn btn-outline btn-sm" onclick="clearEducationSearch()" title="Сбросить поиск">
                        Сброс
                    </button>
                </div>
                <div id="educationCategoryTabs" style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;"></div>
                <div id="educationList"></div>
            </div>
        </section>

        <div class="card" style="margin-top: 20px; border-top: 2px solid var(--border-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                <div>
                    <h3 style="display: flex; align-items: center; gap: 8px; font-size: 1.15em;">
                        <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        </svg>
                        <span>Защита персональных данных (Закон РБ № 99-З)</span>
                    </h3>
                    <p style="color: var(--text-muted); font-size: 0.9em; margin: 4px 0 0 0;">
                        Вы можете в любой момент отозвать электронное согласие и безвозвратно обезличить профиль (Право на забвение).
                    </p>
                </div>
                <button type="button" id="btnAnonymizeProfile" class="btn btn-outline btn-sm" style="color: var(--danger-color); border-color: var(--danger-color); min-height: 60px; min-width: 60px;" onclick="requestRightToBeForgotten()">
                    <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
                    </svg>
                    Отозвать согласие и удалить данные
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($viewRole === 'admin'): ?>



    <div id="view-admin" class="role-view" style="display: block;">

        <div id="coordinatorSosBannerContainer" style="display: none;"></div>

        <div class="card" style="border-top: 6px solid var(--text-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
                <div>
                    <h2 style="display: flex; align-items: center; gap: 8px;">
                        <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M8 10h.01"/><path d="M16 10h.01"/><path d="M8 14h.01"/><path d="M16 14h.01"/></svg>
                        <span>Панель администратора ТЦСОН</span>
                    </h2>
                    <p style="color: var(--text-muted);">Управление заявками по всей Беларуси, верификация волонтёров и аналитика социальной обстановки</p>
                </div>
                <span class="admin-badge">РЕСПУБЛИКА БЕЛАРУСЬ</span>
            </div>


            <div class="admin-subnav" role="tablist">
                <button class="admin-subnav-btn active" data-admin-tab="admin-analytics" id="btnAdminAnalytics">
                    <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    Аналитика и отчёты
                </button>
                <button class="admin-subnav-btn" data-admin-tab="admin-volunteers" id="btnAdminVolunteers">
                    <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/></svg>
                    Верификация волонтёров <span id="pendingCountBadge" class="badge badge-warning" style="margin-left: 4px;">0</span>
                </button>
                <button class="admin-subnav-btn" data-admin-tab="admin-requests" id="btnAdminRequests">
                    <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>
                    Управление заявками
                </button>
                <button class="admin-subnav-btn" data-admin-tab="admin-alerts" id="btnAdminAlerts">
                    <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>
                    Экстренные оповещения
                </button>
                <button class="admin-subnav-btn" data-admin-tab="admin-watchdog" id="btnAdminWatchdog">
                    <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Контроль сроков (Watchdog) <span id="overdueTasksCountBadge" class="badge badge-cancelled" style="margin-left: 4px; display: none;">0</span>
                </button>
                <button class="admin-subnav-btn" data-admin-tab="admin-access" id="btnAdminAccess">
                    <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Доступ и пароли
                </button>
                <button class="admin-subnav-btn" data-admin-tab="admin-content" id="btnAdminContent">
                    <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                    Виды заявок и обучение
                </button>
            </div>


            <div id="admin-analytics" class="admin-tab-pane">
                <div class="stat-grid" id="analyticsKpiGrid">
                    <div class="stat-card">
                        <div class="stat-num" id="statTotalRequests">-</div>
                        <div>Всего заявок</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-num" id="statActiveRequests" style="color: var(--warning-color);">-</div>
                        <div>Требуют помощи сейчас</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-num" id="statCompletedRequests" style="color: var(--accent-color);">-</div>
                        <div>Успешно выполнены</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-num" id="statVerifiedVolunteers" style="color: var(--primary-color);">-</div>
                        <div>Верифицированных волонтёров</div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-top: 20px;">

                    <div class="card" style="margin-bottom: 0;">
                        <h3>Распределение потребностей по категориям</h3>
                        <div id="categoryAnalyticsBars" style="margin-top: 16px;">
                            <p>Загрузка статистики...</p>
                        </div>
                    </div>


                    <div class="card" style="margin-bottom: 0;">
                        <h3 style="display: flex; align-items: center; gap: 8px;">
                            <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
                            <span>Лидеры волонтёрского движения Беларуси</span>
                        </h3>
                        <p style="color: var(--text-muted); font-size: 0.9em; margin-bottom: 12px;">Рейтинг формируется на основе подтверждённых визитов и отзывов:</p>
                        <div id="adminLeaderboardList"></div>
                    </div>
                </div>
            </div>


            <div id="admin-volunteers" class="admin-tab-pane" style="display: none;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <h3>Верификация и проверка волонтёров</h3>
                        <p style="color: var(--text-muted); font-size: 0.9em;">Только верифицированные волонтёры допускаются к контакту с пожилыми гражданами.</p>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn btn-outline btn-sm" onclick="loadAllVolunteers('all')">Все</button>
                        <button class="btn btn-outline btn-sm" onclick="loadAllVolunteers('pending')">Ожидают проверки</button>
                        <button class="btn btn-outline btn-sm" onclick="loadAllVolunteers('verified')">Верифицированные</button>
                    </div>
                </div>


                <div class="card" style="margin-bottom: 16px; padding: 14px 18px;">
                    <div style="display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap;">
                        <div class="form-group" style="margin-bottom: 0; min-width: 220px;">
                            <label class="form-label" for="volunteerMinRating">Фильтр по рейтингу (не ниже):</label>
                            <input type="number" id="volunteerMinRating" class="form-control" min="0" max="5" step="0.1" placeholder="например, 4.5">
                        </div>
                        <button type="button" class="btn btn-primary btn-sm" style="min-height: 44px;" onclick="applyRatingFilter()">Применить</button>
                        <button type="button" class="btn btn-outline btn-sm" style="min-height: 44px;" onclick="resetRatingFilter()">Сбросить</button>
                        <span id="ratingFilterHint" style="color: var(--text-muted); font-size: 0.9em;"></span>
                    </div>
                </div>

                <div class="data-table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>ФИО волонтёра</th>
                                <th>Телефон</th>
                                <th>Навыки</th>
                                <th>Рейтинг / Задания</th>
                                <th>Статус верификации</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody id="volunteersTableBody">
                            <tr><td colspan="7" style="text-align: center;">Загрузка волонтёров...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>


            <div id="admin-requests" class="admin-tab-pane" style="display: none;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <h3>Реестр заявок Республики Беларусь</h3>
                        <p style="color: var(--text-muted); font-size: 0.9em;">Полный контроль исполнения обращений граждан</p>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn btn-outline btn-sm" onclick="loadAdminRequests('all')">Все</button>
                        <button class="btn btn-outline btn-sm" onclick="loadAdminRequests('open')">Открытые</button>
                        <button class="btn btn-outline btn-sm" onclick="loadAdminRequests('in_progress')">В работе</button>
                        <button class="btn btn-outline btn-sm" onclick="loadAdminRequests('completed')">Выполненные</button>
                        <select id="adminRegionFilter" class="form-control" style="max-width: 240px; min-height: 38px;" onchange="loadAdminRequests('all', this.value)">
                            <option value="">Вся Беларусь</option>
                            <?php foreach ($regionsList as $rg): ?>
                                <option value="<?php echo htmlspecialchars($rg['code']); ?>"><?php echo htmlspecialchars($rg['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="data-table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>№</th>
                                <th>Категория</th>
                                <th>Подопечный / Адрес</th>
                                <th>Описание</th>
                                <th>Кодовое слово</th>
                                <th>Волонтёр</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody id="adminRequestsTableBody">
                            <tr><td colspan="8" style="text-align: center;">Загрузка заявок...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>


            <div id="admin-alerts" class="admin-tab-pane" style="display: none;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">
                    <div>
                        <h3 style="display: flex; align-items: center; gap: 8px;">
                            <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>
                            <span>Опубликовать экстренное оповещение</span>
                        </h3>
                        <p style="color: var(--text-muted); font-size: 0.9em; margin-bottom: 16px;">Предупреждение мгновенно появится на главной странице подопечных.</p>

                        <form id="alertForm">
                            <div class="form-group">
                                <label class="form-label" for="alertInputTitle">Заголовок предупреждения:</label>
                                <input type="text" id="alertInputTitle" class="form-control" placeholder="Например: Внимание: лже-электрики в микрорайоне" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="alertInputDesc">Подробное описание и совет гражданам:</label>
                                <textarea id="alertInputDesc" class="form-control" placeholder="Не пускайте посторонних без предъявления служебного удостоверения..." required></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Уровень угрозы:</label>
                                <select id="alertInputSeverity" class="form-control">
                                    <option value="danger">Высокая опасность (Красный)</option>
                                    <option value="warning" selected>Предупреждение (Жёлтый)</option>
                                    <option value="info">Информация (Синий)</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary" style="width: 100%;">
                                <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                Опубликовать предупреждение
                            </button>
                        </form>
                    </div>

                    <div>
                        <h3>Активные предупреждения по всей стране</h3>
                        <p style="color: var(--text-muted); font-size: 0.9em; margin-bottom: 16px;">Управление действующими оповещениями:</p>
                        <div id="activeAlertsAdminList">
                            <p>Загрузка оповещений...</p>
                        </div>
                    </div>
                </div>
            </div>


            <div id="admin-watchdog" class="admin-tab-pane" style="display: none;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <h3>Мониторинг зависших заявок (Watchdog)</h3>
                        <p style="color: var(--text-muted); font-size: 0.9em;">
                            Автоматический контроль нормативов времени: Лекарства (60 мин), Продукты (120 мин), Быт (180 мин).
                        </p>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn btn-primary btn-sm" onclick="runWatchdogScan()">
                            <svg class="ui-icon ui-icon-xs" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.19"/>
                            </svg>
                            Запустить сканирование
                        </button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="loadWatchdogTasks()">
                            <svg class="ui-icon ui-icon-xs" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                            </svg>
                            Обновить
                        </button>
                    </div>
                </div>
                <div class="data-table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>№</th>
                                <th>Категория</th>
                                <th>Подопечный / Адрес</th>
                                <th>Волонтёр</th>
                                <th>В работе (мин)</th>
                                <th>Статус</th>
                                <th>Действие</th>
                            </tr>
                        </thead>
                        <tbody id="watchdogTableBody">
                            <tr><td colspan="7" style="text-align: center;">Загрузка просроченных заявок...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>


            <div id="admin-access" class="admin-tab-pane" style="display: none;">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">
                    <div class="card" style="margin-bottom:0;">
                        <h3 style="display: flex; align-items: center; gap: 8px;">
                            <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <span>Восстановление доступа подопечного</span>
                        </h3>
                        <p style="color: var(--text-muted); font-size: 0.95em; margin-bottom: 16px;">
                            Введите телефон подопечного или волонтёра. Код действует 30 минут, использовать можно один раз на окне смены пароля.
                        </p>
                        <form id="resetCodeForm">
                            <div class="form-group">
                                <label class="form-label" for="resetCodePhone">Телефон пользователя:</label>
                                <input type="tel" id="resetCodePhone" class="form-control" placeholder="+375291112233" required>
                            </div>
                            <button type="submit" class="btn btn-primary" style="width: 100%; min-height: 52px;">Сгенерировать одноразовый код</button>
                        </form>
                        <div id="resetCodeError" class="alert-banner alert-banner-danger" style="display: none; margin-top: 16px;"></div>
                        <div id="resetCodeResult" class="card card-accent-success" style="display: none; margin-top: 16px;">
                            <p style="font-size: 0.95em;">Передайте код сотруднику/пользователю. Не отправляйте его в открытых каналах.</p>
                            <div id="resetCodeValue" style="font-size: 2em; font-weight: 900; letter-spacing: 6px; text-align: center; margin: 12px 0; color: var(--accent-color);"></div>
                            <p id="resetCodeUser" style="text-align: center; color: var(--text-muted);"></p>
                            <p id="resetCodeTtl" style="text-align: center; color: var(--text-muted); font-size: 0.9em;"></p>
                        </div>
                    </div>

                    <div class="card" style="margin-bottom:0;">
                        <h3 style="display: flex; align-items: center; gap: 8px;">
                            <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                            <span>Добавить пользователя</span>
                        </h3>
                        <p style="color: var(--text-muted); font-size: 0.95em; margin-bottom: 16px;">
                            Администратор может зарегистрировать нового подопечного или волонтёра.
                        </p>
                        <form id="adminCreateUserForm">
                            <div class="form-group">
                                <label class="form-label" for="newUserRole">Роль</label>
                                <select id="newUserRole" class="form-control">
                                    <option value="1">Подопечный</option>
                                    <option value="2">Волонтёр</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="newUserName">Имя</label>
                                <input type="text" id="newUserName" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="newUserPhone">Телефон</label>
                                <input type="tel" id="newUserPhone" class="form-control" placeholder="+375..." required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="newUserPassword">Пароль (мин. 8 символов)</label>
                                <input type="password" id="newUserPassword" class="form-control" minlength="8" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="newUserRegion">Регион</label>
                                <select id="newUserRegion" class="form-control">
                                    <?php foreach ($regionsList as $rg): ?>
                                        <option value="<?php echo htmlspecialchars($rg['code']); ?>" <?php echo $rg['code'] === 'MINSK_CITY' ? 'selected' : ''; ?>><?php echo htmlspecialchars($rg['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="newUserAddress">Адрес (необязательно)</label>
                                <input type="text" id="newUserAddress" class="form-control" placeholder="Адрес">
                            </div>
                            <button type="submit" class="btn btn-primary" style="width:100%; min-height:52px;">Создать пользователя</button>
                        </form>
                        <div id="adminCreateUserMessage" class="alert-banner" style="display:none; margin-top:16px;"></div>
                    </div>
                </div>
            </div>

            <div id="admin-content" class="admin-tab-pane" style="display: none;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 20px;">

                    <div class="card" style="margin-bottom: 0;">
                        <h3>Виды заявок (категории помощи)</h3>
                        <p style="color: var(--text-muted); font-size: 0.9em; margin-bottom: 14px;">
                            Добавляйте новые виды помощи и редактируйте существующие. Категорию, по которой уже есть заявки, удалить нельзя.
                        </p>
                        <form id="categoryForm" onsubmit="return submitCategoryForm(event)">
                            <input type="hidden" id="catId" value="">
                            <div class="form-group">
                                <label class="form-label" for="catCode">Код (латиницей, уникальный):</label>
                                <input type="text" id="catCode" class="form-control" placeholder="cleaning" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="catTitle">Название для подопечных:</label>
                                <input type="text" id="catTitle" class="form-control" placeholder="Помощь по дому" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="catIcon">Иконка (служебное имя):</label>
                                <input type="text" id="catIcon" class="form-control" placeholder="broom" value="help-circle">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="catDescription">Описание:</label>
                                <input type="text" id="catDescription" class="form-control" placeholder="Что входит в этот вид помощи">
                            </div>
                            <div style="display: flex; gap: 8px;">
                                <button type="submit" class="btn btn-primary" id="catSubmitBtn">Добавить вид заявки</button>
                                <button type="button" class="btn btn-outline" onclick="resetCategoryForm()">Очистить</button>
                            </div>
                        </form>
                        <div id="categoryFormMessage" class="alert-banner" style="display: none; margin-top: 14px;"></div>

                        <div class="data-table-container" style="margin-top: 18px;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Код</th>
                                        <th>Название</th>
                                        <th>Заявок</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody id="categoriesTableBody">
                                    <tr><td colspan="5" style="text-align: center;">Загрузка видов заявок...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>


                    <div class="card" style="margin-bottom: 0;">
                        <h3>Набор обучающих материалов</h3>
                        <p style="color: var(--text-muted); font-size: 0.9em; margin-bottom: 14px;">
                            Памятки по безопасности, здоровью и волонтёрству. Материалы также используются при реабилитации волонтёров с низким рейтингом.
                        </p>
                                                <form id="educationForm" onsubmit="return submitEducationForm(event)">
                            <div class="form-group">
                                <label class="form-label" for="eduTitle">Заголовок:</label>
                                <input type="text" id="eduTitle" class="form-control" placeholder="Например: Как распознать телефонных мошенников" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="eduType">Тип материала:</label>
                                <select id="eduType" class="form-control" onchange="toggleEducationMediaField()">
                                    <option value="memo">Памятка (текст)</option>
                                    <option value="video">Видео (YouTube ссылка)</option>
                                    <option value="audio">Аудио (ссылка)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="eduCategory">Категория:</label>
                                <input type="text" id="eduCategory" class="form-control" placeholder="Безопасность" value="Безопасность">
                            </div>
                            <div class="form-group" id="eduMediaUrlGroup" style="display:none;">
                                <label class="form-label" for="eduMediaUrl">Ссылка на видео/аудио:</label>
                                <input type="url" id="eduMediaUrl" class="form-control" placeholder="https://www.youtube.com/watch?v=...">
                                <p style="font-size: 0.85em; color: var(--text-muted); margin-top: 6px;">Для YouTube вставьте обычную ссылку на видео. Видео будет показано в разделе «Уроки безопасности» над текстовыми памятками.</p>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="eduContent">Текст памятки / описание видео:</label>
                                <textarea id="eduContent" class="form-control" rows="3" placeholder="Короткий текст памятки или описание видео" required></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">Добавить материал</button>
                        </form><div id="educationFormMessage" class="alert-banner" style="display: none; margin-top: 14px;"></div>

                        <div class="data-table-container" style="margin-top: 18px;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Заголовок</th>
                                        <th>Тип</th>
                                        <th>Категория</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody id="adminEducationTableBody">
                                    <tr><td colspan="5" style="text-align: center;">Загрузка материалов...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($viewRole === 'volunteer'): ?>



    <div id="view-volunteer" class="role-view" style="display: block;">

        <div id="volunteerVerificationNotice" class="card card-accent-success">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h3 id="volVerifyTitle" style="display: flex; align-items: center; gap: 8px;">
                        <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                        <span>Статус волонтёра: Верифицирован</span>
                    </h3>
                    <p id="volVerifyDesc" style="color: var(--accent-color); margin-top: 4px;">Ваша личность проверена ТЦСОН. Вы имеете полный доступ к помощи пожилым гражданам.</p>
                </div>
                <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 8px;">
                    <div style="font-size: 0.9em; color: var(--text-muted);">
                        Волонтёр: <strong id="volNameDisplay">Алексей Смирнов</strong>
                    </div>
                    <button type="button" id="btnOpenVolunteerBadge" class="btn btn-primary btn-sm" style="min-height: 44px; display: inline-flex; align-items: center; gap: 6px;" onclick="showVolunteerBadge()">
                        <svg class="ui-icon ui-icon-xs" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect width="18" height="18" x="3" y="3" rx="2"/><path d="M7 7h.01"/><path d="M17 7h.01"/><path d="M7 17h.01"/><path d="M17 17h.01"/>
                        </svg>
                        Предъявить цифровой бейдж ТЦСОН
                    </button>
                </div>
            </div>
        </div>


        <div class="card card-accent-warning" id="volunteerActiveTasksCard">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h2 style="display: flex; align-items: center; gap: 8px;">
                        <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span>Мои задания в работе</span>
                    </h2>
                    <p style="color: var(--text-muted);">Заявки, которые вы приняли. По завершении введите проверочное слово подопечного:</p>
                </div>
                <button class="btn btn-outline btn-sm" onclick="loadVolunteerActiveTasks()">
                    <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.19"/></svg>
                    Обновить задания
                </button>
            </div>
            <div id="volunteerActiveTasksList">
                <p>Загрузка принятых заданий...</p>
            </div>
        </div>

        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h2 style="display: flex; align-items: center; gap: 8px;">
                        <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                        <span>Лента открытых заявок поблизости</span>
                    </h2>
                    <p style="color: var(--text-muted);">ИИ автоматически рассчитал расстояние от вашей текущей геоточки:</p>
                </div>
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <select id="volunteerRegionFilter" class="form-control" style="max-width: 230px; min-height: 38px;" onchange="loadVolunteerRequests()">
                        <option value="">Все регионы</option>
                        <?php foreach ($regionsList as $rg): ?>
                            <option value="<?php echo htmlspecialchars($rg['code']); ?>"><?php echo htmlspecialchars($rg['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline btn-sm" onclick="loadVolunteerRequests()">
                        <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.19"/></svg>
                        Обновить ленту
                    </button>
                </div>
            </div>
            <div id="volunteerRequestList">
                <p>Загрузка доступных заявок...</p>
            </div>
        </div>

        <div class="card">
            <h2 style="display: flex; align-items: center; gap: 8px;">
                <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
                <span>Таблица лидеров волонтёров Беларуси</span>
            </h2>
            <p style="margin-bottom: 16px; color: var(--text-muted);">За каждую выполненную заявку начисляется +50 очков опыта и растёт социальный рейтинг:</p>
            <div id="leaderboardList"></div>
        </div>


        <div class="card" id="volunteerKnowledgeCard">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h2 style="display: flex; align-items: center; gap: 8px;">
                        <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="M6 6h10"/><path d="M6 10h10"/></svg>
                        <span>База знаний и регламент волонтёра</span>
                    </h2>
                    <p style="color: var(--text-muted);">Стандарты помощи, правила этики, Закон № 99-З и методические материалы для работы с подопечными:</p>
                </div>
                <button class="btn btn-outline btn-sm" onclick="loadVolunteerKnowledge()">
                    <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.19"/></svg>
                    Обновить
                </button>
            </div>
            <div id="volunteerKnowledgeCategoryTabs" style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px;"></div>
            <div id="volunteerKnowledgeList">
                <p>Загрузка стандартов...</p>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>


<div id="geo-complete-modal" class="modal-backdrop" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="geoModalTitle">
    <div class="modal-dialog" style="max-width: 480px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 id="geoModalTitle" style="display: flex; align-items: center; gap: 8px;">
                <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/>
                    <circle cx="12" cy="10" r="3"/>
                </svg>
                <span>Подтверждение визита (GPS + Код)</span>
            </h3>
            <button type="button" class="btn btn-outline btn-xs" id="closeGeoModalBtn" onclick="closeCompleteModal()" aria-label="Закрыть">&times;</button>
        </div>
        <p style="color: var(--text-muted); font-size: 0.9em; margin-bottom: 14px;">
            Назовите подопечному проверочное слово и зафиксируйте выполнение заявки на месте (радиус дома до 150 м).
        </p>
        <form id="geoCompleteForm" onsubmit="event.preventDefault(); submitGeoComplete();">
            <input type="hidden" id="geoCompleteRequestId" value="">

            <div class="form-group">
                <label class="form-label" for="geoSecretWordInput">Секретное проверочное слово подопечного:</label>
                <input type="text" id="geoSecretWordInput" class="form-control" placeholder="Например: Василёк" required autocomplete="off" autofocus>
            </div>

            <div class="form-group">
                <label class="form-label">Текущие GPS-координаты волонтёра:</label>
                <div id="geoCoordinatesDisplay" style="padding: 10px 14px; background: var(--card-subtle); border: 1px solid var(--border-color); border-radius: 8px; font-family: monospace; font-size: 0.95em;">
                    Определение геолокации...
                </div>
            </div>


            <div id="geoDistanceErrorBox" class="alert-banner alert-banner-danger" style="display: none; margin-bottom: 16px;">
                <div id="geoDistanceErrorText"></div>
            </div>

            <div style="display: flex; gap: 10px; margin-top: 16px;">
                <button type="submit" class="btn btn-success" id="confirmGeoCompleteBtn" style="flex: 1; min-height: 48px;">
                    <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    Подтвердить выполнение
                </button>
                <button type="button" class="btn btn-outline" id="cancelGeoCompleteBtn" style="flex: 1; min-height: 48px;" onclick="closeCompleteModal()">
                    Отмена
                </button>
            </div>
        </form>
    </div>
</div>


<div id="volunteer-badge-modal" class="modal-backdrop" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="badgeModalTitle">
    <div class="modal-dialog" style="max-width: 460px; text-align: center;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 id="badgeModalTitle" style="display: flex; align-items: center; gap: 8px;">
                <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    <path d="m9 12 2 2 4-4"/>
                </svg>
                <span>Цифровой бейдж волонтёра</span>
            </h3>
            <button type="button" class="btn btn-outline btn-xs" id="closeBadgeModalBtn" onclick="closeVolunteerBadgeModal()" aria-label="Закрыть">&times;</button>
        </div>

        <div id="badgeUnverifiedNotice" class="alert-banner alert-banner-warning" style="display: none; text-align: left; margin-bottom: 16px;">
            <p>Ваш профиль ещё не верифицирован координатором ТЦСОН. Цифровой бейдж активируется после проверки документов.</p>
        </div>

        <div id="badgeActiveContent">
            <div id="badgeQrCardContainer" style="text-align: center; margin-bottom: 16px;">
                <div style="padding: 20px; color: var(--text-muted);">Загрузка цифрового бейджа...</div>
            </div>


            <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: var(--card-subtle); border-radius: 8px; margin-bottom: 14px; border: 1px solid var(--border-color);">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <span style="font-size: 0.9em; color: var(--text-muted);">Действителен: <strong id="badgeCountdownTimer" style="color: var(--primary-color);">59:59</strong></span>
                </div>
                <button type="button" id="refreshBadgeBtn" class="btn btn-outline btn-xs" onclick="showVolunteerBadge()" title="Обновить динамический токен">
                    <svg class="ui-icon ui-icon-xs" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.19"/>
                    </svg>
                    Обновить QR
                </button>
            </div>
            <p style="font-size: 0.85em; color: var(--text-muted); text-align: center; margin-bottom: 12px;">
                Покажите этот QR-код подопечному или его родственникам для мгновенной проверки официальной аккредитации ТЦСОН.
            </p>
            <div style="display: flex; gap: 8px;">
                <a id="btnBadgePublicUrl" href="#" target="_blank" class="btn btn-primary btn-sm" style="flex: 1; text-decoration: none;">
                    Открыть статус
                </a>
                <button type="button" class="btn btn-outline btn-sm" style="flex: 1;" onclick="closeVolunteerBadgeModal()">
                    Закрыть
                </button>
            </div>
        </div>
    </div>
</div>

<?php if ($isAuthed): ?>

<div id="passwordModal" class="modal-backdrop" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="passwordModalTitle">
    <div class="modal-dialog" style="max-width: 480px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 id="passwordModalTitle">Безопасность аккаунта</h3>
            <button type="button" class="btn btn-outline btn-xs" id="closePasswordModalBtn" style="padding: 0 8px;" aria-label="Закрыть">&times;</button>
        </div>
        <form id="passwordForm">
            <div class="form-group">
                <label class="form-label" for="currentPassword">Текущий пароль:</label>
                <input type="password" id="currentPassword" class="form-control" required autocomplete="current-password">
            </div>
            <div class="form-group">
                <label class="form-label" for="newPassword">Новый пароль (не менее 8 символов):</label>
                <input type="password" id="newPassword" class="form-control" minlength="8" required autocomplete="new-password">
            </div>
            <div class="form-group">
                <label class="form-label" for="newPasswordRepeat">Повторите новый пароль:</label>
                <input type="password" id="newPasswordRepeat" class="form-control" minlength="8" required autocomplete="new-password">
            </div>
            <div id="passwordMessage" class="alert-banner" style="display: none; margin-bottom: 14px;"></div>
            <button type="submit" class="btn btn-primary" style="width: 100%; min-height: 52px;">Сохранить новый пароль</button>
        </form>
        <p style="color: var(--text-muted); font-size: 0.9em; margin: 16px 0 8px;">После смены пароля все остальные устройства выйдут из системы.</p>
        <button type="button" id="btnLogoutAll" class="btn btn-outline" style="width: 100%; color: var(--danger-color); border-color: var(--danger-color);">
            Выйти на всех устройствах (включая телефон подопечного)
        </button>
    </div>
</div>
<?php endif; ?>

<div id="a11yLiveRegion" class="sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
<script>window.APP_CONFIG = <?php echo json_encode(['mode' => 'app', 'role' => $viewRole ?? 'guest', 'isGuest' => !empty($isGuest), 'demoAutologin' => false], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;</script>
<script src="/js/speech.js?v=<?php echo @filemtime(__DIR__ . '/js/speech.js') ?: time(); ?>"></script>
<script src="/js/app.js?v=<?php echo @filemtime(__DIR__ . '/js/app.js') ?: time(); ?>"></script>
</body>
</html>
