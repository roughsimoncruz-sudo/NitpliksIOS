<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
session_start();
require __DIR__ . '/config.php';

function accountResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function accountUserId(): int
{
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if ($id < 1) accountResponse(['error' => 'Sign in required.'], 401);
    return $id;
}

function accountBody(): array
{
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($body) ? $body : $_POST;
}

try {
    $pdo = database();
    $userId = accountUserId();
    $action = $_GET['action'] ?? 'profile';

    if ($action === 'profile' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $query = $pdo->prepare('SELECT id, username, email, is_admin, created_at FROM users WHERE id = ?');
        $query->execute([$userId]);
        accountResponse(['user' => $query->fetch()]);
    }

    if ($action === 'profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = accountBody();
        $username = trim((string) ($body['username'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) {
            accountResponse(['error' => 'Username must be 3–30 characters using letters, numbers, or underscores.'], 422);
        }
        $query = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
        $query->execute([$username, $userId]);
        if ($query->fetch()) accountResponse(['error' => 'That username is already in use.'], 409);
        $query = $pdo->prepare('UPDATE users SET username = ? WHERE id = ?');
        $query->execute([$username, $userId]);
        accountResponse(['saved' => true]);
    }

    if ($action === 'password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = accountBody();
        $current = (string) ($body['current_password'] ?? '');
        $next = (string) ($body['new_password'] ?? '');
        $query = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $query->execute([$userId]);
        $user = $query->fetch();
        if (!$user || !password_verify($current, $user['password_hash'])) accountResponse(['error' => 'Current password is incorrect.'], 422);
        if (strlen($next) < 8 || strlen($next) > 72) accountResponse(['error' => 'New password must be between 8 and 72 characters.'], 422);
        $query = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $query->execute([password_hash($next, PASSWORD_DEFAULT), $userId]);
        accountResponse(['saved' => true]);
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo->beginTransaction();
        $query = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $query->execute([$userId]);
        $pdo->commit();
        $_SESSION = [];
        session_destroy();
        accountResponse(['deleted' => true]);
    }

    accountResponse(['error' => 'Unknown account action.'], 400);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Nitpliks account error: ' . $error->getMessage());
    accountResponse(['error' => 'Account service is temporarily unavailable.'], 503);
}
