<?php

declare(strict_types=1);

/**
 * Authentication hardening regression suite.
 *
 * Covers the issues fixed in the auth hardening change:
 *  - no implicit anonymous admin identity in local/production;
 *  - demo impersonation is opt-in only;
 *  - the root page is a neutral guest screen and does not set a session cookie;
 *  - the explicit passwordless senior demo login works when demo auth is enabled.
 */

require_once __DIR__ . '/../app/Services/AuthService.php';

use App\Services\AuthService;

$baseUrl = rtrim(getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8000', '/');
$passed = 0;
$failed = 0;

function assertAuth(string $name, bool $condition, string $details = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $passed++;
        return;
    }
    $failed++;
    echo "  [FAIL] {$name}" . ($details !== '' ? " - {$details}" : '') . "\n";
}

/**
 * @return array{code:int, headers:string, body:string, json:mixed}
 */
function authRequest(string $method, string $path, ?array $data = null, array $headers = []): array
{
    global $baseUrl;
    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
    ]);
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
    }
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headerText = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    return [
        'code' => $code,
        'headers' => $headerText,
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

echo "========================================================\n";
echo "   AUTH HARDENING & GUEST ENTRY REGRESSION TESTS\n";
echo "========================================================\n\n";

// ---------------------------------------------------------------------
// 1. Unit checks: no implicit identity outside the explicit test fixture.
// ---------------------------------------------------------------------
echo "1. Environment-based auth policy:\n";

$saved = [];
foreach (['APP_ENV', 'ALLOW_DEMO_AUTH', 'ALLOW_ANONYMOUS_ADMIN'] as $key) {
    $saved[$key] = getenv($key);
}

$clearRequestIdentity = static function (): void {
    unset(
        $_SERVER['HTTP_AUTHORIZATION'],
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
        $_SERVER['HTTP_X_AUTH_TOKEN'],
        $_SERVER['HTTP_X_DEMO_USER'],
        $_COOKIE['auth_token']
    );
};

try {
    $clearRequestIdentity();

    putenv('APP_ENV=local');
    putenv('ALLOW_DEMO_AUTH=');
    putenv('ALLOW_ANONYMOUS_ADMIN=');
    assertAuth('Local anonymous request is not an admin', AuthService::getCurrentUser() === null);
    assertAuth('Demo auth is disabled unless explicitly enabled', AuthService::isDemoAuthAllowed() === false);

    putenv('ALLOW_DEMO_AUTH=true');
    assertAuth('Demo auth can be enabled explicitly in local mode', AuthService::isDemoAuthAllowed() === true);

    putenv('APP_ENV=production');
    putenv('ALLOW_DEMO_AUTH=true');
    putenv('ALLOW_ANONYMOUS_ADMIN=true');
    assertAuth('Production ignores the demo-auth flag', AuthService::isDemoAuthAllowed() === false);
    assertAuth('Production anonymous request is not an admin', AuthService::getCurrentUser() === null);
    assertAuth('Production session cookies are always Secure', AuthService::cookieSecure() === true);

    putenv('APP_ENV=local');
    unset($_SERVER['HTTPS']);
    assertAuth('Local HTTP session cookies are not marked Secure', AuthService::cookieSecure() === false);

    putenv('APP_ENV=testing');
    putenv('ALLOW_ANONYMOUS_ADMIN=true');
    $fixture = AuthService::getCurrentUser();
    assertAuth(
        'The explicit integration-test fixture still works',
        is_array($fixture) && ($fixture['role_code'] ?? '') === 'admin'
    );
} finally {
    foreach ($saved as $key => $value) {
        putenv($key . '=' . ($value === false ? '' : $value));
    }
    $clearRequestIdentity();
}

// ---------------------------------------------------------------------
// 2. Guest entry: "/" must not silently create an elderly session.
// ---------------------------------------------------------------------
echo "\n2. Guest entry page:\n";

$guest = authRequest('GET', '/');
$guestCookie = headerValue($guest['headers'], 'Set-Cookie');
assertAuth('Guest page returns HTTP 200', $guest['code'] === 200, "HTTP {$guest['code']}");
assertAuth('Guest page does not set an auth cookie', !str_contains($guestCookie, 'auth_token='));
assertAuth(
    'Guest page offers the explicit senior entry',
    str_contains($guest['body'], 'продолжить без пароля')
);
assertAuth(
    'Guest page offers the staff login',
    str_contains($guest['body'], 'Вход для сотрудников')
);
assertAuth(
    'Guest page does not render a specific senior identity',
    !str_contains($guest['body'], 'Анна Ивановна')
);

// ---------------------------------------------------------------------
// 3. Explicit demo device login (ALLOW_DEMO_AUTH=true in the test env).
// ---------------------------------------------------------------------
echo "\n3. Explicit passwordless demo login:\n";

$deviceLogin = authRequest('POST', '/api/auth/device');
assertAuth('Device login returns HTTP 200 with JSON', $deviceLogin['code'] === 200, "HTTP {$deviceLogin['code']}");
$deviceToken = is_array($deviceLogin['json']) ? ($deviceLogin['json']['token'] ?? '') : '';
assertAuth('Device login returns a token', is_string($deviceToken) && $deviceToken !== '');
assertAuth(
    'Device login resolves to the demo senior',
    ($deviceLogin['json']['user']['role_code'] ?? '') === 'elderly'
);

if ($deviceToken !== '') {
    $me = authRequest('GET', '/api/auth/me', null, ["Authorization: Bearer {$deviceToken}"]);
    assertAuth('Device token authenticates the senior', $me['code'] === 200, "HTTP {$me['code']}");
    assertAuth(
        'Device token keeps the elderly role',
        ($me['json']['user']['role_code'] ?? '') === 'elderly'
    );
}

// ---------------------------------------------------------------------
// 4. Tokens and demo header boundaries.
// ---------------------------------------------------------------------
echo "\n4. Token and demo-header boundaries:\n";

$invalid = authRequest('GET', '/api/auth/me', null, ['Authorization: Bearer invalid.token']);
assertAuth('Invalid bearer token is rejected with 401', $invalid['code'] === 401, "HTTP {$invalid['code']}");

$demo = authRequest('GET', '/api/auth/me', null, ['X-Demo-User: 4']);
assertAuth('Explicit demo header works when demo auth is enabled', $demo['code'] === 200, "HTTP {$demo['code']}");
assertAuth(
    'Explicit demo header resolves to the admin fixture',
    ($demo['json']['user']['role_code'] ?? '') === 'admin'
);

echo "\n--------------------------------------------------------\n";
echo "TEST RESULTS: Total: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================\n";

exit($failed === 0 ? 0 : 1);
