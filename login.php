<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$clientId = getenv('GOOGLE_CLIENT_ID');
$redirectUri = getenv('GOOGLE_REDIRECT_URI');

if (!$clientId || !$redirectUri) {
    http_response_code(500);
    die('Google OAuth is not configured.');
}

$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

$params = http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
    'access_type' => 'online',
    'prompt' => 'select_account'
]);

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Fantasy Farmer Login</title>
  <link rel="stylesheet" href="assets/css/farm.css?v=0.3.15">
</head>
<body class="login-page">
  <main class="login-card">
    <div class="brand-mark">🌱</div>
    <h1>Fantasy Farmer</h1>
    <p>Start with dirt. End with suspiciously magical jam.</p>
    <a class="button primary" href="<?= htmlspecialchars($authUrl) ?>">Sign in with Google</a>
  </main>
  <div class="version-pill"><?= htmlspecialchars(getAppVersion($db), ENT_QUOTES) ?></div>
</body>
</html>
