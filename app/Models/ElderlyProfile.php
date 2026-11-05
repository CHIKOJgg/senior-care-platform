<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Services\CryptoService;

class ElderlyProfile extends Model
{
    protected static string $table = 'elderly_profiles';
    protected static string $primaryKey = 'user_id';

    public static function find(int|string $id): ?array
    {
        $row = parent::find($id);
        return $row ? self::decryptProfile($row) : null;
    }

    public static function all(): array
    {
        $rows = parent::all();
        foreach ($rows as &$row) {
            $row = self::decryptProfile($row);
        }
        return $rows;
    }

    public static function where(string $column, mixed $value, string $operator = '='): array
    {
        $rows = parent::where($column, $value, $operator);
        foreach ($rows as &$row) {
            $row = self::decryptProfile($row);
        }
        return $rows;
    }

    public static function create(array $data): int|string
    {
        $data = self::encryptProfile($data);
        return parent::create($data);
    }

    public static function update(int|string $id, array $data): bool
    {
        $data = self::encryptProfile($data);
        return parent::update($id, $data);
    }

    private static function encryptProfile(array $data): array
    {
        if (isset($data['mobility_notes']) && $data['mobility_notes'] !== null && $data['mobility_notes'] !== '') {
            $data['mobility_notes'] = CryptoService::encrypt((string)$data['mobility_notes']);
        }
        return $data;
    }

    private static function decryptProfile(array $data): array
    {
        if (isset($data['mobility_notes']) && $data['mobility_notes'] !== null && $data['mobility_notes'] !== '') {
            try {
                $data['mobility_notes'] = CryptoService::decrypt((string)$data['mobility_notes']);
            } catch (\Throwable $e) {
                $data['mobility_notes'] = null;
            }
        }
        return $data;
    }
}
