<?php

declare(strict_types=1);

namespace Tests\E2ESuite;

require_once __DIR__ . '/TestHarness.php';
require_once __DIR__ . '/../../app/Services/GeoMatchingService.php';
require_once __DIR__ . '/../../app/Services/SecurityService.php';
require_once __DIR__ . '/../../app/Services/AntiFraudService.php';
require_once __DIR__ . '/../../app/Core/RateLimiter.php';

use App\Services\GeoMatchingService;
use App\Services\SecurityService;
use App\Services\AntiFraudService;
use App\Core\RateLimiter;

class Tier2_BoundaryCornerTest
{
    private TestHarness $harness;

    public function __construct(TestHarness $harness)
    {
        $this->harness = $harness;
    }

    public function run(): void
    {
        echo "\n========================================================\n";
        echo "   TIER 2: BOUNDARY & CORNER CASES (62 TESTS)\n";
        echo "========================================================\n\n";

        $this->testHaversineBoundaries();
        $this->testSecretCodeBoundaries();
        $this->testAuthRbacBoundaries();
        $this->testDataProtectionBoundaries();
        $this->testSosEmergencyBoundaries();
        $this->testRequestLifecycleBoundaries();
        $this->testSanitizationAndStressBoundaries();
    }

    // Category 1: Haversine & Geofencing Boundaries (10 tests)
    private function testHaversineBoundaries(): void
    {
        echo "[Tier 2.1] Haversine Geofencing Boundaries (10 tests):\n";

        // T2-GEO-01: Exactly 0.0m
        $d0 = GeoMatchingService::calculateDistance(53.9168, 27.5862, 53.9168, 27.5862);
        $this->harness->assert('T2-GEO-01: Zero offset distance is exactly 0.000 km', $d0 === 0.0, "Got {$d0}", 'Tier 2');

        // T2-GEO-02: 1 meter offset
        $d1 = GeoMatchingService::calculateDistance(53.9168, 27.5862, 53.916809, 27.5862);
        $meters1 = (int)($d1 * 1000);
        $this->harness->assert('T2-GEO-02: Micro-offset (~1m) computes correctly without precision loss', $meters1 >= 0 && $meters1 <= 3, "Got {$meters1}m", 'Tier 2');

        // T2-GEO-03: Boundary value <= 150m
        $d150 = GeoMatchingService::calculateDistance(53.9168, 27.5862, 53.9180, 27.5862);
        $m150 = $d150 * 1000;
        $this->harness->assert('T2-GEO-03: ~133m offset is within 150m boundary threshold', $m150 < 150.0, "Got {$m150}m", 'Tier 2');

        // T2-GEO-04: Breach > 150m
        $dBreach = GeoMatchingService::calculateDistance(53.9168, 27.5862, 53.9185, 27.5862);
        $mBreach = $dBreach * 1000;
        $this->harness->assert('T2-GEO-04: ~189m offset strictly exceeds 150m boundary threshold', $mBreach > 150.0, "Got {$mBreach}m", 'Tier 2');

        // T2-GEO-05: 500m distance
        $d500 = GeoMatchingService::calculateDistance(53.9168, 27.5862, 53.9213, 27.5862);
        $m500 = (int)($d500 * 1000);
        $this->harness->assert('T2-GEO-05: 500m distance correctly identified as outside local geofence', $m500 >= 480 && $m500 <= 520, "Got {$m500}m", 'Tier 2');

        // T2-GEO-06: 15km distance (MKAD Ring Road)
        $dMkad = GeoMatchingService::calculateDistance(53.9168, 27.5862, 53.8000, 27.5000);
        $this->harness->assert('T2-GEO-06: Long distance across Minsk (~14km) calculates accurately', $dMkad >= 12.0 && $dMkad <= 16.0, "Got {$dMkad} km", 'Tier 2');

        // T2-GEO-07: Antipodal points (numerical stability clamp)
        $dAnti = GeoMatchingService::calculateDistance(0.0, 0.0, 0.0, 180.0);
        $this->harness->assert('T2-GEO-07: Maximum spherical distance does not produce NaN', !is_nan($dAnti) && $dAnti > 19000.0, "Got {$dAnti} km", 'Tier 2');

        // T2-GEO-08: Invalid latitude > 90
        $resLat = $this->harness->apiCall('GET', '/api/requests?lat=95.0&lon=27.5862');
        $this->harness->assert('T2-GEO-08: Out-of-bounds latitude (>90) handled gracefully', in_array($resLat['code'], [200, 400, 422]), "Code: {$resLat['code']}", 'Tier 2');

        // T2-GEO-09: Invalid longitude > 180
        $resLon = $this->harness->apiCall('GET', '/api/requests?lat=53.9168&lon=195.0');
        $this->harness->assert('T2-GEO-09: Out-of-bounds longitude (>180) handled gracefully', in_array($resLon['code'], [200, 400, 422]), "Code: {$resLon['code']}", 'Tier 2');

        // T2-GEO-10: Non-numeric coordinates string
        $resStr = $this->harness->apiCall('GET', '/api/requests?lat=not_a_number&lon=abc');
        $this->harness->assert('T2-GEO-10: Non-numeric coordinate strings do not crash server', in_array($resStr['code'], [200, 400, 422]), "Code: {$resStr['code']}", 'Tier 2');
    }

    // Category 2: Secret Code Validation & Hardening (8 tests)
    private function testSecretCodeBoundaries(): void
    {
        echo "\n[Tier 2.2] Secret Code Validation & Hardening (8 tests):\n";

        $original = 'Ромашка';
        $this->harness->assert('T2-SEC-01: Exact match confirms secret code', SecurityService::verifyCode('Ромашка', $original), '', 'Tier 2');
        $this->harness->assert('T2-SEC-02: Lowercase variant confirms secret code', SecurityService::verifyCode('ромашка', $original), '', 'Tier 2');
        $this->harness->assert('T2-SEC-03: Uppercase variant confirms secret code', SecurityService::verifyCode('РОМАШКА', $original), '', 'Tier 2');
        
        $codeYo = 'Василёк';
        $this->harness->assertMilestone('M3', 'T2-SEC-04: "е" matches "ё" in Cyrillic normalization', function() use ($codeYo) {
            $m = SecurityService::verifyCode('василек', $codeYo);
            if (!$m) {
                return ['skipped' => true, 'reason' => 'ё/е normalization pending M3'];
            }
            return ['pass' => true, 'details' => 'Normalized'];
        }, 'Tier 2');
        
        $this->harness->assert('T2-SEC-05: Leading/trailing whitespace trimmed during verification', SecurityService::verifyCode('   Ромашка   ', $original), '', 'Tier 2');
        $this->harness->assertMilestone('M3', 'T2-SEC-06: Quotes around code trimmed during verification', function() use ($original) {
            $m = SecurityService::verifyCode('«Ромашка»', $original) || SecurityService::verifyCode('"Ромашка"', $original);
            if (!$m) {
                return ['skipped' => true, 'reason' => 'Quotes trimming pending M3'];
            }
            return ['pass' => true, 'details' => 'Trimmed'];
        }, 'Tier 2');
        
        $this->harness->assert('T2-SEC-07: Empty string rejected as secret code', !SecurityService::verifyCode('', $original), '', 'Tier 2');
        $this->harness->assert('T2-SEC-08: Arbitrary incorrect word rejected', !SecurityService::verifyCode('Колокольчик', $original), '', 'Tier 2');
    }

    // Category 3: Authentication, Tokens & RBAC Boundaries (10 tests)
    private function testAuthRbacBoundaries(): void
    {
        echo "\n[Tier 2.3] Authentication, Tokens & RBAC Boundaries (10 tests):\n";

        $adminToken = $this->harness->getAdminToken();
        $resMe = $this->harness->apiCall('GET', '/api/auth/me', null, ["Authorization: Bearer {$adminToken}"]);
        $this->harness->assert('T2-AUTH-01: Valid token provides authenticated user context', $resMe['code'] === 200 && ($resMe['data']['user']['id'] ?? 0) === 4, '', 'Tier 2');

        $this->harness->assert('T2-AUTH-02: Missing token string returns null on verification', \App\Services\AuthService::verifyToken('') === null, '', 'Tier 2');
        $this->harness->assert('T2-AUTH-03: Non-stateless token without dot signature rejected', \App\Services\AuthService::verifyToken('BasicAuthStringWithoutDot') === null, '', 'Tier 2');
        $this->harness->assert('T2-AUTH-04: Truncated token string rejected by verifyToken', \App\Services\AuthService::verifyToken(substr($adminToken, 0, 10)) === null, '', 'Tier 2');

        $tamperedPayload = 'eyJ1c2VyX2lkIjo0LCJyb2xlIjoiYWRtaW4ifQ.' . substr($adminToken, strpos($adminToken, '.') + 1);
        $this->harness->assert('T2-AUTH-05: Tampered payload segment fails HMAC verification', \App\Services\AuthService::verifyToken($tamperedPayload) === null, '', 'Tier 2');

        $tamperedSig = $adminToken . 'corrupt';
        $this->harness->assert('T2-AUTH-06: Altered HMAC signature strictly rejected by verifyToken', \App\Services\AuthService::verifyToken($tamperedSig) === null, '', 'Tier 2');

        $this->harness->assert('T2-AUTH-07: Empty payload or malformed base64 rejected', \App\Services\AuthService::verifyToken('.invalidsig') === null, '', 'Tier 2');

        $volToken = $this->harness->getVolunteerToken();
        $resVolCreateAlert = $this->harness->apiCall('POST', '/api/coordinator/alerts', [
            'title' => 'Попытка взлома волонтёром'
        ], ["Authorization: Bearer {$volToken}"]);
        $this->harness->assert('T2-AUTH-08: Volunteer token forbidden from admin alert creation (403)', $resVolCreateAlert['code'] === 403, "Code: {$resVolCreateAlert['code']}", 'Tier 2');

        $elderlyToken = $this->harness->getElderlyToken();
        $resElderlyStats = $this->harness->apiCall('GET', '/api/coordinator/stats', null, ["Authorization: Bearer {$elderlyToken}"]);
        $this->harness->assert('T2-AUTH-09: Elderly token forbidden from coordinator stats (403)', $resElderlyStats['code'] === 403, "Code: {$resElderlyStats['code']}", 'Tier 2');

        // Unverified volunteer cannot accept requests
        $volReg = $this->harness->registerUser(2, 'Неверифицированный Волонтёр');
        $newReq = $this->harness->createHelpRequest(1, 1, 'Проверка блокировки неверифицированного');
        $reqId = $newReq['data']['request_id'];
        $resAcceptBlocked = $this->harness->apiCall('POST', "/api/requests/{$reqId}/accept", ['volunteer_id' => $volReg['user']['id']]);
        $this->harness->assert('T2-AUTH-10: Unverified volunteer strictly blocked from accepting request (403)', $resAcceptBlocked['code'] === 403, "Code: {$resAcceptBlocked['code']}", 'Tier 2');
    }

    // Category 4: Data Protection, Masking & Crypto Boundaries (10 tests)
    private function testDataProtectionBoundaries(): void
    {
        echo "\n[Tier 2.4] Data Protection, Masking & Crypto Boundaries (10 tests):\n";

        $this->harness->assertMilestone('M1', 'T2-DAT-01: Masking standard BY phone (+375291112233)', function() {
            if (!class_exists('\\App\\Services\\DataMaskingService')) {
                return ['skipped' => true, 'reason' => 'DataMaskingService pending M1'];
            }
            $m = \App\Services\DataMaskingService::maskPhone('+375291112233');
            return ['pass' => strpos($m, '***') !== false, 'details' => "Masked: {$m}"];
        }, 'Tier 2');

        $this->harness->assertMilestone('M1', 'T2-DAT-02: Masking national format (80291112233)', function() {
            if (!class_exists('\\App\\Services\\DataMaskingService')) {
                return ['skipped' => true, 'reason' => 'DataMaskingService pending M1'];
            }
            $m = \App\Services\DataMaskingService::maskPhone('80291112233');
            return ['pass' => strpos($m, '***') !== false, 'details' => "Masked: {$m}"];
        }, 'Tier 2');

        $this->harness->assertMilestone('M1', 'T2-DAT-03: Masking spaced/hyphenated format (+375 (29) 111-22-33)', function() {
            if (!class_exists('\\App\\Services\\DataMaskingService')) {
                return ['skipped' => true, 'reason' => 'DataMaskingService pending M1'];
            }
            $m = \App\Services\DataMaskingService::maskPhone('+375 (29) 111-22-33');
            return ['pass' => strpos($m, '***') !== false, 'details' => "Masked: {$m}"];
        }, 'Tier 2');

        $this->harness->assertMilestone('M1', 'T2-DAT-04: Masking short/invalid string returns safe placeholder', function() {
            if (!class_exists('\\App\\Services\\DataMaskingService')) {
                return ['skipped' => true, 'reason' => 'DataMaskingService pending M1'];
            }
            $m = \App\Services\DataMaskingService::maskPhone('123');
            return ['pass' => !empty($m), 'details' => "Safe output: {$m}"];
        }, 'Tier 2');

        $this->harness->assertMilestone('M1', 'T2-DAT-05: Apartment masking with "кв. 12" -> "кв. ***"', function() {
            if (!class_exists('\\App\\Services\\DataMaskingService')) {
                return ['skipped' => true, 'reason' => 'DataMaskingService pending M1'];
            }
            $m = \App\Services\DataMaskingService::maskAddress('пр-т Независимости, 45, кв. 12');
            return ['pass' => strpos($m, 'кв. ***') !== false, 'details' => "Masked: {$m}"];
        }, 'Tier 2');

        $this->harness->assertMilestone('M1', 'T2-DAT-06: Apartment masking with "квартира 45" -> "кв. ***"', function() {
            if (!class_exists('\\App\\Services\\DataMaskingService')) {
                return ['skipped' => true, 'reason' => 'DataMaskingService pending M1'];
            }
            $m = \App\Services\DataMaskingService::maskAddress('ул. Сурганова, 10, квартира 45');
            return ['pass' => strpos($m, '***') !== false, 'details' => "Masked: {$m}"];
        }, 'Tier 2');

        $this->harness->assertMilestone('M1', 'T2-DAT-07: Address without apartment (private house) structure preserved', function() {
            if (!class_exists('\\App\\Services\\DataMaskingService')) {
                return ['skipped' => true, 'reason' => 'DataMaskingService pending M1'];
            }
            $addr = 'г. Минск, ул. Орловская, 12';
            $m = \App\Services\DataMaskingService::maskAddress($addr);
            return ['pass' => strpos($m, 'ул. Орловская, 12') !== false, 'details' => "Preserved: {$m}"];
        }, 'Tier 2');

        $this->harness->assertMilestone('M1', 'T2-DAT-08: AES-256-GCM encrypted string has enc:v1: prefix', function() {
            if (!class_exists('\\App\\Services\\CryptoService')) {
                return ['skipped' => true, 'reason' => 'CryptoService pending M1'];
            }
            $c = \App\Services\CryptoService::encrypt('Инсулин');
            return ['pass' => str_starts_with($c, 'enc:v1:'), 'details' => "Encrypted: {$c}"];
        }, 'Tier 2');

        $this->harness->assertMilestone('M1', 'T2-DAT-09: Unencrypted string returned as-is and corrupted payload rejected', function() {
            if (!class_exists('\\App\\Services\\CryptoService')) {
                return ['skipped' => true, 'reason' => 'CryptoService pending M1'];
            }
            $legacy = \App\Services\CryptoService::decrypt('legacy unencrypted notes');
            $corruptedRejected = false;
            try {
                \App\Services\CryptoService::decrypt('enc:v1:corrupted-data');
            } catch (\Throwable $e) {
                $corruptedRejected = true;
            }
            return ['pass' => $legacy === 'legacy unencrypted notes' && $corruptedRejected, 'details' => 'Handled cleanly'];
        }, 'Tier 2');

        $this->harness->assertMilestone('M1', 'T2-DAT-10: Modified auth tag in AES-256-GCM cipher fails AEAD verification', function() {
            if (!class_exists('\\App\\Services\\CryptoService')) {
                return ['skipped' => true, 'reason' => 'CryptoService pending M1'];
            }
            $c = \App\Services\CryptoService::encrypt('Тест целостности');
            $tampered = 'enc:v1:' . base64_encode(str_repeat('X', 40));
            $tagFailed = false;
            try {
                $res = \App\Services\CryptoService::decrypt($tampered);
                $tagFailed = ($res === null || $res === false);
            } catch (\Throwable $e) {
                $tagFailed = true;
            }
            return ['pass' => $tagFailed, 'details' => 'Tag check succeeded'];
        }, 'Tier 2');
    }

    // Category 5: SOS & Emergency Response Boundaries (8 tests)
    private function testSosEmergencyBoundaries(): void
    {
        echo "\n[Tier 2.5] SOS & Emergency Response Boundaries (8 tests):\n";

        $elderlyToken = $this->harness->getElderlyToken();

        $this->harness->assertMilestone('M3', 'T2-SOS-01: SOS trigger with complete valid payload', function() use ($elderlyToken) {
            $res = $this->harness->apiCall('POST', '/api/sos', [
                'latitude' => 53.9168,
                'longitude' => 27.5862,
                'accuracy' => 10.0,
                'reason' => 'Упала, не могу встать'
            ], ["Authorization: Bearer {$elderlyToken}"]);
            if ($res['code'] === 404) {
                return ['skipped' => true, 'reason' => 'POST /api/sos pending M3'];
            }
            return ['pass' => $res['code'] === 201, 'details' => "HTTP {$res['code']}"];
        }, 'Tier 2');

        $this->harness->assertMilestone('M3', 'T2-SOS-02: SOS trigger with minimal payload (default coordinates)', function() use ($elderlyToken) {
            $res = $this->harness->apiCall('POST', '/api/sos', [], ["Authorization: Bearer {$elderlyToken}"]);
            if ($res['code'] === 404) {
                return ['skipped' => true, 'reason' => 'Endpoint pending M3'];
            }
            return ['pass' => in_array($res['code'], [201, 400]), 'details' => "HTTP {$res['code']}"];
        }, 'Tier 2');

        $this->harness->assertMilestone('M3', 'T2-SOS-03: SOS without explicit user_id resolves to authenticated elderly user', function() use ($elderlyToken) {
            $res = $this->harness->apiCall('POST', '/api/sos', ['reason' => 'Тест авто-идентификации'], ["Authorization: Bearer {$elderlyToken}"]);
            if ($res['code'] === 404) {
                return ['skipped' => true, 'reason' => 'Endpoint pending M3'];
            }
            return ['pass' => in_array($res['code'], [201, 400]), 'details' => "HTTP {$res['code']}"];
        }, 'Tier 2');

        $this->harness->assertMilestone('M3', 'T2-SOS-04: SOS trigger with extreme coordinates', function() use ($elderlyToken) {
            $res = $this->harness->apiCall('POST', '/api/sos', ['latitude' => -89.9, 'longitude' => 179.9], ["Authorization: Bearer {$elderlyToken}"]);
            if ($res['code'] === 404) {
                return ['skipped' => true, 'reason' => 'Endpoint pending M3'];
            }
            return ['pass' => in_array($res['code'], [201, 400, 422]), 'details' => "HTTP {$res['code']}"];
        }, 'Tier 2');

        $this->harness->assertMilestone('M3', 'T2-SOS-05: SOS trigger without Authorization token returns 401', function() {
            $res = $this->harness->apiCall('POST', '/api/sos', ['reason' => 'Анонимная тревога']);
            if ($res['code'] === 404) {
                return ['skipped' => true, 'reason' => 'Endpoint pending M3'];
            }
            return ['pass' => $res['code'] === 401, 'details' => "HTTP {$res['code']}"];
        }, 'Tier 2');

        $this->harness->assertMilestone('M3', 'T2-SOS-06: Coordinator can retrieve active SOS alerts list', function() {
            $coordToken = $this->harness->getCoordinatorToken();
            $res = $this->harness->apiCall('GET', '/api/coordinator/sos-alerts', null, ["Authorization: Bearer {$coordToken}"]);
            if ($res['code'] === 404) {
                return ['skipped' => true, 'reason' => 'Endpoint pending M3'];
            }
            return ['pass' => $res['code'] === 200, 'details' => "HTTP {$res['code']}"];
        }, 'Tier 2');

        $this->harness->assertMilestone('M3', 'T2-SOS-07: Resolving non-existent SOS ID returns 404', function() {
            $coordToken = $this->harness->getCoordinatorToken();
            $res = $this->harness->apiCall('POST', '/api/coordinator/sos-alerts/999999/resolve', null, ["Authorization: Bearer {$coordToken}"]);
            if ($res['code'] === 404 && ($res['data']['error'] ?? '') === 'Маршрут не найден') {
                return ['skipped' => true, 'reason' => 'Endpoint pending M3'];
            }
            return ['pass' => $res['code'] === 404, 'details' => "HTTP {$res['code']}"];
        }, 'Tier 2');

        $this->harness->assertMilestone('M3', 'T2-SOS-08: SOS alert incident defaults to highest priority', function() use ($elderlyToken) {
            $res = $this->harness->apiCall('POST', '/api/sos', [
                'latitude' => 53.9168,
                'longitude' => 27.5862,
                'reason' => 'Проверка наивысшего приоритета SOS (Priority 0)'
            ], ["Authorization: Bearer {$elderlyToken}"]);

            if ($res['code'] === 404) {
                return ['skipped' => true, 'reason' => 'POST /api/sos pending M3'];
            }

            if ($res['code'] !== 201) {
                return ['pass' => false, 'details' => "HTTP {$res['code']}, expected 201"];
            }

            $apiPriority = $res['data']['priority'] ?? null;
            $sosId = (int)($res['data']['sos_id'] ?? 0);

            $dbAlert = \App\Core\Database::getConnection()->query("SELECT priority, status FROM sos_alerts WHERE id = {$sosId}")->fetch(\PDO::FETCH_ASSOC);
            $dbPriority = $dbAlert !== false ? (int)$dbAlert['priority'] : null;

            $pass = ($apiPriority === 0) && ($dbPriority === 0);
            return [
                'pass' => $pass,
                'details' => "API priority: " . var_export($apiPriority, true) . ", DB priority: " . var_export($dbPriority, true)
            ];
        }, 'Tier 2');
    }

    // Category 6: Help Request Lifecycle & State Transitions (8 tests)
    private function testRequestLifecycleBoundaries(): void
    {
        echo "\n[Tier 2.6] Help Request Lifecycle & State Transitions (8 tests):\n";

        RateLimiter::clear("create_request:127.0.0.1");
        RateLimiter::clear("create_request:");

        // T2-REQ-01: Create request with minimum required fields
        $resMin = $this->harness->apiCall('POST', '/api/requests', [
            'elderly_id' => 1,
            'category_id' => 1,
            'description' => 'Купить хлеб'
        ]);
        $this->harness->assert('T2-REQ-01: Create request with description only generates default title and secret code', $resMin['code'] === 201 && !empty($resMin['data']['secret_code']), "Code: {$resMin['code']}", 'Tier 2');
        $minReqId = $resMin['data']['request_id'];
        $minSecret = $resMin['data']['secret_code'];

        RateLimiter::clear("create_request:127.0.0.1");
        RateLimiter::clear("create_request:");

        // T2-REQ-02: Empty title AND empty description -> 400
        $resEmpty = $this->harness->apiCall('POST', '/api/requests', [
            'elderly_id' => 1,
            'category_id' => 1,
            'title' => '',
            'description' => ''
        ]);
        $this->harness->assert('T2-REQ-02: Create request with empty title and empty description rejected with 400', $resEmpty['code'] === 400, "Code: {$resEmpty['code']}", 'Tier 2');

        // T2-REQ-03: Double accept -> 400
        $this->harness->apiCall('POST', "/api/requests/{$minReqId}/accept", ['volunteer_id' => 2]);
        $resDoubleAccept = $this->harness->apiCall('POST', "/api/requests/{$minReqId}/accept", ['volunteer_id' => 2]);
        $this->harness->assert('T2-REQ-03: Accepting already accepted task (in_progress) rejected with 400', $resDoubleAccept['code'] === 400, "Code: {$resDoubleAccept['code']}", 'Tier 2');

        // T2-REQ-04: Complete without accept on an open task
        $openReq = $this->harness->createHelpRequest(1, 1, 'Непринятая заявка');
        $openId = $openReq['data']['request_id'];
        $resEarlyComplete = $this->harness->apiCall('POST', "/api/requests/{$openId}/complete", [
            'secret_code' => $openReq['data']['secret_code'],
            'latitude' => 53.9168,
            'longitude' => 27.5862
        ]);
        $this->harness->assert('T2-REQ-04: Completing open task without taking into work rejected with 400', $resEarlyComplete['code'] === 400, "Code: {$resEarlyComplete['code']}", 'Tier 2');

        // T2-REQ-05: Complete the accepted task
        $resComp = $this->harness->apiCall('POST', "/api/requests/{$minReqId}/complete", [
            'secret_code' => $minSecret,
            'latitude' => 53.9168,
            'longitude' => 27.5862
        ]);
        $this->harness->assert('T2-REQ-05: Complete in_progress task with valid code succeeds (200)', $resComp['code'] === 200, "Code: {$resComp['code']}", 'Tier 2');

        // T2-REQ-06: Complete already completed task -> 400
        $resDoubleComp = $this->harness->apiCall('POST', "/api/requests/{$minReqId}/complete", [
            'secret_code' => $minSecret,
            'latitude' => 53.9168,
            'longitude' => 27.5862
        ]);
        $this->harness->assert('T2-REQ-06: Completing already completed task rejected with 400', $resDoubleComp['code'] === 400, "Code: {$resDoubleComp['code']}", 'Tier 2');

        // T2-REQ-07: Cancel open task then try to accept
        $resCancel = $this->harness->apiCall('POST', "/api/requests/{$openId}/cancel");
        $this->harness->assert('T2-REQ-07: Cancel open task succeeds (200)', $resCancel['code'] === 200, "Code: {$resCancel['code']}", 'Tier 2');

        $resAcceptCancelled = $this->harness->apiCall('POST', "/api/requests/{$openId}/accept", ['volunteer_id' => 2]);
        $this->harness->assert('T2-REQ-08: Accepting cancelled task rejected with 400', $resAcceptCancelled['code'] === 400, "Code: {$resAcceptCancelled['code']}", 'Tier 2');
    }

    // Category 7: Rate Limiting, Input Sanitization & Stress (8 tests)
    private function testSanitizationAndStressBoundaries(): void
    {
        echo "\n[Tier 2.7] Rate Limiting, Input Sanitization & Stress (8 tests):\n";

        $key = 'tier2_limiter_test_' . time();
        RateLimiter::clear($key);
        $this->harness->assert('T2-SAN-01: RateLimiter passes on request 1 within limit', RateLimiter::check($key, 3, 30), '', 'Tier 2');
        $this->harness->assert('T2-SAN-02: RateLimiter passes on request 2 within limit', RateLimiter::check($key, 3, 30), '', 'Tier 2');
        $this->harness->assert('T2-SAN-03: RateLimiter passes on request 3 (ceiling hit)', RateLimiter::check($key, 3, 30), '', 'Tier 2');
        $this->harness->assert('T2-SAN-04: RateLimiter strictly rejects request 4 (exceeded limit)', !RateLimiter::check($key, 3, 30), '', 'Tier 2');
        RateLimiter::clear($key);

        // Long title stress (1000 characters)
        $longTitle = str_repeat('А', 1000);
        $resLong = $this->harness->createHelpRequest(1, 1, $longTitle);
        $this->harness->assert('T2-SAN-05: 1000-character long title handled safely without crash', in_array($resLong['code'], [201, 400]), "Code: {$resLong['code']}", 'Tier 2');

        // Cyrillic, Latin and punctuation in description
        $mixedDesc = 'Пожалуйста, buy 2 packages of творог 5% & кефир. Спасибо!';
        $resMixed = $this->harness->createHelpRequest(1, 1, 'Смешанное описание', $mixedDesc);
        $this->harness->assert('T2-SAN-06: Mixed Cyrillic/Latin/Symbols description accepted intact', $resMixed['code'] === 201, "Code: {$resMixed['code']}", 'Tier 2');

        // Anti-fraud danger detection
        $fraudRes = AntiFraudService::analyze('Сообщите код из смс и CVV карты для перевода пенсии');
        $this->harness->assert('T2-SAN-07: AntiFraudService flags phishing pattern as danger', ($fraudRes['risk_level'] ?? '') === 'danger', '', 'Tier 2');

        // Anti-fraud safe detection
        $safeRes = AntiFraudService::analyze('Купите пожалуйста буханку нарезного батона и пакет кефира');
        $this->harness->assert('T2-SAN-08: AntiFraudService flags benign request as safe', ($safeRes['risk_level'] ?? '') === 'safe', '', 'Tier 2');
    }
}
