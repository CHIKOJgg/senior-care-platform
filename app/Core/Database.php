<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $driver = getenv('DB_CONNECTION') ?: 'sqlite';

            try {
                if ($driver === 'sqlite') {
                    $dbFile = getenv('DB_DATABASE') ?: 'database/database.sqlite';
                    $isAbsolute = str_starts_with($dbFile, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $dbFile) === 1;
                    $dbPath = $isAbsolute ? $dbFile : __DIR__ . '/../../' . ltrim($dbFile, '/\\');
                    $dir = dirname($dbPath);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0777, true);
                    }
                    self::$instance = new PDO('sqlite:' . $dbPath);
                    self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                    self::$instance->exec('PRAGMA foreign_keys = ON;');
                    self::$instance->exec('PRAGMA journal_mode = WAL;');
                    self::$instance->exec('PRAGMA busy_timeout = 5000;');
                    self::$instance->exec('PRAGMA synchronous = NORMAL;');
                } else {
                    $host = getenv('DB_HOST') ?: '127.0.0.1';
                    $port = getenv('DB_PORT') ?: '3306';
                    $dbname = getenv('DB_DATABASE') ?: 'senior_care_db';
                    $user = getenv('DB_USERNAME') ?: 'root';
                    $pass = getenv('DB_PASSWORD') ?: '';

                    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
                    self::$instance = new PDO($dsn, $user, $pass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]);
                }
            } catch (PDOException $e) {
                error_log('Database connection error: ' . $e->getMessage());
                throw new RuntimeException('Database connection failed: ' . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }

        return self::$instance;
    }

    public static function resetConnection(): void
    {
        self::$instance = null;
    }
}
