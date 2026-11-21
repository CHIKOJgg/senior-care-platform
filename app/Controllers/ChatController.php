<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Router;
use App\Models\ChatMessage;
use App\Models\HelpRequest;
use App\Services\AuthService;
use Throwable;

class ChatController
{
    public function messages(string $requestId): void
    {
        $reqId = (int)$requestId;
        $req = HelpRequest::find($reqId);
        if (!$req) {
            Router::json(['error' => 'Заявка не найдена'], 404);
        }

        $currentUser = AuthService::getCurrentUser();
        if ($currentUser) {
            $uid = (int)$currentUser['user_id'];
            $role = $currentUser['role_code'] ?? '';
            $isParticipant = ($uid === (int)$req['elderly_id'])
                || ($uid === (int)($req['volunteer_id'] ?? 0))
                || in_array($role, ['admin', 'coordinator'], true);

            if (!$isParticipant) {
                Router::json(['error' => 'Доступ к сообщениям данной заявки ограничен участниками'], 403);
            }
        } elseif (AuthService::isProduction()) {
            AuthService::requireAuth();
        }

        $messages = ChatMessage::getForRequest($reqId);
        Router::json([
            'success' => true,
            'data' => $messages
        ]);
    }

    public function send(string $requestId): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!RateLimiter::check("chat_send:{$ip}", 60, 60)) {
            Router::json(['error' => 'Слишком много сообщений. Подождите минуту.'], 429);
        }

        $reqId = (int)$requestId;
        $req = HelpRequest::find($reqId);
        if (!$req) {
            Router::json(['error' => 'Заявка не найдена'], 404);
        }

        $input = Router::getJsonInput();
        $currentUser = AuthService::getCurrentUser();

        if ($currentUser) {
            $senderId = (int)$currentUser['user_id'];
            $role = $currentUser['role_code'] ?? '';
            $isParticipant = ($senderId === (int)$req['elderly_id'])
                || ($senderId === (int)($req['volunteer_id'] ?? 0))
                || in_array($role, ['admin', 'coordinator'], true);

            if (!$isParticipant) {
                Router::json(['error' => 'Доступ запрещён: отправлять сообщения могут только участники заявки'], 403);
            }
        } elseif (AuthService::isProduction()) {
            AuthService::requireAuth();
            return;
        } else {

            $senderId = (int)($input['sender_id'] ?? 1);
        }

        $text = trim((string)($input['message'] ?? ''));

        if ($text === '') {
            Router::json(['error' => 'Текст сообщения не может быть пустым'], 400);
        }

        try {
            $msgId = ChatMessage::create([
                'request_id' => $reqId,
                'sender_id' => $senderId,
                'message_text' => $text,
                'is_read' => 0
            ]);
        } catch (Throwable $e) {
            Logger::error('Chat message insert failed', [
                'request_id' => $reqId,
                'sender_id' => $senderId,
                'error' => $e->getMessage()
            ]);
            Router::json(['error' => 'Не удалось отправить сообщение'], 500);
        }

        Router::json([
            'success' => true,
            'message_id' => $msgId,
            'created_at' => date('Y-m-d H:i:s')
        ], 201);
    }
}
