<?php
require_once __DIR__ . '/includes/bootstrap.php';

$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';

if (!$code || !$state || empty($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $state)) {
    http_response_code(400);
    die('Invalid OAuth state.');
}

unset($_SESSION['oauth_state']);

$clientId = getenv('GOOGLE_CLIENT_ID');
$clientSecret = getenv('GOOGLE_CLIENT_SECRET');
$redirectUri = getenv('GOOGLE_REDIRECT_URI');

$tokenPayload = http_build_query([
    'code' => $code,
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri' => $redirectUri,
    'grant_type' => 'authorization_code'
]);

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $tokenPayload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
]);

$tokenResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode < 200 || $httpCode >= 300 || !$tokenResponse) {
    http_response_code(500);
    die('Unable to complete Google login.');
}

$tokens = json_decode($tokenResponse, true);
$accessToken = $tokens['access_token'] ?? '';

if (!$accessToken) {
    http_response_code(500);
    die('Missing access token.');
}

$ch = curl_init('https://openidconnect.googleapis.com/v1/userinfo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken]
]);

$profileResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode < 200 || $httpCode >= 300 || !$profileResponse) {
    http_response_code(500);
    die('Unable to fetch Google profile.');
}

$profile = json_decode($profileResponse, true);

try {
    $userId = createOrUpdateGoogleUser($db, $profile);
    $_SESSION['user_id'] = $userId;
    $_SESSION['display_name'] = $profile['name'] ?? 'Farmer';

    header('Location: index.php');
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    die('Unable to create player.');
}
