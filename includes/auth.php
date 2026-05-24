<?php
function currentUserId(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

function clearInvalidSession(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

function requireLogin(): int
{
    global $db;

    if (empty($_SESSION['user_id'])) {
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            jsonResponse(['ok' => false, 'error' => 'login_required'], 401);
        }

        header('Location: login.php');
        exit;
    }

    $userId = (int) $_SESSION['user_id'];

    $stmt = $db->prepare("SELECT user_id FROM users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();

    if (!$exists) {
        clearInvalidSession();

        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            jsonResponse(['ok' => false, 'error' => 'session_expired'], 401);
        }

        header('Location: login.php');
        exit;
    }

    return $userId;
}

function createOrUpdateGoogleUser(mysqli $db, array $profile): int
{
    $googleId = $profile['sub'] ?? '';
    $email = $profile['email'] ?? '';
    $name = $profile['name'] ?? '';
    $picture = $profile['picture'] ?? '';

    if ($googleId === '' || $email === '') {
        throw new RuntimeException('Invalid Google profile.');
    }

    $stmt = $db->prepare("SELECT user_id FROM users WHERE google_id = ? LIMIT 1");
    $stmt->bind_param('s', $googleId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();

    if ($existing) {
        $userId = (int) $existing['user_id'];

        $stmt = $db->prepare("
            UPDATE users
            SET email = ?, display_name = ?, avatar_url = ?, last_seen_at = NOW()
            WHERE user_id = ?
        ");
        $stmt->bind_param('sssi', $email, $name, $picture, $userId);
        $stmt->execute();

        ensurePlayerDefaults($db, $userId);
        return $userId;
    }

    $stmt = $db->prepare("
        INSERT INTO users (google_id, email, display_name, avatar_url, last_seen_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param('ssss', $googleId, $email, $name, $picture);
    $stmt->execute();

    $userId = (int) $db->insert_id;
    ensurePlayerDefaults($db, $userId);

    return $userId;
}
