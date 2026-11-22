<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use InvalidArgumentException;

abstract class Model
{
    protected static string $table = '';
    protected static string $primaryKey = 'id';

    public static function all(): array
    {
        $db = Database::getConnection();
        $table = static::$table;
        $pk = static::$primaryKey;
        $stmt = $db->query("SELECT * FROM {$table} ORDER BY {$pk} DESC");
        return $stmt->fetchAll();
    }

    public static function find(int|string $id): ?array
    {
        $db = Database::getConnection();
        $table = static::$table;
        $pk = static::$primaryKey;
        $stmt = $db->prepare("SELECT * FROM {$table} WHERE {$pk} = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function where(string $column, mixed $value, string $operator = '='): array
    {
        // Sanitize column name to allow only safe identifier characters
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new InvalidArgumentException("Invalid column name: {$column}");
        }

        $allowedOperators = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE'];
        if (!in_array(strtoupper($operator), $allowedOperators, true)) {
            throw new InvalidArgumentException("Invalid operator: {$operator}");
        }

        $db = Database::getConnection();
        $table = static::$table;
        $stmt = $db->prepare("SELECT * FROM {$table} WHERE {$column} {$operator} :value");
        $stmt->execute(['value' => $value]);
        return $stmt->fetchAll();
    }

    public static function create(array $data): int|string
    {
        $db = Database::getConnection();
        $table = static::$table;
        $columns = [];
        $placeholders = [];
        $params = [];

        foreach ($data as $key => $val) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                continue;
            }
            $columns[] = $key;
            $placeholders[] = ':' . $key;
            $params[$key] = $val;
        }

        $colsStr = implode(', ', $columns);
        $placeStr = implode(', ', $placeholders);

        $sql = "INSERT INTO {$table} ({$colsStr}) VALUES ({$placeStr})";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $db->lastInsertId();
    }

    public static function update(int|string $id, array $data): bool
    {
        $db = Database::getConnection();
        $table = static::$table;
        $pk = static::$primaryKey;
        $fields = '';
        $params = ['primary_id' => $id];

        foreach ($data as $key => $val) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                continue;
            }
            $fields .= "{$key} = :{$key}, ";
            $params[$key] = $val;
        }
        $fields = rtrim($fields, ', ');

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE {$table} SET {$fields} WHERE {$pk} = :primary_id";
        $stmt = $db->prepare($sql);
        return $stmt->execute($params);
    }

    public static function delete(int|string $id): bool
    {
        $db = Database::getConnection();
        $table = static::$table;
        $pk = static::$primaryKey;
        $stmt = $db->prepare("DELETE FROM {$table} WHERE {$pk} = :id");
        return $stmt->execute(['id' => $id]);
    }

    public static function query(string $sql, array $params = []): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
