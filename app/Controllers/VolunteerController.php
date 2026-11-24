<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Router;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Url;
use App\Services\AuthService;
use App\Services\BadgeService;
use App\Services\Identity\IdentityProviderFactory;
use App\Models\VolunteerProfile;
use App\Models\VolunteerTrainingAssignment;
use App\Models\User;

class VolunteerController
{
    /**
     * Self-service endpoint for verified volunteers to retrieve their active dynamic badge
     * GET /api/volunteers/my-badge
     */
    public function myBadge(): void
    {
        $hasExplicitAuth = !empty($_SERVER['HTTP_AUTHORIZATION']) || 
                           !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ||
                           !empty($_SERVER['HTTP_X_AUTH_TOKEN']) || 
                           !empty($_COOKIE['auth_token']) || 
                           !empty($_SERVER['HTTP_X_DEMO_USER']);
        if (!$hasExplicitAuth) {
            Router::json(['error' => 'Требуется авторизация в системе', 'code' => 'UNAUTHORIZED'], 401);
        }

        $auth = AuthService::requireAuth(['volunteer']);
        $userId = (int)$auth['user_id'];

        $profile = VolunteerProfile::find($userId);
        if (!$profile) {
            Router::json([
                'error' => 'Профиль волонтёра не найден',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        $isVerified = (int)($profile['is_verified'] ?? 0);
        $status = strtoupper((string)($profile['verification_status'] ?? ''));

        if ($isVerified === -1 || $status === 'REJECTED' || $status === 'SUSPENDED') {
            Router::json([
                'error' => 'Доступ заблокирован: профиль волонтёра отклонён координатором',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        if ($isVerified !== 1) {
            Router::json([
                'error' => 'Цифровой бейдж доступен только после завершения верификации координатором ТЦСОН',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $user = User::find($userId);
        $displayName = $user ? $user['name'] : null;

        $token = BadgeService::generateBadgeToken($userId, $displayName);
        $payload = BadgeService::verifyBadgeToken($token);
        $qrSvg = BadgeService::generateBadgeQrSvg($token);

        $appUrl = Url::base();
        $verificationUrl = $appUrl . '/verify/' . rawurlencode($token);
        $vp = \App\Models\VolunteerProfile::find($userId);
        $badgeNumber = $payload['bid'] ?? sprintf(
            'BY-%s-%s-%04d',
            \App\Services\RegionService::short($vp['region_code'] ?? null),
            date('Y'),
            $userId
        );

        Router::json([
            'success' => true,
            'badge_token' => $token,
            'badge_number' => $badgeNumber,
            'expires_at' => $payload['exp'] ?? (time() + BadgeService::DEFAULT_TTL),
            'ttl_seconds' => BadgeService::DEFAULT_TTL,
            'verification_url' => $verificationUrl,
            'qr_svg' => $qrSvg,
            'badge_svg_url' => "/api/volunteers/badge/{$token}/svg"
        ]);
    }

    /**
     * Assigned retraining materials for the authenticated volunteer.
     * Route: GET /api/volunteers/training
     */
    public function trainingMaterials(): void
    {
        $auth = AuthService::requireAuth(['volunteer']);
        $materials = VolunteerTrainingAssignment::getForVolunteer((int)$auth['user_id']);

        Router::json([
            'success' => true,
            'count' => count($materials),
            'data' => $materials
        ]);
    }

    /**
     * Mark an assigned retraining material as completed.
     * Route: POST /api/volunteers/training/{id}/complete
     */
    public function completeTrainingMaterial(string $materialId): void
    {
        $auth = AuthService::requireAuth(['volunteer']);
        $volunteerId = (int)$auth['user_id'];
        $materialId = (int)$materialId;

        if (!VolunteerTrainingAssignment::isAssigned($volunteerId, $materialId)) {
            Router::json(['error' => 'Материал не назначен этому волонтёру'], 404);
        }

        $completed = VolunteerTrainingAssignment::markCompleted($volunteerId, $materialId);
        if ($completed) {
            Logger::info("Volunteer #{$volunteerId} completed training material #{$materialId}");
        }

        Router::json([
            'success' => true,
            'material_id' => $materialId,
            'message' => $completed
                ? 'Материал отмечен как изученный'
                : 'Материал уже отмечен как изученный'
        ]);
    }

    /**
     * Public verification endpoint for citizens/relatives scanning the QR code
     * GET /api/volunteers/badge/{token}
     */
    public function verifyPublicBadge(string $token): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!RateLimiter::check("badge_verify:{$ip}", 60, 60)) {
            Router::json(['valid' => false, 'error' => 'Слишком много запросов проверки. Подождите минуту.'], 429);
        }

        $result = BadgeService::verifyVolunteerBadge($token);

        if (!($result['valid'] ?? false)) {
            Router::json($result, 400);
        }

        Router::json($result, 200);
    }

    /**
     * Render dynamic visual SVG Badge Card directly
     * GET /api/volunteers/badge/{token}/svg
     */
    public function renderBadgeSvg(string $token): void
    {
        $result = BadgeService::verifyVolunteerBadge($token);
        $payload = $result['valid'] ? BadgeService::verifyBadgeToken($token) : null;
        if (!$payload) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Срок действия динамического QR-кода истёк, подпись недействительна или волонтёр отозван';
            exit;
        }

        $userId = (int)($payload['vid'] ?? $payload['user_id'] ?? 0);
        $svg = BadgeService::generateBadgeSvg($userId, $token);

        http_response_code(200);
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $svg;
        exit;
    }

    /**
     * Volunteer identity self-verification via external provider (MSI / Gosuslugi)
     * POST /api/volunteers/verify-identity
     */
    public function verifyIdentity(): void
    {
        $auth = AuthService::requireAuth(['volunteer', 'admin']);
        $userId = (int)$auth['user_id'];

        $input = Router::getJsonInput();
        $providerName = (string)($input['provider'] ?? 'msi');
        $personalNumber = (string)($input['personal_number'] ?? '');

        if ($personalNumber === '') {
            Router::json([
                'success' => false,
                'error' => 'MISSING_PARAM',
                'message' => 'Необходимо указать идентификационный номер (личный номер паспорта или СНИЛС)'
            ], 400);
        }

        try {
            $provider = IdentityProviderFactory::get($providerName);
        } catch (\InvalidArgumentException $e) {
            Router::json([
                'success' => false,
                'error' => 'UNKNOWN_PROVIDER',
                'message' => $e->getMessage()
            ], 400);
        }

        $res = $provider->verifyIdentity($personalNumber, $input);
        if (!($res['verified'] ?? false)) {
            Router::json([
                'success' => false,
                'error' => $res['error'] ?? 'VERIFICATION_FAILED',
                'message' => $res['message'] ?? 'Верификация личности не пройдена'
            ], 422);
        }

        // Update profile in database. A fresh identity check always returns the
        // volunteer to moderation; previously granted access must not survive it.
        $vp = VolunteerProfile::find($userId);
        if ($vp) {
            VolunteerProfile::update($userId, [
                'id_provider' => $res['provider'] ?? $providerName,
                'id_provider_user_id' => $res['personal_number_hash'] ?? null,
                'passport_masked' => $res['personal_number_masked'] ?? null,
                'verification_status' => 'PENDING',
                'is_verified' => 0,
            ]);
        }

        Logger::info("Volunteer #{$userId} verified identity via provider '{$providerName}'");

        Router::json([
            'success' => true,
            'message' => 'Идентификация успешно подтверждена внешним провайдером. Профиль передан на модерацию координатору.',
            'verification' => $res
        ]);
    }
}
