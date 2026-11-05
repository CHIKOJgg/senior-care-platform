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
        $currentUser = AuthService::getCurrentUser();

        $hasExplicitAuth = !empty($_SERVER['HTTP_AUTHORIZATION']) || 
                           !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ||
                           !empty($_SERVER['HTTP_X_AUTH_TOKEN']) || 
                           !empty($_COOKIE['auth_token']) || 
                           !empty($_SERVER['HTTP_X_DEMO_USER']);

        $userId = $hasExplicitAuth && $currentUser 
            ? (int)($currentUser['user_id'] ?? $currentUser['id'] ?? 0)
            : (int)($input['user_id'] ?? 0);

        if ($userId <= 0) {
            Router::json(['error' => 'Требуется авторизация в системе для фиксации согласия'], 401);
        }

        $records = [];
        $policyVersion = $input['policy_version'] ?? PrivacyService::CURRENT_POLICY_VERSION;

        // Поддержка пакетной и единичной фиксации
        $consentList = isset($input['consents']) && is_array($input['consents'])
            ? $input['consents']
            : [['consent_type' => $input['consent_type'] ?? '']];

        try {
            foreach ($consentList as $item) {
                $type = trim($item['consent_type'] ?? '');
                $version = $item['policy_version'] ?? $policyVersion;
                if (!$type) {
                    Router::json(['error' => 'Укажите обязательный тип согласия (consent_type)'], 400);
                }

                $record = $this->privacyService->recordConsent(
                    $userId,
                    $type,
                    $version,
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                );
                $records[] = $record;
            }
        } catch (InvalidArgumentException $e) {
            Router::json(['error' => $e->getMessage()], 400);
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

        try {
            $result = $this->privacyService->anonymizeUser($userId);

            // Если пользователь обезличил сам себя — сбрасываем куки сессии
            if ($userId === (int)($user['user_id'] ?? $user['id'] ?? 0)) {
                @setcookie('auth_token', '', ['expires' => time() - 3600, 'path' => '/']);
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
