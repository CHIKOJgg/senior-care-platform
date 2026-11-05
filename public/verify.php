<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Services/BadgeService.php';

use App\Services\BadgeService;

// Извлечение токена из query параметра или path_info
$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($requestUri && preg_match('#^/verify(?:/|\.php/)?([a-zA-Z0-9_\-\.]+)#', $requestUri, $matches)) {
        $token = $matches[1];
    }
}

$badgeData = null;
$isValid = false;
$errorMessage = '';

if ($token !== '') {
    $badgeData = BadgeService::verifyVolunteerBadge($token);
    $isValid = (bool)($badgeData['valid'] ?? false);
    if (!$isValid) {
        $errorMessage = $badgeData['error'] ?? 'Срок действия QR-кода истёк или цифровая подпись недействительна';
    }
} else {
    $errorMessage = 'QR-код не передан. Пожалуйста, отсканируйте цифровой бейдж волонтёра камерой смартфона.';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Официальная проверка волонтёра — ТЦСОН Советского района г. Минска</title>
    <link rel="stylesheet" href="/css/accessibility.css">
    <style>
        .verify-card {
            max-width: 560px;
            margin: 40px auto;
            background: #ffffff;
            border: 3px solid #000000;
            border-radius: 16px;
            padding: 32px 24px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        .seal-header {
            text-align: center;
            margin-bottom: 24px;
        }
        .status-badge-lg {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 18px;
            border-radius: 30px;
            font-size: 1.15rem;
            font-weight: 800;
            margin-top: 12px;
        }
        .status-verified {
            background-color: #155724;
            color: #ffffff;
            border: 2px solid #0b2e13;
        }
        .status-invalid {
            background-color: #721c24;
            color: #ffffff;
            border: 2px solid #491217;
        }
        .data-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
            font-size: 1.05rem;
        }
        .data-label {
            color: #475569;
            font-weight: 600;
        }
        .data-val {
            color: #0f172a;
            font-weight: 700;
            text-align: right;
        }
    </style>
</head>
<body class="senior-mode">
    <main class="container">
        <div class="verify-card">
            <!-- Header with Official TЦСОН Seal (Pure Vector SVG) -->
            <div class="seal-header">
                <svg class="badge-seal-svg" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="50" cy="50" r="46" stroke="#004085" stroke-width="4" fill="#F0F7FF"/>
                    <path d="M50 14 L53 22 L62 22 L55 27 L58 35 L50 30 L42 35 L45 27 L38 22 L47 22 Z" fill="#004085"/>
                    <path d="M50 38 C35 38 24 50 24 64 C24 78 50 88 50 88 C50 88 76 78 76 64 C76 50 65 38 50 38 Z" fill="#155724"/>
                    <path d="M42 62 L48 68 L60 54" stroke="#FFFFFF" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <h1 style="font-size: 1.35rem; font-weight: 800; color: #000000; margin-bottom: 4px;">
                    ТЦСОН Советского района г. Минска
                </h1>
                <p style="color: #334155; font-size: 0.95rem; font-weight: 600;">
                    Государственный реестр аккредитации волонтёров
                </p>

                <?php if ($isValid && isset($badgeData['volunteer'])): ?>
                    <div class="status-badge-lg status-verified">
                        <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                        <span>АККРЕДИТАЦИЯ ПОДТВЕРЖДЕНА</span>
                    </div>
                <?php else: ?>
                    <div class="status-badge-lg status-invalid">
                        <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
                        </svg>
                        <span>БЕЙДЖ НЕ ДЕЙСТВИТЕЛЕН</span>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($isValid && isset($badgeData['volunteer'])): 
                $v = $badgeData['volunteer'];
                $fullNameFormatted = htmlspecialchars(trim(($v['first_name'] ?? '') . ' ' . ($v['last_initial'] ?? '')));
            ?>
                <!-- Valid Volunteer Details (Law 99-Z Compliant: No full phone or passport) -->
                <div style="margin-top: 20px;">
                    <div class="data-row">
                        <span class="data-label">Имя волонтёра:</span>
                        <span class="data-val" id="volunteerDisplayName"><?php echo $fullNameFormatted; ?></span>
                    </div>
                    <div class="data-row">
                        <span class="data-label">Организация:</span>
                        <span class="data-val"><?php echo htmlspecialchars($v['organization'] ?? 'ТЦСОН Советского района г. Минска'); ?></span>
                    </div>
                    <div class="data-row">
                        <span class="data-label">Статус проверки:</span>
                        <span class="data-val" style="color: #155724;">Паспорт и справка МВД проверены</span>
                    </div>
                    <div class="data-row">
                        <span class="data-label">Номер бейджа:</span>
                        <span class="data-val" style="font-family: monospace;"><?php echo htmlspecialchars($badgeData['badge_number'] ?? 'BY-MSK-SOV-2026'); ?></span>
                    </div>
                    <div class="data-row" style="border-bottom: 2px solid #000000;">
                        <span class="data-label">Действителен до:</span>
                        <span class="data-val"><?php echo date('H:i:s d.m.Y', (int)($v['expires_at'] ?? time())); ?></span>
                    </div>
                </div>

                <div class="card card-accent-success" style="margin-top: 24px; padding: 16px; border-radius: 12px; background: #f0fdf4;">
                    <p style="font-size: 0.95rem; color: #166534; line-height: 1.5; margin: 0;">
                        <strong>Информация для подопечного:</strong> Этот гражданин является официально зарегистрированным волонтёром территориального центра. Перед допуском в жилое помещение убедитесь, что волонтёр знает секретное слово вашей текущей заявки.
                    </p>
                </div>
            <?php else: ?>
                <!-- Warning Notice for Invalid / Expired Badge -->
                <div class="alert-banner alert-banner-danger" style="margin-top: 20px;">
                    <h3 style="display: flex; align-items: center; gap: 8px;">
                        <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/>
                            <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                        </svg>
                        <span>Внимание: проверка не пройдена!</span>
                    </h3>
                    <p style="margin-top: 8px;"><?php echo htmlspecialchars($errorMessage); ?></p>
                    <p style="margin-top: 8px; font-weight: bold;">
                        Не впускайте человека в жилое помещение и не передавайте денежные средства!
                    </p>
                </div>
            <?php endif; ?>

            <!-- Hotline Support Footer -->
            <div style="margin-top: 30px; text-align: center; border-top: 1px solid #cbd5e1; padding-top: 20px;">
                <p style="font-size: 0.95rem; color: #334155; margin-bottom: 6px;">
                    Горячая линия ТЦСОН Советского района г. Минска:
                </p>
                <a href="tel:+375173770000" style="font-size: 1.25rem; font-weight: 800; color: #004085; text-decoration: none;">
                    +375 (17) 377-00-00
                </a>
                <p style="font-size: 0.85rem; color: #64748b; margin-top: 8px;">
                    В экстренных ситуациях звоните по телефону <strong>102</strong> или <strong>112</strong>
                </p>
            </div>
        </div>
    </main>
</body>
</html>
