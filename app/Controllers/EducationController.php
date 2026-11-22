<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Router;
use App\Core\Logger;
use App\Services\AuthService;
use App\Models\EducationalMaterial;

class EducationController
{
    public function index(): void
    {
        $category = $_GET['category'] ?? null;
        $type = $_GET['type'] ?? null;
        $materials = EducationalMaterial::all();
        if ($category !== null) {
            $materials = array_values(array_filter($materials, fn($m) => ($m['category'] ?? '') === $category));
        }
        if ($type !== null) {
            $materials = array_values(array_filter($materials, fn($m) => ($m['type'] ?? '') === $type));
        }
        Router::json([
            'success' => true,
            'count' => count($materials),
            'data' => $materials
        ]);
    }

    public function show(string $id): void
    {
        $m = EducationalMaterial::find((int)$id);
        if (!$m) {
            Router::json(['error' => 'Материал не найден'], 404);
        }
        Router::json(['success' => true, 'data' => $m]);
    }

    public function create(): void
    {
        $admin = AuthService::requireAuth(['admin', 'coordinator']);
        $input = Router::getJsonInput();
        $title = trim((string)($input['title'] ?? ''));
        $type = trim((string)($input['type'] ?? 'memo'));
        $category = trim((string)($input['category'] ?? 'Общее'));
        $content = trim((string)($input['content'] ?? ''));

        if ($title === '' || $content === '') {
            Router::json(['error' => 'Укажите title и content'], 400);
        }
        if (!in_array($type, ['memo', 'audio', 'video'], true)) {
            Router::json(['error' => 'Недопустимый type (memo/audio/video)'], 400);
        }
        if (mb_strlen($title) > 255 || mb_strlen($category) > 100 || mb_strlen($content) > 20000) {
            Router::json(['error' => 'Слишком длинный заголовок, категория или текст материала'], 400);
        }
        $audioUrl = isset($input['audio_url']) ? trim((string)$input['audio_url']) : null;
        if ($audioUrl !== null && mb_strlen($audioUrl) > 255) {
            Router::json(['error' => 'Ссылка на аудио слишком длинная'], 400);
        }

        $id = EducationalMaterial::create([
            'title' => $title,
            'type' => $type,
            'category' => $category,
            'content' => $content,
            'audio_url' => $audioUrl
        ]);
        Logger::info("Educational material #{$id} created by Admin #{$admin['user_id']}");
        Router::json(['success' => true, 'material_id' => $id], 201);
    }

    public function update(string $id): void
    {
        AuthService::requireAuth(['admin', 'coordinator']);
        $mid = (int)$id;
        $m = EducationalMaterial::find($mid);
        if (!$m) {
            Router::json(['error' => 'Материал не найден'], 404);
        }
        $input = Router::getJsonInput();
        $data = [];
        foreach (['title', 'type', 'category', 'content', 'audio_url'] as $f) {
            if (isset($input[$f])) {
                $data[$f] = is_string($input[$f]) ? trim($input[$f]) : $input[$f];
            }
        }
        if (empty($data)) {
            Router::json(['error' => 'Нет данных для обновления'], 400);
        }
        if (isset($data['type']) && !in_array($data['type'], ['memo', 'audio', 'video'], true)) {
            Router::json(['error' => 'Недопустимый type (memo/audio/video)'], 400);
        }
        if (isset($data['title']) && ($data['title'] === '' || mb_strlen($data['title']) > 255)) {
            Router::json(['error' => 'Заголовок (title) обязателен и не должен превышать 255 символов'], 400);
        }
        if (isset($data['content']) && ($data['content'] === '' || mb_strlen($data['content']) > 20000)) {
            Router::json(['error' => 'Текст (content) обязателен и не должен превышать 20000 символов'], 400);
        }
        if (isset($data['category']) && mb_strlen($data['category']) > 100) {
            Router::json(['error' => 'Категория слишком длинная'], 400);
        }
        if (isset($data['audio_url']) && $data['audio_url'] !== null && mb_strlen((string)$data['audio_url']) > 255) {
            Router::json(['error' => 'Ссылка на аудио слишком длинная'], 400);
        }
        EducationalMaterial::update($mid, $data);
        Router::json(['success' => true, 'message' => 'Материал обновлён']);
    }

    public function delete(string $id): void
    {
        AuthService::requireAuth(['admin', 'coordinator']);
        $mid = (int)$id;
        $m = EducationalMaterial::find($mid);
        if (!$m) {
            Router::json(['error' => 'Материал не найден'], 404);
        }
        EducationalMaterial::delete($mid);
        Router::json(['success' => true, 'message' => 'Материал удалён']);
    }
}
