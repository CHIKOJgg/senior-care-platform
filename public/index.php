<?php
// Main Web Portal
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Платформа взаимной помощи «ЗаботаРядом»</title>
    <link rel="stylesheet" href="/css/accessibility.css">
    <style>
        <?php include __DIR__ . '/css/accessibility.css'; ?>
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: bold;
        }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-success { background: #dcfce7; color: #166534; }
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: #f8fafc;
            border: 2px solid #cbd5e1;
            border-radius: 12px;
            padding: 16px;
            text-align: center;
        }
        .stat-num {
            font-size: 32px;
            font-weight: 800;
            color: #0284c7;
        }
    </style>
</head>
<body class="senior-mode">

<header>
    <div class="container header-inner">
        <a href="/" class="brand">
            <span>🤝</span> ЗаботаРядом
        </a>
        <div class="a11y-controls">
            <button id="speakPageBtn" class="btn btn-outline" title="Озвучить страницу">
                🔊 Озвучить
            </button>
            <button id="toggleSeniorMode" class="btn btn-primary" title="Переключить крупный режим">
                🔍 Крупный шрифт
            </button>
        </div>
    </div>
</header>

<main class="container">
    <!-- Active Danger Alert Banner -->
    <div id="urgentAlertBox" style="display: none; background: #fee2e2; border: 3px solid #dc2626; border-radius: 14px; padding: 16px; margin-bottom: 20px;">
        <h3 id="alertTitle" style="color: #991b1b;">⚠️ Внимание!</h3>
        <p id="alertDesc" style="color: #7f1d1d; margin-top: 6px;"></p>
    </div>

    <!-- Navigation Tabs -->
    <nav class="nav-tabs" role="tablist">
        <button class="tab-btn active" data-tab="tab-elderly">👵 Мне нужна помощь</button>
        <button class="tab-btn" data-tab="tab-volunteer">🙋 Я волонтёр</button>
        <button class="tab-btn" data-tab="tab-safety">🛡️ Школа безопасности</button>
        <button class="tab-btn" data-tab="tab-coordinator">🏛️ Координатор (ТЦСОН)</button>
    </nav>

    <!-- Tab 1: Elderly Help Request -->
    <section id="tab-elderly" class="tab-pane">
        <div class="card">
            <h2 style="margin-bottom: 16px;">Какая помощь вам требуется?</h2>
            
            <input type="hidden" id="selectedCategory" value="1">
            <div class="category-grid">
                <div class="category-card selected" data-id="1">
                    <span class="category-icon">🛒</span>
                    <div class="category-title">Продукты</div>
                </div>
                <div class="category-card" data-id="2">
                    <span class="category-icon">💊</span>
                    <div class="category-title">Лекарства</div>
                </div>
                <div class="category-card" data-id="3">
                    <span class="category-icon">🧹</span>
                    <div class="category-title">Быт и дом</div>
                </div>
                <div class="category-card" data-id="4">
                    <span class="category-icon">📱</span>
                    <div class="category-title">Телефон / ЖКХ</div>
                </div>
                <div class="category-card" data-id="5">
                    <span class="category-icon">🚶</span>
                    <div class="category-title">Прогулка</div>
                </div>
                <div class="category-card" data-id="6">
                    <span class="category-icon">☕</span>
                    <div class="category-title">Беседа</div>
                </div>
            </div>

            <!-- Voice Dictation Feature -->
            <div class="card" style="background: #eff6ff; border-color: #93c5fd;">
                <div class="voice-bar">
                    <button type="button" id="micBtn" class="mic-btn" title="Нажмите и говорите">
                        🎙️
                    </button>
                    <div>
                        <strong>Голосовой ввод заявки</strong>
                        <p style="font-size: 0.9em; color: #1e3a8a;">Нажмите на микрофон и скажите вслух, что вам привезти или сделать.</p>
                    </div>
                </div>
            </div>

            <form id="helpForm">
                <div class="form-group">
                    <label class="form-label" for="requestDescription">Опишите вашу просьбу:</label>
                    <textarea id="requestDescription" class="form-control" placeholder="Например: купите, пожалуйста, молоко и серый хлеб..."></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label" for="requestAddress">Ваш адрес проживания:</label>
                    <input type="text" id="requestAddress" class="form-control" value="г. Минск, пр-т Независимости, 45, кв. 12">
                </div>
                <button type="submit" class="btn btn-success" style="width: 100%; font-size: 1.2em;">
                    ✅ Отправить заявку волонтёрам
                </button>
            </form>

            <!-- Modal / Banner for Secret Code -->
            <div id="secretCodeBanner" class="secret-code-banner" style="display: none;">
                <h3>🛡️ Заявка отправлена! Ваша безопасность:</h3>
                <p>Когда к вам придёт волонтёр, он ОБЯЗАН назвать кодовое слово:</p>
                <span id="displaySecretWord" class="secret-word">Василёк</span>
                <p style="color: #991b1b; font-weight: bold;">⚠️ Не открывайте дверь, если человек не знает это слово!</p>
            </div>
        </div>
    </section>

    <!-- Tab 2: Volunteer Dashboard & Gamification -->
    <section id="tab-volunteer" class="tab-pane" style="display: none;">
        <div class="card">
            <h2>Лента открытых заявок поблизости</h2>
            <p style="margin-bottom: 20px; color: #64748b;">ИИ автоматически рассчитал расстояние от вашей текущей геоточки в Советском районе Минска:</p>
            <div id="volunteerRequestList">
                <p>Загрузка доступных заявок...</p>
            </div>
        </div>

        <div class="card">
            <h2>🏆 Таблица лидеров волонтёров района</h2>
            <p style="margin-bottom: 16px; color: #64748b;">За каждую выполненную заявку начисляется +50 очков опыта и растёт социальный рейтинг:</p>
            <div id="leaderboardList"></div>
        </div>
    </section>

    <!-- Tab 3: Safety & Anti-Fraud Scanner -->
    <section id="tab-safety" class="tab-pane" style="display: none;">
        <div class="card" style="border-left: 6px solid #dc2626;">
            <h2>🚨 Проверка подозрительного звонка или СМС</h2>
            <p style="margin: 8px 0 16px 0;">Вам позвонили из «банка» или милиции? Вставьте текст или перескажите фразу сюда для проверки:</p>
            <div class="form-group">
                <textarea id="fraudCheckInput" class="form-control" placeholder="Например: Позвонили в вайбер, сказали что на меня берут кредит и просят код из смс..."></textarea>
            </div>
            <button id="runFraudCheckBtn" class="btn btn-danger">🔍 Проверить на мошенничество</button>
            
            <div id="fraudResultBox" style="display: none; margin-top: 16px; padding: 16px; border-radius: 12px; border: 2px solid;"></div>
        </div>

        <div class="card">
            <h2>🛡️ Памятки и уроки безопасности</h2>
            <p style="margin-bottom: 20px;">Простые правила, которые защитят вас от телефонных и бытовых мошенников:</p>
            <div id="educationList"></div>
        </div>
    </section>

    <!-- Tab 4: Coordinator Panel -->
    <section id="tab-coordinator" class="tab-pane" style="display: none;">
        <div class="card">
            <h2>🏛️ Панель координатора ТЦСОН (Советский район)</h2>
            <p style="margin-bottom: 20px; color: #64748b;">Мониторинг социальной обстановки, верификация добровольцев и экстренные оповещения.</p>
            
            <div class="stat-grid" id="coordinatorStats">
                <div class="stat-card">
                    <div class="stat-num" id="statTotal">-</div>
                    <div>Всего заявок</div>
                </div>
                <div class="stat-card">
                    <div class="stat-num" id="statActive">-</div>
                    <div>Требуют помощи сейчас</div>
                </div>
                <div class="stat-card">
                    <div class="stat-num" id="statVolunteers">-</div>
                    <div>Верифицированных волонтёров</div>
                </div>
            </div>
        </div>

        <!-- Publish Alert Form -->
        <div class="card">
            <h3>📢 Опубликовать экстренное оповещение для пожилых граждан</h3>
            <form id="alertForm" style="margin-top: 12px;">
                <div class="form-group">
                    <label class="form-label">Заголовок предупреждения:</label>
                    <input type="text" id="alertInputTitle" class="form-control" placeholder="Например: Внимание: лже-электрики в микрорайоне">
                </div>
                <div class="form-group">
                    <label class="form-label">Подробное описание и совет:</label>
                    <textarea id="alertInputDesc" class="form-control" placeholder="Не пускайте посторонних без предъявления служебного удостоверения..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Опубликовать баннер</button>
            </form>
        </div>
    </section>
</main>

<script src="/js/speech.js"></script>
<script src="/js/app.js"></script>
</body>
</html>
