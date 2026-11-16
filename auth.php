<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

require __DIR__ . '/config.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function requestData(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : $_POST;
}

function currentUser(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $statement = database()->prepare('SELECT id, username, email, avatar_url, is_admin, admin_role, created_at FROM users WHERE id = ?');
    $statement->execute([(int) $_SESSION['user_id']]);
    $user = $statement->fetch();
    return $user ?: null;
}

function securityEvent(string $type, string $email, string $ip): void
{
    database()->prepare('INSERT INTO security_events (event_type, email, ip_address) VALUES (?, ?, ?)')->execute([$type, $email, $ip]);
}

try {
    $action = $_GET['action'] ?? 'me';

    if ($action === 'me') {
        respond(['authenticated' => currentUser() !== null, 'user' => currentUser(), 'csrf_token' => $_SESSION['csrf_token']]);
    }

    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
        respond(['authenticated' => false]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['error' => 'This action requires a POST request.'], 405);
    }

    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        respond(['error' => 'Security token expired. Refresh the page and try again.'], 419);
    }

    $data = requestData();
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $password = (string) ($data['password'] ?? '');

    if ($action === 'forgot') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            respond(['error' => 'Enter a valid email address.'], 422);
        }

        $statement = database()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $statement->execute([$email]);
        $user = $statement->fetch();
        $response = ['message' => 'If that email exists, a reset link has been generated.'];
        if ($user) {
            $rawToken = bin2hex(random_bytes(32));
            database()->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([(int) $user['id']]);
            database()->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)')->execute([
                (int) $user['id'],
                hash('sha256', $rawToken),
                date('Y-m-d H:i:s', time() + 1800),
            ]);
            $response['reset_url'] = 'password-reset.html?token=' . rawurlencode($rawToken);
        }
        respond($response);
    }

    if ($action === 'reset') {
        $token = trim((string) ($data['token'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            respond(['error' => 'That reset link is invalid or expired.'], 422);
        }
        if (strlen($password) < 8 || strlen($password) > 72) {
            respond(['error' => 'Password must be between 8 and 72 characters.'], 422);
        }
        $statement = database()->prepare(
            'SELECT id, user_id FROM password_reset_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
        );
        $statement->execute([hash('sha256', $token)]);
        $reset = $statement->fetch();
        if (!$reset) {
            respond(['error' => 'That reset link is invalid or expired.'], 422);
        }
        database()->beginTransaction();
        database()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), (int) $reset['user_id']]);
        database()->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?')->execute([(int) $reset['id']]);
        database()->commit();
        respond(['message' => 'Password reset successfully. You can now sign in.']);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        respond(['error' => 'Enter a valid email address.'], 422);
    }
    if (strlen($password) < 8 || strlen($password) > 72) {
        respond(['error' => 'Password must be between 8 and 72 characters.'], 422);
    }

    if ($action === 'register') {
        $username = trim((string) ($data['username'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) {
            respond(['error' => 'Username must be 3–30 characters using letters, numbers, or underscores.'], 422);
        }

        $statement = database()->prepare('SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1');
        $statement->execute([$email, $username]);
        if ($statement->fetch()) {
            respond(['error' => 'That email or username is already registered.'], 409);
        }

        $statement = database()->prepare('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)');
        $statement->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT)]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) database()->lastInsertId();
        respond(['authenticated' => true, 'user' => currentUser()], 201);
    }

    if ($action === 'login') {
        $ip = clientIp();
        $attempt = database()->prepare('SELECT attempts, locked_until FROM login_attempts WHERE ip_address = ? AND email = ?');
        $attempt->execute([$ip, $email]);
        $record = $attempt->fetch();
        if ($record && $record['locked_until'] !== null && strtotime($record['locked_until']) > time()) {
            respond(['error' => 'Too many failed attempts. Try again later.'], 429);
        }

        $statement = database()->prepare('SELECT id, password_hash, suspended FROM users WHERE email = ? LIMIT 1');
        $statement->execute([$email]);
        $user = $statement->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            securityEvent('login_failed', $email, $ip);
            $attempts = ((int) ($record['attempts'] ?? 0)) + 1;
            $lockedUntil = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
            database()->prepare(
                'INSERT INTO login_attempts (ip_address, email, attempts, first_attempt_at, locked_until) VALUES (?, ?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), locked_until = VALUES(locked_until)'
            )->execute([$ip, $email, $attempts, $lockedUntil]);
            respond(['error' => 'Email or password is incorrect.'], 401);
        }
        if ((int) $user['suspended'] === 1) {
            respond(['error' => 'This account is suspended. Contact an administrator.'], 403);
        }

        database()->prepare('DELETE FROM login_attempts WHERE ip_address = ? AND email = ?')->execute([$ip, $email]);
        securityEvent('login_success', $email, $ip);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        respond(['authenticated' => true, 'user' => currentUser()]);
    }

    respond(['error' => 'Unknown authentication action.'], 400);
} catch (PDOException $error) {
    error_log('Nitpliks authentication database error: ' . $error->getMessage());
    respond(['error' => 'The account service is temporarily unavailable.'], 503);
} catch (Throwable $error) {
    error_log('Nitpliks authentication error: ' . $error->getMessage());
    respond(['error' => 'Unable to complete that request.'], 500);
}
