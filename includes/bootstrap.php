<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/farm_functions.php';
require_once __DIR__ . '/auth.php';

const GAME_VERSION = 'v0.4.34';

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}


function isAdminUser(mysqli $db, int $userId): bool
{
    $adminUser = getenv('ADMIN_USER') ?: '';

    if ($adminUser === '') {
        return false;
    }

    $stmt = $db->prepare("SELECT google_id, email FROM users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        return false;
    }

    return hash_equals($adminUser, (string) $user['google_id'])
        || hash_equals($adminUser, (string) $user['email'])
        || hash_equals($adminUser, (string) $userId);
}
