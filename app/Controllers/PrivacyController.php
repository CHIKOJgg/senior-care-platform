<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Router;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Services\AuthService;
use App\Services\PrivacyService;
use App\Models\User;
use DomainException;
use InvalidArgumentException;
use Throwable;

class PrivacyController
{
    private PrivacyService $privacyService;

    public function __construct()
    {
        $this->privacyService = new PrivacyService();
    }

    public function recordConsent(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!RateLimiter::check("consent_record:{$ip}", 30, 60)) {
            Router::json(['error' => 'Слишком много запросов. Пожалуйста, подождите минуту.'], 429);
        }

        $input = Router::getJsonInput();
        $hasExplicitAuth = !empty($_SERVER['HTTP_AUTHORIZATION'])
            || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
            || !empty($_SERVER['HTTP_X_AUTH_TOKEN'])
            || !empty($_COOKIE['auth_token']);

        $currentUser = $hasExplicitAuth ? AuthService::getCurrentUser() : null;
        $userId = $currentUser ? (int)($currentUser['user_id'] ?? $currentUser['id'] ?? 0) : 0;
        if ($userId <= 0) {
            Router::json(['error' => 'Требуется авторизация в системе для фиксации согласия'], 401);
        }

        $records = [];
        $policyVersion = trim((string)($input['policy_version'] ?? PrivacyService::CURRENT_POLICY_VERSION));
        if ($policyVersion === '' || mb_strlen($policyVersion) > 50) {
            Router::json(['error' => 'Некорректная версия политики обработки данных'], 400);
        }

        // Поддержка пакетной и единичной фиксации
        $consentList = isset($input['consents']) && is_array($input['consents'])
            ? $input['consents']
            : [['consent_type' => $input['consent_type'] ?? '']];

        // Validate the whole batch before writing anything: a rejected item must
        // not leave a partially recorded consent set behind.
        $normalized = [];
        foreach ($consentList as $item) {
            $type = trim((string)($item['consent_type'] ?? ''));
            $version = trim((string)($item['policy_version'] ?? $policyVersion));
            if ($type === '') {
                Router::json(['error' => 'Укажите обязательный тип согласия (consent_type)'], 400);
            }
            if (!in_array($type, PrivacyService::getSupportedConsentTypes(), true)) {
                Router::json(['error' => "Недопустимый тип согласия: {$type}"], 400);
            }
            if ($version === '' || mb_strlen($version) > 50) {
                Router::json(['error' => 'Некорректная версия политики обработки данных'], 400);
            }
            $normalized[] = ['type' => $type, 'version' => $version];
        }

        $pdo = \App\Core\Database::getConnection();
        try {
            $pdo->beginTransaction();
            foreach ($normalized as $item) {
                $records[] = $this->privacyService->recordConsent(
                    $userId,
                    $item['type'],
                    $item['version'],
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'), 0, 1000, 'UTF-8')
                );
            }
            $pdo->commit();
        } catch (InvalidArgumentException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Router::json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error('Consent batch failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            Router::json(['error' => 'Не удалось зафиксировать согласие'], 500);
        }

        Router::json([
            'success' => true,
            'message' => 'Согласие на обработку персональных данных успешно зафиксировано',
            'data' => $records
        ], 201);
    }

    public function getConsents(): void
    {
        $user = AuthService::requireAuth();
        $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
        $consents = $this->privacyService->getConsents($userId);

        Router::json([
            'success' => true,
            'data' => $consents
        ]);
    }

    public function revokeConsent(): void
    {
        $user = AuthService::requireAuth();
        $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
        $input = Router::getJsonInput();

        $consentType = trim((string)($input['consent_type'] ?? ''));
        if ($consentType === '') {
            Router::json(['error' => 'Укажите обязательный тип согласия (consent_type) для отзыва'], 400);
        }

        if (!in_array($consentType, PrivacyService::getSupportedConsentTypes(), true)) {
            Router::json(['error' => "Недопустимый тип согласия: {$consentType}"], 400);
        }

        $revoked = $this->privacyService->revokeConsent($userId, $consentType);

        Router::json([
            'success' => true,
            'message' => $revoked 
                ? "Согласие [{$consentType}] успешно отозвано" 
                : "Активное согласие данного типа не найдено",
            'revoked' => $revoked,
            'consent_type' => $consentType
        ], 200);
    }

    public function anonymize(): void
    {
        $user = AuthService::requireAuth();
        $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
        $input = Router::getJsonInput();

        if (empty($input['confirm']) || $input['confirm'] !== true) {
            Router::json(['error' => 'Для необратимого обезличивания персональных данных требуется явное подтверждение (confirm: true)'], 400);
        }

        // Если передан target user_id (координатор/админ обезличивает подопечного)
        if (!empty($input['user_id']) && (int)$input['user_id'] !== $userId) {
            if (!in_array($user['role_code'] ?? '', ['admin', 'coordinator'], true)) {
                Router::json(['error' => 'Недостаточно прав для обезличивания стороннего пользователя'], 403);
            }
            $userId = (int)$input['user_id'];
        }

        $target = User::getWithProfile($userId);
        if (!$target) {
            Router::json(['error' => 'Пользователь не найден'], 404);
        }
        $targetRole = (string)($target['role_code'] ?? '');
        if (!in_array($targetRole, ['elderly', 'volunteer', 'admin', 'coordinator'], true)) {
            Router::json(['error' => 'Недопустимая роль пользователя для обезличивания'], 400);
        }
        $isSelf = $userId === (int)($user['user_id'] ?? $user['id'] ?? 0);
        if (!$isSelf && !in_array($targetRole, ['elderly', 'volunteer'], true)) {
            Router::json(['error' => 'Нельзя обезличить учётную запись сотрудника через этот endpoint'], 403);
        }

        try {
            $result = $this->privacyService->anonymizeUser($userId);

            // Если пользователь обезличил сам себя — сбрасываем куки сессии
            if ($userId === (int)($user['user_id'] ?? $user['id'] ?? 0)) {
                @setcookie('auth_token', '', [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'httponly' => true,
                    'secure' => !empty($_SERVER['HTTPS']),
                    'samesite' => 'Lax'
                ]);
            }

            Router::json($result, 200);
        } catch (DomainException $e) {
            Router::json(['error' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            Logger::error("Anonymization failed for user #{$userId}: " . $e->getMessage());
            Router::json(['error' => 'Внутренняя ошибка при выполнении процедуры обезличивания'], 500);
        }
    }

    public function anonymizeElderly(string $id): void
    {
        $user = AuthService::requireAuth(['coordinator', 'admin']);
        $targetId = (int)$id;
        $input = Router::getJsonInput();

        if (empty($input['confirm']) || $input['confirm'] !== true) {
            Router::json(['error' => 'Для необратимого обезличивания подопечного требуется явное подтверждение (confirm: true)'], 400);
        }

        $targetUser = User::find($targetId);
        if (!$targetUser) {
            Router::json(['error' => 'Подопечный с указанным ID не найден'], 404);
        }
        $targetProfile = User::getWithProfile($targetId);
        if (($targetProfile['role_code'] ?? '') !== 'elderly') {
            Router::json(['error' => 'Обезличить через этот endpoint можно только подопечного'], 403);
        }

        try {
            $reason = $input['reason'] ?? 'Письменное заявление подопечного в ТЦСОН';
            $result = $this->privacyService->anonymizeUser($targetId, $reason);
            Router::json($result, 200);
        } catch (DomainException $e) {
            Router::json(['error' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            Logger::error("Coordinator anonymization failed for elderly #{$targetId}: " . $e->getMessage());
            Router::json(['error' => 'Внутренняя ошибка при выполнении процедуры обезличивания'], 500);
        }
    }
}
