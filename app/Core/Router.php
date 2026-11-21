<?php

declare(strict_types=1);

namespace App\Core;

class Router
{
    private array $routes = [];
    private static string $currentRoute = 'unmatched';

    public function get(string $path, callable|array $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    public function put(string $path, callable|array $handler): void
    {
        $this->addRoute('PUT', $path, $handler);
    }

    public function delete(string $path, callable|array $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    private function addRoute(string $method, string $path, callable|array $handler): void
    {
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = '#^' . $pattern . '$#';
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'pattern' => $pattern,
            'handler' => $handler
        ];
    }

    public function dispatch(string $method, string $uri): void
    {

        $path = parse_url($uri, PHP_URL_PATH);
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        foreach ($this->routes as $route) {
            if ($route['method'] === $method && preg_match($route['pattern'], $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $handler = $route['handler'];
                self::$currentRoute = $route['method'] . ' ' . $route['path'];

                if (is_array($handler)) {
                    [$class, $action] = $handler;
                    $controller = new $class();
                    call_user_func_array([$controller, $action], array_values($params));
                    return;
                }

                call_user_func_array($handler, array_values($params));
                return;
            }
        }

        self::$currentRoute = 'unmatched';
        self::json(['error' => 'Not Found', 'path' => $path], 404);
    }

    public static function json(mixed $data, int $status = 200): void
    {
        try {
            $duration = microtime(true) - (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
            MetricsService::recordRequest(self::$currentRoute, $status, $duration);
        } catch (\Throwable $e) {

        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $corsOrigin = getenv('APP_CORS_ORIGIN') ?: (getenv('APP_ENV') === 'production' ? '' : '*');
        if ($corsOrigin !== '') {
            header('Access-Control-Allow-Origin: ' . $corsOrigin);
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        $allowedHeaders = 'Content-Type, Authorization, X-Auth-Token';
        if (getenv('APP_ENV') !== 'production') {
            $allowedHeaders .= ', X-Demo-User';
        }
        header('Access-Control-Allow-Headers: ' . $allowedHeaders);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(self), microphone=(self), camera=()');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('X-Permitted-Cross-Domain-Policies: none');
        $output = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        header('Content-Length: ' . (string)strlen($output));
        echo $output;
        exit;
    }

    public static function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
