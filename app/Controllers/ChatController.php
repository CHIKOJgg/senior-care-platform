<?php

namespace App\Controllers;

use App\Core\Router;
use App\Models\ChatMessage;

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
        $input = Router::getJsonInput();
        $senderId = (int)($input['sender_id'] ?? 1);
        $text = trim($input['message'] ?? '');

        if (!$text) {
            Router::json(['error' => 'Текст сообщения не может быть пустым'], 400);
        }

        $msgId = ChatMessage::create([
            'request_id' => (int)$requestId,
            'sender_id' => $senderId,
            'message_text' => $text,
            'is_read' => 0
        ]);

        Router::json([
            'success' => true,
            'message_id' => $msgId,
            'created_at' => date('Y-m-d H:i:s')
        ], 201);
    }
}
