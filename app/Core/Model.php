<?php

namespace App\Core;

use PDO;

abstract class Model
{
    protected static string $table = '';
    protected static string $primaryKey = 'id';

    public static function all(): array
    {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT * FROM " . static::$table . " ORDER BY " . static::$primaryKey . " DESC");
        return $stmt->fetchAll();
    }

    public static function find(int|string $id): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM " . static::$table . " WHERE " . static::$primaryKey . " = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function where(string $column, mixed $value, string $operator = '='): array {
    $allowedOperators = ['=', '!=', '<', '>', '<=', '>=', 'LIKE']; // белый список операторов (для предотвращения sql инъекций)
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) { // + regex
        throw new \InvalidArgumentException("Недопустимое имя колонки {$column}");
    }

    $operator = strtoupper(trim($operator));
    if (!in_array($operator, $allowedOperators, true)) {
        throw new \InvalidArgumentException("Недопустимый оператор {$operator}");
    }

    $db = Database::getConnection();
    $sql = "SELECT * FROM " . static::$table . " WHERE {$column} {$operator} :value";
    $stmt = $db->prepare($sql);
    $stmt->execute(['value' => $value]);

    return $stmt->fetchAll();
}

    public static function create(array $data): int|string
    {
        $db = Database::getConnection();
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));

        $sql = "INSERT INTO " . static::$table . " ({$columns}) VALUES ({$placeholders})";
        $stmt = $db->prepare($sql);
        $stmt->execute($data);

        return $db->lastInsertId();
    }

    public static function update(int|string $id, array $data): bool
    {
        $db = Database::getConnection();
        $fields = '';
        foreach ($data as $key => $val) {
            $fields .= "{$key} = :{$key}, ";
        }
        $fields = rtrim($fields, ', ');

        $sql = "UPDATE " . static::$table . " SET {$fields} WHERE " . static::$primaryKey . " = :primary_id";
        $data['primary_id'] = $id;

        $stmt = $db->prepare($sql);
        return $stmt->execute($data);
    }

    public static function delete(int|string $id): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM " . static::$table . " WHERE " . static::$primaryKey . " = :id");
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
