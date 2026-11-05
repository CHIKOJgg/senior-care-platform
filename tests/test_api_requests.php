<?php

declare(strict_types=1);

function apiCall(string $method, string $path, array $data = null, array $headers = []): array {
    $url = 'http://127.0.0.1:8000' . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    $reqHeaders = ['Content-Type: application/json'];
    foreach ($headers as $h) {
        $reqHeaders[] = $h;
    }

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);
    
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'data' => json_decode($response ?: '', true)];
}

$passed = 0;
$failed = 0;

function assertApi(string $name, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$name} - {$details}\n";
        $failed++;
    }
}

echo "========================================================\n";
echo "   COMPREHENSIVE E2E REST API TEST SUITE (PRODUCTION-GRADE)\n";
echo "========================================================\n\n";

// 0. Healthcheck
echo "0. Testing Production Healthcheck Endpoint:\n";
$resHealth = apiCall('GET', '/api/health');
assertApi('Healthcheck returns 200 OK', $resHealth['code'] === 200);
assertApi('Healthcheck reports database connected', ($resHealth['data']['checks']['database']['status'] ?? '') === 'connected');

// 1. Create Help Request
echo "\n1. Testing Help Request Creation (User Function 1):\n";
$res1 = apiCall('POST', '/api/requests', [
    'elderly_id' => 1,
    'category_id' => 1,
    'title' => 'Привезти творог и кефир',
    'description' => 'Купите творог 5% 2 пачки и кефир 1 л',
    'address' => 'Минск, пр-т Независимости 45',
    'latitude' => 53.9168,
    'longitude' => 27.5862
]);
assertApi('Create request returns 201 Created', $res1['code'] === 201);
assertApi('Response contains valid secret code', !empty($res1['data']['secret_code']));
$reqId = $res1['data']['request_id'];
$secretCode = $res1['data']['secret_code'];

// 2. Elderly My Requests Endpoint
echo "\n2. Testing Elderly My Requests List:\n";
$resMy = apiCall('GET', '/api/requests/my?elderly_id=1');
assertApi('GET /api/requests/my returns 200', $resMy['code'] === 200);
assertApi('Contains created request with secret code', !empty($resMy['data']['data'][0]['secret_code']));

// 3. Register a New Volunteer
echo "\n3. Testing Volunteer Registration & Verification Defense:\n";
$randomPhone = '+37529' . rand(1000000, 9999999);
$resReg = apiCall('POST', '/api/auth/register', [
    'role_id' => 2,
    'phone' => $randomPhone,
    'name' => 'Иван Новый Волонтёр',
    'password' => 'secret123',
    'skills' => 'автомобиль, доставка'
]);
assertApi('Register new volunteer returns 201', $resReg['code'] === 201);
$newVolId = $resReg['data']['user']['id'];
$volToken = $resReg['data']['token'];

// 4. Unverified Volunteer tries to accept request -> MUST BE BLOCKED (403)
echo "\n4. Testing Unverified Volunteer Access Control (Security Guard):\n";
$resBlocked = apiCall('POST', "/api/requests/{$reqId}/accept", ['volunteer_id' => $newVolId]);
assertApi('Unverified volunteer blocked with 403 Forbidden', $resBlocked['code'] === 403);

// 5. Admin Verifies Volunteer -> SUCCEEDS (200)
echo "\n5. Testing Admin Volunteer Verification:\n";
$resVerify = apiCall('POST', "/api/coordinator/verify-volunteer/{$newVolId}");
assertApi('Admin verify volunteer returns 200 OK', $resVerify['code'] === 200 && $resVerify['data']['success'] === true);

// 6. Now Verified Volunteer accepts request -> SUCCEEDS (200)
echo "\n6. Testing Verified Volunteer Accepting Request:\n";
$resAccept = apiCall('POST', "/api/requests/{$reqId}/accept", ['volunteer_id' => $newVolId]);
assertApi('Verified volunteer successfully accepts request', $resAccept['code'] === 200 && $resAccept['data']['success'] === true);

// 6b. Volunteer Active Tasks List
echo "\n6b. Testing Volunteer Active Tasks in Progress:\n";
$resVolTasks = apiCall('GET', "/api/requests/volunteer?volunteer_id={$newVolId}&status=in_progress");
assertApi('Volunteer active tasks endpoint returns 200', $resVolTasks['code'] === 200);
assertApi('Contains accepted in_progress task', count($resVolTasks['data']['data'] ?? []) > 0 && (int)$resVolTasks['data']['data'][0]['id'] === (int)$reqId);

// 7. Complete Request Security Word Validation
echo "\n7. Testing Request Completion & Secret Code Verification:\n";
// 7a. Missing secret code -> 400
$resFailEmpty = apiCall('POST', "/api/requests/{$reqId}/complete", ['secret_code' => '']);
assertApi('Completion without secret code returns 400', $resFailEmpty['code'] === 400);

// 7b. Wrong secret code -> 400
$resFailWrong = apiCall('POST', "/api/requests/{$reqId}/complete", ['secret_code' => 'НеверноеСлово123']);
assertApi('Completion with wrong secret code returns 400', $resFailWrong['code'] === 400);

// 7c. Correct secret code & GPS proof-of-presence -> 200
$resComplete = apiCall('POST', "/api/requests/{$reqId}/complete", [
    'secret_code' => $secretCode,
    'latitude' => 53.9168,
    'longitude' => 27.5862
]);
assertApi('Completion with correct secret code succeeds 200', $resComplete['code'] === 200 && $resComplete['data']['success'] === true);

// 8. Admin Rejects / Revokes Volunteer
echo "\n8. Testing Admin Volunteer Rejection / Revocation:\n";
$resReject = apiCall('POST', "/api/coordinator/reject-volunteer/{$newVolId}");
assertApi('Admin rejection returns 200', $resReject['code'] === 200);

// 9. Anti-Fraud Scanner (User Function 2)
echo "\n9. Testing Anti-Fraud Scanner (User Function 2):\n";
$resFraud = apiCall('POST', '/api/antifraud/check', ['text' => 'Срочно переведите деньги на безопасный счёт и назовите код из смс']);
assertApi('Danger fraud text identified as danger', $resFraud['data']['result']['risk_level'] === 'danger');

$resSafe = apiCall('POST', '/api/antifraud/check', ['text' => 'Здравствуйте, внук приедет на выходных']);
assertApi('Safe text identified as safe', $resSafe['data']['result']['risk_level'] === 'safe');

// 10. Admin Analytics & Requests Management
echo "\n10. Testing Admin Analytics & Management Endpoints:\n";
$resStats = apiCall('GET', '/api/coordinator/stats');
assertApi('Admin stats contains total_requests', isset($resStats['data']['stats']['total_requests']));
assertApi('Admin stats contains category_distribution', is_array($resStats['data']['stats']['category_distribution']));

$resAdminReqs = apiCall('GET', '/api/coordinator/requests');
assertApi('Admin requests list returns all requests', is_array($resAdminReqs['data']['data']));

// 11. Admin Alerts Management (Create, List, Delete)
echo "\n11. Testing Admin Alerts Management:\n";
$resAlertCreate = apiCall('POST', '/api/coordinator/alerts', [
    'title' => 'Тестовое оповещение района',
    'description' => 'Проверка оповещения в ТЦСОН',
    'severity' => 'danger'
]);
assertApi('Create alert returns 201', $resAlertCreate['code'] === 201);
$alertId = $resAlertCreate['data']['alert_id'];

$resAlertDelete = apiCall('DELETE', "/api/coordinator/alerts/{$alertId}");
assertApi('Delete alert returns 200', $resAlertDelete['code'] === 200);

// 12. Authentication, Token verification and RBAC
echo "\n12. Testing Authentication & RBAC Access Control:\n";
$resLogin = apiCall('POST', '/api/auth/login', [
    'phone' => '+375299999999',
    'password' => 'password123'
]);
assertApi('Admin login returns 200 and token', $resLogin['code'] === 200 && !empty($resLogin['data']['token']));
$adminToken = $resLogin['data']['token'];

$resMe = apiCall('GET', '/api/auth/me', null, ["Authorization: Bearer {$adminToken}"]);
assertApi('GET /api/auth/me with Bearer token identifies Admin user', $resMe['code'] === 200 && $resMe['data']['user']['id'] === 4);

// Volunteer tries to create alert -> MUST BE 403 Forbidden
$resForbidden = apiCall('POST', '/api/coordinator/alerts', [
    'title' => 'Попытка взлома',
    'description' => 'Несанкционированное оповещение'
], ["Authorization: Bearer {$volToken}"]);
assertApi('Volunteer token blocked from admin alerts with 403 Forbidden', $resForbidden['code'] === 403);

echo "\n--------------------------------------------------------\n";
echo "E2E API RESULTS: Total: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
