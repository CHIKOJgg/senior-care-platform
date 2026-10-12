<?php

function apiCall(string $method, string $path, array $data = null) {
    $url = 'http://127.0.0.1:8000' . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'data' => json_decode($response, true)];
}

echo "=== TESTING ALL API ENDPOINTS ===\n";

// 1. Create Request
$res1 = apiCall('POST', '/api/requests', [
    'elderly_id' => 1,
    'category_id' => 2,
    'title' => 'Купить лекарства в аптеке',
    'description' => 'Купите цитрамон и валидол',
    'address' => 'Минск, ул. Куйбышева 28',
    'latitude' => 53.9190,
    'longitude' => 27.5750
]);
echo "1. POST /api/requests: Code {$res1['code']}, Success: " . ($res1['data']['success'] ? 'YES' : 'NO') . ", Secret: {$res1['data']['secret_code']}\n";
$reqId = $res1['data']['request_id'];
$secretCode = $res1['data']['secret_code'];

// 2. Get Requests
$res2 = apiCall('GET', '/api/requests?lat=53.9180&lon=27.5900');
echo "2. GET /api/requests: Code {$res2['code']}, Count: {$res2['data']['count']}\n";

// 3. Get Single Request Details
$res3 = apiCall('GET', '/api/requests/' . $reqId);
echo "3. GET /api/requests/{$reqId}: Code {$res3['code']}, Title: {$res3['data']['data']['title']}\n";

// 4. Accept Request
$res4 = apiCall('POST', "/api/requests/{$reqId}/accept", ['volunteer_id' => 2]);
echo "4. POST /api/requests/{$reqId}/accept: Code {$res4['code']}, Success: " . ($res4['data']['success'] ? 'YES' : 'NO') . "\n";

// 5. Complete Request
$res5 = apiCall('POST', "/api/requests/{$reqId}/complete", ['secret_code' => $secretCode]);
echo "5. POST /api/requests/{$reqId}/complete: Code {$res5['code']}, Success: " . ($res5['data']['success'] ? 'YES' : 'NO') . "\n";

// 6. Anti-Fraud Check
$res6 = apiCall('POST', '/api/antifraud/check', ['text' => 'Срочно назовите смс код подтверждения от банка']);
echo "6. POST /api/antifraud/check: Code {$res6['code']}, Risk: {$res6['data']['result']['risk_level']}\n";

// 7. Coordinator Stats
$res7 = apiCall('GET', '/api/coordinator/stats');
echo "7. GET /api/coordinator/stats: Code {$res7['code']}, Total: {$res7['data']['stats']['total_requests']}\n";

// 8. Volunteer Leaderboard
$res8 = apiCall('GET', '/api/volunteers/leaderboard');
echo "8. GET /api/volunteers/leaderboard: Code {$res8['code']}, Volunteers: " . count($res8['data']['data']) . "\n";

echo "=== ALL API TESTS COMPLETE ===\n";
