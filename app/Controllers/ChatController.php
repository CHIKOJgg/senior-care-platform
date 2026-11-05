<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Logger;
use App\Core\Router;
use App\Models\ChatMessage;
use App\Models\HelpRequest;
use App\Models\User;
use Throwable;

class ChatController
{
    public function messages(string $requestId): void
    {
        $messages = ChatMessage::getForRequest((int)$requestId);
        Router::json([
            'success' => true,
            'data' => $messages
        ]);
    }

    public function send(string $requestId): void
    {
        $requestId = (int)$requestId;
        if (!HelpRequest::find($requestId)) {
            Router::json(['error' => 'Заявка не найдена'], 404);
        }

        $input = Router::getJsonInput();
        $senderId = (int)($input['sender_id'] ?? 1);
        $text = trim((string)($input['message'] ?? ''));

        if ($text === '') {
            Router::json(['error' => 'Текст сообщения не может быть пустым'], 400);
        }
        if (!User::find($senderId)) {
            Router::json(['error' => 'Отправитель не найден'], 400);
        }

        try {
            $msgId = ChatMessage::create([
                'request_id' => $requestId,
                'sender_id' => $senderId,
                'message_text' => $text,
                'is_read' => 0
            ]);
        } catch (Throwable $e) {
            Logger::error('Chat message insert failed', [
                'request_id' => $requestId,
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
