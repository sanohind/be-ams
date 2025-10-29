<?php

// Script untuk mendapatkan token valid dari be-sphere untuk testing AMS
$loginData = [
    'email' => 'superadmin',
    'password' => 'password'
];

// Login ke be-sphere
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://127.0.0.1:8000/api/auth/login');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($loginData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Login Response (HTTP $httpCode):\n";
echo $response . "\n\n";

if ($httpCode === 200) {
    $data = json_decode($response, true);
    if (isset($data['data']['access_token'])) {
        $token = $data['data']['access_token'];
        echo "=== VALID TOKEN FOR AMS TESTING ===\n";
        echo "Token: $token\n\n";
        
        echo "=== TEST AMS DASHBOARD ===\n";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://127.0.0.1:8002/api/dashboard');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $dashboardResponse = curl_exec($ch);
        $dashboardHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        echo "Dashboard Response (HTTP $dashboardHttpCode):\n";
        echo $dashboardResponse . "\n\n";
        
        echo "=== FRONTEND TESTING ===\n";
        echo "1. Buka browser developer tools (F12)\n";
        echo "2. Masuk ke tab Console\n";
        echo "3. Jalankan perintah berikut:\n";
        echo "localStorage.setItem('auth_token', '$token');\n";
        echo "4. Refresh halaman AMS frontend\n";
        echo "5. Seharusnya sudah login sebagai superadmin\n";
    }
}
