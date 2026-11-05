<?php

declare(strict_types=1);

namespace App\Core;

class Logger
{
    private static string $logFile = __DIR__ . '/../../storage/logs/app.log';

    public static function log(string $level, string $message, array $context = []): void
    {
        $dir = dirname(self::$logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $uri = $_SERVER['REQUEST_URI'] ?? '-';
        $method = $_SERVER['REQUEST_METHOD'] ?? '-';

        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $entry = sprintf("[%s] [%s] [%s %s] [IP: %s] %s%s\n", $timestamp, strtoupper($level), $method, $uri, $ip, $message, $contextStr);

        @file_put_contents(self::$logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context);
    }

    public static function security(string $message, array $context = []): void
    {
        self::log('SECURITY', $message, $context);
    }
}
