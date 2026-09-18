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
    <!-- Navigation Tabs -->
    <nav class="nav-tabs" role="tablist">
        <button class="tab-btn active" data-tab="tab-elderly">👵 Мне нужна помощь</button>
        <button class="tab-btn" data-tab="tab-volunteer">🙋 Я волонтёр</button>
        <button class="tab-btn" data-tab="tab-safety">🛡️ Школа безопасности</button>
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

    <!-- Tab 2: Volunteer Dashboard -->
    <section id="tab-volunteer" class="tab-pane" style="display: none;">
        <div class="card">
            <h2>Лента открытых заявок поблизости</h2>
            <p style="margin-bottom: 20px; color: #64748b;">ИИ автоматически рассчитал расстояние от вашей текущей геоточки в Советском районе Минска:</p>
            <div id="volunteerRequestList">
                <p>Загрузка доступных заявок...</p>
            </div>
        </div>
    </section>

    <!-- Tab 3: Safety & Education -->
    <section id="tab-safety" class="tab-pane" style="display: none;">
        <div class="card">
            <h2>🛡️ Памятки и уроки безопасности</h2>
            <p style="margin-bottom: 20px;">Простые правила, которые защитят вас от телефонных и бытовых мошенников:</p>
            <div id="educationList"></div>
        </div>
    </section>
</main>

<script src="/js/speech.js"></script>
<script src="/js/app.js"></script>
</body>
</html>
