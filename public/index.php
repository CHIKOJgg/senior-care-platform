<?php
// If request is targeting API, delegate to API router
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($requestUri && str_starts_with($requestUri, '/api/')) {
    require __DIR__ . '/api.php';
    exit;
}

// Two modes: "pensioner" (default, no login UI) and "family" (/family or ?mode=family)
$mode = ($requestUri === '/family' || ($_GET['mode'] ?? '') === 'family') ? 'family' : 'pensioner';
$demoAutologin = (getenv('APP_ENV') ?: 'local') === 'local';

$initialRole = 'user';
if ($mode === 'family') {
    if (isset($_GET['role'])) {
        $initialRole = in_array($_GET['role'], ['admin', 'volunteer', 'user'], true) ? $_GET['role'] : 'user';
    } elseif ($requestUri === '/admin') {
        $initialRole = 'admin';
    } elseif ($requestUri === '/volunteer') {
        $initialRole = 'volunteer';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Платформа взаимной помощи «ЗаботаРядом»</title>
    <link rel="stylesheet" href="/css/accessibility.css">
</head>
<body data-initial-role="<?php echo htmlspecialchars($initialRole); ?>" data-mode="<?php echo $mode; ?>">

<!-- Top Status Bar (без входа: подопечные работают сразу) -->
<div class="quick-auth-bar">
    <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
        <span id="currentUserDisplay" style="font-weight: 700; color: var(--primary-color); display: inline-flex; align-items: center; gap: 6px;">
            <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span>Загрузка профиля...</span>
        </span>
        <?php if ($mode === 'family'): ?>
        <button type="button" id="btnChangePassword" class="btn btn-outline btn-xs" style="height: 30px;">Сменить пароль</button>
        <button type="button" id="btnLogout" class="btn btn-outline btn-xs" style="height: 30px;">Выйти с этого устройства</button>
        <?php endif; ?>
        <div class="live-indicator">
            <span class="pulse-dot"></span>
            <span>СИСТЕМА ОНЛАЙН</span>
        </div>
    </div>
</div>

<header>
    <div class="container header-inner">
        <a href="/" class="brand">
            <svg class="ui-icon ui-icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>
            </svg>
            ЗаботаРядом
        </a>

        <!-- Main Role Switcher (family/staff mode only): User vs Admin vs Volunteer -->
        <?php if ($mode === 'family'): ?>
        <div class="role-switcher" role="navigation" aria-label="Выбор роли">
            <button class="role-btn <?php echo $initialRole === 'user' ? 'active' : ''; ?>" data-role="user" id="roleBtnUser">
                <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Пользователь
            </button>
            <button class="role-btn <?php echo $initialRole === 'admin' ? 'active' : ''; ?>" data-role="admin" id="roleBtnAdmin">
                <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                Администратор
            </button>
            <button class="role-btn <?php echo $initialRole === 'volunteer' ? 'active' : ''; ?>" data-role="volunteer" id="roleBtnVolunteer">
                <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Волонтёр
            </button>
        </div>
        <?php endif; ?>

        <div class="a11y-controls">
            <button id="speakPageBtn" class="btn btn-outline" title="Озвучить страницу">
                <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>
                Озвучить
            </button>
            <button id="toggleSeniorMode" class="btn btn-outline" title="Переключить крупный режим">
                <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                Крупный шрифт
            </button>
        </div>
    </div>
</header>

<main class="container">
    <!-- Active Danger Alert Banner for Citizens -->
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

    <!-- ========================================================================= -->
    <!-- 1. USER SECTION: STRICTLY 2 FUNCTIONS ONLY                               -->
    <!-- ========================================================================= -->
    <div id="view-user" class="role-view" style="<?php echo $initialRole === 'user' ? 'display: block;' : 'display: none;'; ?>">
        <!-- Active Emergency SOS Banner (Displays EMERCOM-112-BY ticket) -->
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

        <!-- Dedicated High-Contrast Emergency SOS Button (WCAG 2.1 AAA Compliant) -->
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
                        <h2 style="font-size: 1.35em; color: var(--danger-color); margin-bottom: 4px;">Экстренная помощь подопечному</h2>
                        <p style="font-size: 0.95em; color: #7f1d1d; margin: 0;">Если вам стало плохо или угрожает опасность — нажмите тревожную кнопку. Вызов мгновенно поступит координатору ТЦСОН и в службу 112.</p>
                    </div>
                </div>
                <button type="button" id="sosBtn" class="btn btn-danger btn-sos sos-btn" aria-label="Экстренная помощь SOS — вызов координатора и МЧС 112" style="min-height: 60px; min-width: 200px; font-size: 1.3em; font-weight: 900; letter-spacing: 1px; box-shadow: 0 4px 14px rgba(220, 38, 38, 0.4);" onclick="triggerSos()">
                    <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                    КНОПКА SOS
                </button>
            </div>
        </div>

        <!-- 2 Function Selector Navigation -->
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
                    <div class="function-sub">Проверить подозрительный звонок, СМС и правила безопасности</div>
                </div>
            </button>
        </div>

        <!-- User Function 1: Help Request -->
        <section id="func-request" class="user-func-pane">
            <div class="card">
                <h2 style="margin-bottom: 16px;">Какая помощь вам требуется?</h2>
                
                <input type="hidden" id="selectedCategory" value="1">
                <div class="category-grid">
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

                <!-- Voice Dictation Feature -->
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
                        <label class="form-label" for="requestAddress">Ваш адрес проживания:</label>
                        <input type="text" id="requestAddress" class="form-control" value="г. Минск, пр-т Независимости, 45, кв. 12">
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

                <!-- Modal / Banner for Secret Code -->
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

            <!-- My Active Requests (for Elderly) -->
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
        </section>

        <!-- User Function 2: Safety & Anti-Fraud Scanner -->
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

            <div class="card">
                <h2 style="display: flex; align-items: center; gap: 8px;">
                    <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="M6 6h10"/><path d="M6 10h10"/></svg>
                    <span>Памятки и уроки безопасности</span>
                </h2>
                <p style="margin-bottom: 20px;">Простые правила, которые защитят вас от телефонных и бытовых мошенников:</p>
                <div id="educationList"></div>
            </div>
        </section>

        <!-- Privacy & Right to be Forgotten Card (Law 99-Z) -->
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

    <!-- ========================================================================= -->
    <!-- 2. ADMIN SECTION: MANAGEMENT & ANALYTICS                                  -->
    <!-- ========================================================================= -->
    <div id="view-admin" class="role-view" style="<?php echo $initialRole === 'admin' ? 'display: block;' : 'display: none;'; ?>">
        <!-- Coordinator Priority 0 SOS Incident Console Banner -->
        <div id="coordinatorSosBannerContainer" style="display: none;"></div>

        <div class="card" style="border-top: 6px solid var(--text-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
                <div>
                    <h2 style="display: flex; align-items: center; gap: 8px;">
                        <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M8 10h.01"/><path d="M16 10h.01"/><path d="M8 14h.01"/><path d="M16 14h.01"/></svg>
                        <span>Панель администратора ТЦСОН</span>
                    </h2>
                    <p style="color: var(--text-muted);">Управление заявками района, верификация волонтёров и аналитика социальной обстановки</p>
                </div>
                <span class="admin-badge">СОВЕТСКИЙ РАЙОН МИНСКА</span>
            </div>

            <!-- Admin Sub-Navigation -->
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
            </div>

            <!-- Tab 1: Analytics & Reports -->
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
                    <!-- Categories Breakdown -->
                    <div class="card" style="margin-bottom: 0;">
                        <h3>Распределение потребностей по категориям</h3>
                        <div id="categoryAnalyticsBars" style="margin-top: 16px;">
                            <p>Загрузка статистики...</p>
                        </div>
                    </div>

                    <!-- Top Volunteers Leaderboard in Admin -->
                    <div class="card" style="margin-bottom: 0;">
                        <h3 style="display: flex; align-items: center; gap: 8px;">
                            <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
                            <span>Лидеры волонтёрского движения района</span>
                        </h3>
                        <p style="color: var(--text-muted); font-size: 0.9em; margin-bottom: 12px;">Рейтинг формируется на основе подтверждённых визитов и отзывов:</p>
                        <div id="adminLeaderboardList"></div>
                    </div>
                </div>
            </div>

            <!-- Tab 2: Volunteer Verification Queue -->
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

            <!-- Tab 3: Help Requests Management -->
            <div id="admin-requests" class="admin-tab-pane" style="display: none;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <h3>Реестр заявок района</h3>
                        <p style="color: var(--text-muted); font-size: 0.9em;">Полный контроль исполнения обращений граждан</p>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn btn-outline btn-sm" onclick="loadAdminRequests('all')">Все</button>
                        <button class="btn btn-outline btn-sm" onclick="loadAdminRequests('open')">Открытые</button>
                        <button class="btn btn-outline btn-sm" onclick="loadAdminRequests('in_progress')">В работе</button>
                        <button class="btn btn-outline btn-sm" onclick="loadAdminRequests('completed')">Выполненные</button>
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

            <!-- Tab 4: Safety Alerts -->
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
                        <h3>Активные предупреждения в районе</h3>
                        <p style="color: var(--text-muted); font-size: 0.9em; margin-bottom: 16px;">Управление действующими оповещениями:</p>
                        <div id="activeAlertsAdminList">
                            <p>Загрузка оповещений...</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab 5: Coordinator Watchdog View -->
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

            <!-- Tab 6: Access Recovery (coordinator-issued one-time codes) -->
            <div id="admin-access" class="admin-tab-pane" style="display: none;">
                <div style="max-width: 620px;">
                    <h3 style="display: flex; align-items: center; gap: 8px;">
                        <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <span>Восстановление доступа подопечного</span>
                    </h3>
                    <p style="color: var(--text-muted); font-size: 0.95em; margin-bottom: 16px;">
                        Введите телефон подопечного или волонтёра. Код действует 30 минут, используется один раз и после смены пароля завершает все прежние сеансы.
                    </p>
                    <form id="resetCodeForm">
                        <div class="form-group">
                            <label class="form-label" for="resetCodePhone">Телефон пользователя:</label>
                            <input type="tel" id="resetCodePhone" class="form-control" placeholder="+375291112233" required>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%; min-height: 52px;">Создать одноразовый код</button>
                    </form>
                    <div id="resetCodeError" class="alert-banner alert-banner-danger" style="display: none; margin-top: 16px;"></div>
                    <div id="resetCodeResult" class="card card-accent-success" style="display: none; margin-top: 16px;">
                        <p style="font-size: 0.95em;">Передайте код родственнику по телефону. Не отправляйте его в мессенджеры.</p>
                        <div id="resetCodeValue" style="font-size: 2em; font-weight: 900; letter-spacing: 6px; text-align: center; margin: 12px 0; color: var(--accent-color);"></div>
                        <p id="resetCodeUser" style="text-align: center; color: var(--text-muted);"></p>
                        <p id="resetCodeTtl" style="text-align: center; color: var(--text-muted); font-size: 0.9em;"></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- 3. VOLUNTEER SECTION: FOR VOLUNTEER ACTIONS & VERIFICATION DEMO          -->
    <!-- ========================================================================= -->
    <div id="view-volunteer" class="role-view" style="<?php echo $initialRole === 'volunteer' ? 'display: block;' : 'display: none;'; ?>">
        <!-- Volunteer Verification Status Banner -->
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

        <!-- Volunteer Active Tasks in Progress -->
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
                    <p style="color: var(--text-muted);">ИИ автоматически рассчитал расстояние от вашей текущей геоточки в Советском районе Минска:</p>
                </div>
                <button class="btn btn-outline btn-sm" onclick="loadVolunteerRequests()">
                    <svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.19"/></svg>
                    Обновить ленту
                </button>
            </div>
            <div id="volunteerRequestList">
                <p>Загрузка доступных заявок...</p>
            </div>
        </div>

        <div class="card">
            <h2 style="display: flex; align-items: center; gap: 8px;">
                <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
                <span>Таблица лидеров волонтёров района</span>
            </h2>
            <p style="margin-bottom: 16px; color: var(--text-muted);">За каждую выполненную заявку начисляется +50 очков опыта и растёт социальный рейтинг:</p>
            <div id="leaderboardList"></div>
        </div>
    </div>
</main>

<!-- Volunteer Geolocation Completion Modal (Proof-of-Presence) -->
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
            
            <!-- Дружелюбный блок оповещения о превышении расстояния -->
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

<!-- Volunteer Digital Badge Modal -->
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
            
            <!-- Dynamic 1h TTL countdown timer -->
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

<?php if ($mode === 'family'): ?>
<!-- Family security: password change & remote logout -->
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

<!-- Session gate: pensioner sees only the status; family signs in once per device -->
<div id="sessionGate" class="modal-backdrop" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="gateTitle">
    <div class="modal-dialog" style="max-width: 480px;">
        <h2 id="gateTitle" style="text-align: center; margin-bottom: 12px;">ЗаботаРядом</h2>
        <p id="gateMessage" style="text-align: center; font-size: 1.1em; margin-bottom: 20px;"></p>
        <?php if ($mode === 'family'): ?>
        <form id="sessionGateForm" autocomplete="on">
            <div class="form-group">
                <label class="form-label" for="gatePhone">Номер телефона:</label>
                <input type="tel" id="gatePhone" name="phone" class="form-control" placeholder="+375291112233" required autocomplete="username">
            </div>
            <div class="form-group">
                <label class="form-label" for="gatePassword">Пароль:</label>
                <input type="password" id="gatePassword" name="password" class="form-control" required autocomplete="current-password">
            </div>
            <div class="form-group" style="margin: 14px 0;">
                <label class="checkbox-label" for="gateRemember" style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                    <input type="checkbox" id="gateRemember" checked style="width: 22px; height: 22px; min-width: 22px; margin-top: 2px;">
                    <span>Запомнить на этом телефоне: подопечный будет открывать сайт без входа</span>
                </label>
            </div>
            <div id="gateError" class="alert-banner alert-banner-danger" style="display: none; margin-bottom: 14px;"></div>
            <button type="submit" class="btn btn-primary" style="width: 100%; min-height: 56px; font-size: 1.15em;">Войти</button>
            <button type="button" class="btn btn-outline" style="width: 100%; margin-top: 10px;" onclick="hideSessionGate()">Отмена</button>
        </form>
        <button type="button" id="showResetFormBtn" class="btn btn-outline" style="width: 100%; margin-top: 10px;">Забыли пароль?</button>
        <div id="resetPasswordBox" style="display: none; margin-top: 16px; border-top: 1px solid var(--border-color); padding-top: 16px;">
            <p style="font-size: 0.95em; color: var(--text-muted); margin-bottom: 12px;">
                Позвоните координатору ТЦСОН и попросите одноразовый код. Код действует 30 минут.
            </p>
            <form id="resetPasswordForm">
                <div class="form-group">
                    <label class="form-label" for="resetPhone">Номер телефона:</label>
                    <input type="tel" id="resetPhone" class="form-control" placeholder="+375291112233" required autocomplete="username">
                </div>
                <div class="form-group">
                    <label class="form-label" for="resetCode">Код подтверждения:</label>
                    <input type="text" id="resetCode" class="form-control" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" placeholder="6 цифр" required autocomplete="one-time-code">
                </div>
                <div class="form-group">
                    <label class="form-label" for="resetNewPassword">Новый пароль (не менее 8 символов):</label>
                    <input type="password" id="resetNewPassword" class="form-control" minlength="8" required autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label class="form-label" for="resetNewPasswordRepeat">Повторите новый пароль:</label>
                    <input type="password" id="resetNewPasswordRepeat" class="form-control" minlength="8" required autocomplete="new-password">
                </div>
                <div id="resetPasswordMessage" class="alert-banner" style="display: none; margin-bottom: 14px;"></div>
                <button type="submit" class="btn btn-primary" style="width: 100%; min-height: 52px;">Сохранить новый пароль</button>
                <button type="button" id="cancelResetBtn" class="btn btn-outline" style="width: 100%; margin-top: 10px;">Вернуться ко входу</button>
            </form>
        </div>
        <?php else: ?>
        <a href="tel:112" class="btn btn-danger" style="width: 100%; min-height: 60px; font-size: 1.2em; text-decoration: none; display: flex; align-items: center; justify-content: center;">Позвонить 112</a>
        <p style="text-align: center; font-size: 0.9em; color: var(--text-muted); margin-top: 16px;">Вводить ничего не нужно.</p>
        <?php endif; ?>
    </div>
</div>

<script>window.APP_CONFIG = <?php echo json_encode(['mode' => $mode, 'demoAutologin' => $demoAutologin], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;</script>
<script src="/js/speech.js"></script>
<script src="/js/app.js"></script>
</body>
</html>
