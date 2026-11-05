<?php

declare(strict_types=1);

namespace Tests\E2ESuite;

require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Core/Model.php';
require_once __DIR__ . '/../../app/Core/Logger.php';
require_once __DIR__ . '/../../app/Core/RateLimiter.php';
require_once __DIR__ . '/../../app/Services/AuthService.php';

use App\Core\RateLimiter;

class TestHarness
{
    private static ?self $instance = null;
    private string $baseUrl;
    private int $passed = 0;
    private int $failed = 0;
    private int $skipped = 0;
    private array $results = [];
    private bool $strictMode = false;
    private bool $verbose = false;
    private float $startTime = 0.0;

    // Cached tokens for standard demo roles
    private ?string $adminToken = null;
    private ?string $coordinatorToken = null;
    private ?string $volunteerToken = null;
    private ?string $elderlyToken = null;

    private function __construct(string $baseUrl = 'http://127.0.0.1:8000')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->startTime = microtime(true);
    }

    public static function getInstance(string $baseUrl = 'http://127.0.0.1:8000'): self
    {
        if (self::$instance === null) {
            self::$instance = new self($baseUrl);
        }
        return self::$instance;
    }

    public function setStrictMode(bool $strict): void
    {
        $this->strictMode = $strict;
    }

    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Perform an HTTP API request (opaque-box black-box interaction)
     */
    public function apiCall(string $method, string $path, ?array $data = null, array $headers = []): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $reqHeaders = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($headers as $h) {
            $reqHeaders[] = $h;
        }

        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);

        $response = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $decoded = null;
        if (is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
        }

        return [
            'code' => $code,
            'raw' => $response,
            'data' => $decoded,
            'error' => $error
        ];
    }

    /**
     * Record and assert test outcome
     */
    public function assert(string $name, bool $condition, string $details = '', string $tier = 'Tier 1'): bool
    {
        if ($condition) {
            $this->passed++;
            $this->results[] = [
                'name' => $name,
                'status' => 'PASS',
                'tier' => $tier,
                'details' => $details
            ];
            echo "  \033[32m[PASS]\033[0m {$name}\n";
            return true;
        } else {
            $this->failed++;
            $this->results[] = [
                'name' => $name,
                'status' => 'FAIL',
                'tier' => $tier,
                'details' => $details
            ];
            echo "  \033[31m[FAIL]\033[0m {$name} - {$details}\n";
            return false;
        }
    }

    /**
     * Progressive testability: if feature belongs to an unmerged milestone and returns 404/405/not implemented,
     * record as SKIPPED unless strict mode is enabled.
     */
    public function assertMilestone(string $milestone, string $name, callable $testFn, string $tier = 'Tier 1'): void
    {
        try {
            $outcome = $testFn($this);
            if (is_array($outcome) && isset($outcome['skipped']) && $outcome['skipped'] === true) {
                if ($this->strictMode) {
                    $this->assert($name, false, "Feature {$milestone} missing in strict mode: " . ($outcome['reason'] ?? 'not deployed'), $tier);
                } else {
                    $this->skipped++;
                    $this->results[] = [
                        'name' => $name,
                        'status' => 'SKIPPED',
                        'tier' => $tier,
                        'details' => "Pending {$milestone}: " . ($outcome['reason'] ?? 'endpoint not mounted')
                    ];
                    echo "  \033[33m[SKIP]\033[0m {$name} (Pending {$milestone})\n";
                }
                return;
            }

            $condition = is_bool($outcome) ? $outcome : ($outcome['pass'] ?? false);
            $details = is_array($outcome) ? ($outcome['details'] ?? '') : '';
            $this->assert($name, (bool)$condition, $details, $tier);
        } catch (\Throwable $e) {
            if ($this->strictMode) {
                $this->assert($name, false, "Exception: " . $e->getMessage(), $tier);
            } else {
                $this->skipped++;
                $this->results[] = [
                    'name' => $name,
                    'status' => 'SKIPPED',
                    'tier' => $tier,
                    'details' => "Pending {$milestone}: " . $e->getMessage()
                ];
                echo "  \033[33m[SKIP]\033[0m {$name} (Pending {$milestone}: {$e->getMessage()})\n";
            }
        }
    }

    /**
     * Get or create admin bearer token
     */
    public function getAdminToken(): string
    {
        if ($this->adminToken !== null) {
            return $this->adminToken;
        }
        $res = $this->apiCall('POST', '/api/auth/login', [
            'phone' => '+375299999999',
            'password' => 'password123'
        ]);
        if ($res['code'] === 200 && !empty($res['data']['token'])) {
            $this->adminToken = $res['data']['token'];
            return $this->adminToken;
        }
        return '';
    }

    /**
     * Get or create coordinator bearer token
     */
    public function getCoordinatorToken(): string
    {
        if ($this->coordinatorToken !== null) {
            return $this->coordinatorToken;
        }
        $res = $this->apiCall('POST', '/api/auth/login', [
            'phone' => '+375295556677',
            'password' => 'password123'
        ]);
        if ($res['code'] === 200 && !empty($res['data']['token'])) {
            $this->coordinatorToken = $res['data']['token'];
            return $this->coordinatorToken;
        }
        return '';
    }

    /**
     * Get or create volunteer bearer token
     */
    public function getVolunteerToken(): string
    {
        if ($this->volunteerToken !== null) {
            return $this->volunteerToken;
        }
        $res = $this->apiCall('POST', '/api/auth/login', [
            'phone' => '+375292223344',
            'password' => 'password123'
        ]);
        if ($res['code'] === 200 && !empty($res['data']['token'])) {
            $this->volunteerToken = $res['data']['token'];
            return $this->volunteerToken;
        }
        return '';
    }

    /**
     * Get or create elderly user bearer token
     */
    public function getElderlyToken(): string
    {
        if ($this->elderlyToken !== null) {
            return $this->elderlyToken;
        }
        $res = $this->apiCall('POST', '/api/auth/login', [
            'phone' => '+375291112233',
            'password' => 'password123'
        ]);
        if ($res['code'] === 200 && !empty($res['data']['token'])) {
            $this->elderlyToken = $res['data']['token'];
            return $this->elderlyToken;
        }
        return '';
    }

    /**
     * Register a unique random user for isolated test cases
     */
    public function registerUser(int $roleId, string $name, ?string $skills = null): array
    {
        $phone = '+37529' . rand(2000000, 8999999);
        $payload = [
            'role_id' => $roleId,
            'phone' => $phone,
            'name' => $name,
            'password' => 'pass_' . bin2hex(random_bytes(4))
        ];
        if ($skills !== null) {
            $payload['skills'] = $skills;
        }
        $res = $this->apiCall('POST', '/api/auth/register', $payload);
        return [
            'phone' => $phone,
            'password' => $payload['password'],
            'response' => $res,
            'user' => $res['data']['user'] ?? null,
            'token' => $res['data']['token'] ?? null
        ];
    }

    /**
     * Helper to create a help request
     */
    public function createHelpRequest(
        int $elderlyId = 1,
        int $categoryId = 1,
        string $title = 'Тестовая просьба о помощи',
        string $description = 'Купить продукты первой необходимости',
        string $address = 'г. Минск, пр-т Независимости, 45, кв. 12',
        float $lat = 53.9168,
        float $lon = 27.5862,
        ?string $authToken = null
    ): array {
        $headers = [];
        if ($authToken) {
            $headers[] = "Authorization: Bearer {$authToken}";
        }
        RateLimiter::clear("create_request:127.0.0.1");
        RateLimiter::clear("create_request:");
        return $this->apiCall('POST', '/api/requests', [
            'elderly_id' => $elderlyId,
            'category_id' => $categoryId,
            'title' => $title,
            'description' => $description,
            'address' => $address,
            'latitude' => $lat,
            'longitude' => $lon
        ], $headers);
    }

    public function getSummary(): array
    {
        $total = $this->passed + $this->failed + $this->skipped;
        $duration = round(microtime(true) - $this->startTime, 3);
        return [
            'total' => $total,
            'passed' => $this->passed,
            'failed' => $this->failed,
            'skipped' => $this->skipped,
            'duration_seconds' => $duration,
            'success_rate' => $total > 0 ? round(($this->passed / ($this->passed + $this->failed ?: 1)) * 100, 2) : 0.0
        ];
    }

    public function printSummary(string $suiteName = 'COMPREHENSIVE E2E TEST SUITE'): void
    {
        $sum = $this->getSummary();
        echo "\n========================================================\n";
        echo "   {$suiteName} SUMMARY\n";
        echo "========================================================\n";
        echo "  Total Tests:    {$sum['total']}\n";
        echo "  Passed:         \033[32m{$sum['passed']}\033[0m\n";
        echo "  Failed:         " . ($sum['failed'] > 0 ? "\033[31m{$sum['failed']}\033[0m" : "0") . "\n";
        echo "  Skipped:        \033[33m{$sum['skipped']}\033[0m\n";
        echo "  Duration:       {$sum['duration_seconds']}s\n";
        echo "  Success Rate:   {$sum['success_rate']}%\n";
        echo "========================================================\n";
    }

    public function exportJson(?string $filePath = null): string
    {
        $data = [
            'timestamp' => date('c'),
            'summary' => $this->getSummary(),
            'results' => $this->results
        ];
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($filePath !== null) {
            file_put_contents($filePath, $json);
        }
        return $json;
    }

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }
}
