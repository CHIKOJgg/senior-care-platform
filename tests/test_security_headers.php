<?php

declare(strict_types=1);

$baseUrl = rtrim(getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8000', '/');

function request(string $method, string $path): array
{
    global $baseUrl;
    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $raw = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headers = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    return [
        'status' => $status,
        'headers' => $headers,
        'body' => $body,
        'json' => json_decode($body, true),
    ];
}

function headerValue(string $headers, string $name): string
{
    foreach (preg_split('/\r?\n/', $headers) as $line) {
        if (stripos($line, $name . ':') === 0) {
            return trim(substr($line, strlen($name) + 1));
        }
    }
    return '';
}

$passed = 0;
$failed = 0;

function assertHeader(string $name, bool $condition, string $details = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$name}\n";
        return;
    }
    $failed++;
    echo "  [FAIL] {$name}" . ($details !== '' ? " - {$details}" : '') . "\n";
}

echo "========================================================\n";
echo "   API SECURITY HEADERS & ERROR CONTRACT TESTS\n";
echo "========================================================\n\n";

$health = request('GET', '/api/health');
assertHeader('health returns 200', $health['status'] === 200, "HTTP {$health['status']}");
assertHeader('content-type is JSON', stripos(headerValue($health['headers'], 'Content-Type'), 'application/json') !== false);
assertHeader('X-Content-Type-Options is nosniff', strtolower(headerValue($health['headers'], 'X-Content-Type-Options')) === 'nosniff');
assertHeader('X-Frame-Options is SAMEORIGIN', strtoupper(headerValue($health['headers'], 'X-Frame-Options')) === 'SAMEORIGIN');
assertHeader('Cache-Control disables caching', stripos(headerValue($health['headers'], 'Cache-Control'), 'no-store') !== false);

$notFound = request('GET', '/api/this-route-does-not-exist');
assertHeader('unknown API route returns 404', $notFound['status'] === 404, "HTTP {$notFound['status']}");
assertHeader('unknown API route returns JSON', is_array($notFound['json']));
assertHeader('unknown API route contains error', isset($notFound['json']['error']));
assertHeader('unknown API route uses no-store', stripos(headerValue($notFound['headers'], 'Cache-Control'), 'no-store') !== false);

$options = request('OPTIONS', '/api/health');
assertHeader('OPTIONS returns 200', $options['status'] === 200, "HTTP {$options['status']}");

echo "\n--------------------------------------------------------\n";
echo "TEST RESULTS: Total: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================\n";

exit($failed === 0 ? 0 : 1);
