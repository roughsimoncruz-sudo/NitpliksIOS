<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'nitpliks_db';
const DB_USER = 'root';
const DB_PASS = '';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

function appEnv(string $name): string
{
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        $envFile = __DIR__ . DIRECTORY_SEPARATOR . '.env';
        if (is_readable($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                        continue;
                    }
                    [$envName, $envValue] = explode('=', $line, 2);
                    $envName = trim($envName);
                    $envValue = trim($envValue);
                    $existingValue = getenv($envName);
                    if ($envName === '' || ($existingValue !== false && $existingValue !== '')) {
                        continue;
                    }
                    if (
                        strlen($envValue) >= 2
                        && (($envValue[0] === '"' && $envValue[-1] === '"')
                            || ($envValue[0] === "'" && $envValue[-1] === "'"))
                    ) {
                        $envValue = substr($envValue, 1, -1);
                    }
                    putenv($envName . '=' . $envValue);
                    $_ENV[$envName] = $envValue;
                }
            }
        }
    }

    $value = getenv($name);
    if ($value === false) {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? '';
    }
    return is_string($value) ? $value : '';
}

function clientIp(): string
{
    $cloudflareIp = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    $remoteIp = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $ip = filter_var($cloudflareIp, FILTER_VALIDATE_IP) ? $cloudflareIp : $remoteIp;
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function database(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $pdo->exec('CREATE TABLE IF NOT EXISTS ip_bans (
        ip_address VARCHAR(45) NOT NULL,
        reason VARCHAR(255) NOT NULL DEFAULT \'\',
        banned_by INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (ip_address),
        KEY ip_bans_created (created_at),
        CONSTRAINT ip_bans_user_fk FOREIGN KEY (banned_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB');
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN suspended TINYINT(1) NOT NULL DEFAULT 0");
    } catch (PDOException $error) {
        if (stripos($error->getMessage(), 'duplicate column') === false) throw $error;
    }
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN admin_role ENUM('owner', 'moderator', 'analyst') NOT NULL DEFAULT 'owner'");
    } catch (PDOException $error) {
        if (stripos($error->getMessage(), 'duplicate column') === false) throw $error;
    }
    $visitorLogsTableExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'visitor_logs'"
    )->fetchColumn() > 0;
    if ($visitorLogsTableExists) {
        foreach ([
            "ALTER TABLE visitor_logs ADD COLUMN country_code CHAR(2) NOT NULL DEFAULT '' AFTER device_type",
            "ALTER TABLE visitor_logs ADD COLUMN city VARCHAR(100) NOT NULL DEFAULT '' AFTER country_code",
            "ALTER TABLE visitor_logs ADD COLUMN region VARCHAR(100) NOT NULL DEFAULT '' AFTER city",
        ] as $visitorLogMigration) {
            try {
                $pdo->exec($visitorLogMigration);
            } catch (PDOException $error) {
                if (stripos($error->getMessage(), 'duplicate column') === false) throw $error;
            }
        }
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS featured_content (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT, title_id INT UNSIGNED NOT NULL,
        media_type ENUM("movie", "tv") NOT NULL, title VARCHAR(255) NOT NULL,
        image_url VARCHAR(500) NOT NULL DEFAULT "", note VARCHAR(255) NOT NULL DEFAULT "",
        sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY featured_title_unique (title_id, media_type)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE IF NOT EXISTS content_reports (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, reporter_id INT UNSIGNED NULL,
        target_type VARCHAR(20) NOT NULL, target_key VARCHAR(120) NOT NULL,
        reason VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT "open",
        resolved_by INT UNSIGNED NULL, resolved_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY reports_status_created (status, created_at)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_audit_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, admin_id INT UNSIGNED NULL,
        action VARCHAR(80) NOT NULL, target_type VARCHAR(40) NOT NULL DEFAULT "",
        target_key VARCHAR(120) NOT NULL DEFAULT "", details VARCHAR(500) NOT NULL DEFAULT "",
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY admin_audit_created (created_at)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE IF NOT EXISTS site_settings (
        setting_key VARCHAR(80) NOT NULL,
        setting_value TEXT NOT NULL,
        updated_by INT UNSIGNED NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (setting_key),
        CONSTRAINT site_settings_user_fk FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO site_settings (setting_key, setting_value) VALUES
        ('maintenance_mode', '0'),
        ('maintenance_message', 'We are performing scheduled maintenance. Please check back soon.'),
        ('announcement_enabled', '0'),
        ('announcement_message', '')
        ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key)");
    return $pdo;
}

function getIpBanReason(): ?string
{
    $ip = clientIp();
    if ($ip === '') return null;

    try {
        $query = database()->prepare('SELECT reason FROM ip_bans WHERE ip_address = ? LIMIT 1');
        $query->execute([$ip]);
        $reason = $query->fetchColumn();
        if ($reason === false) return null;
        $reason = trim((string) $reason);
        return $reason !== '' ? $reason : 'No reason was provided.';
    } catch (Throwable $error) {
        error_log('Nitpliks IP ban check error: ' . $error->getMessage());
        return null;
    }
}

function isIpBanned(): bool
{
    return getIpBanReason() !== null;
}

function respondIpBanned(string $reason, bool $adminOnly = false): void
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $acceptsJson = str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
        || $script === 'features.php'
        || in_array($script, ['auth.php', 'account.php', 'sync.php', 'visitor.php'], true);

    http_response_code(403);
    if ($acceptsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => $adminOnly
                ? 'Administrator access is blocked for this IP address.'
                : 'This IP address is blocked from accessing Nitpliks.',
            'reason' => $reason,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    $title = $adminOnly ? 'Admin access blocked' : 'Access blocked';
    $message = $adminOnly
        ? 'Administrator access is blocked for this IP address.'
        : 'This IP address has been blocked from accessing Nitpliks.';
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeReason = htmlspecialchars($reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $safeTitle . '</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#070914;color:#f5f2e9;font:16px system-ui,sans-serif;text-align:center}main{max-width:32rem;margin:1rem;padding:2rem;border:1px solid #ff735f;border-radius:1rem}h1{color:#ffb4a7}p{color:#aeb5cc;line-height:1.6}.reason{padding:1rem;border-radius:.6rem;background:rgba(255,115,95,.1);overflow-wrap:anywhere}.reason strong{display:block;margin-bottom:.35rem;color:#ffb4a7}</style></head><body><main><h1>' . $safeTitle . '</h1><p>' . $safeMessage . '</p><p class="reason"><strong>Reason</strong>' . $safeReason . '</p></main></body></html>');
}

function enforceAdminIpBan(): void
{
    $reason = getIpBanReason();
    if ($reason === null) return;
    respondIpBanned($reason, true);
}

function enforceIpBan(): void
{
    $reason = getIpBanReason();
    if ($reason === null) return;
    respondIpBanned($reason);
}

function enforceMaintenanceMode(): void
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $action = (string) ($_GET['action'] ?? '');
    if ($script === 'admin.php' || $script === 'auth.php' || $script === 'visitor.php'
        || ($script === 'features.php' && $action === 'site-status')) {
        return;
    }

    try {
        $query = database()->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ?');
        $query->execute(['maintenance_mode']);
        if ($query->fetchColumn() !== '1') return;

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId > 0) {
            $query = database()->prepare('SELECT is_admin, suspended FROM users WHERE id = ?');
            $query->execute([$userId]);
            $user = $query->fetch();
            if ($user && (int) $user['is_admin'] === 1 && (int) $user['suspended'] === 0) return;
        }
    } catch (Throwable $error) {
        error_log('Nitpliks maintenance check error: ' . $error->getMessage());
        return;
    }

    http_response_code(503);
    if ($script !== '' && str_ends_with($script, '.php')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'The website is temporarily unavailable for maintenance.'], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

enforceIpBan();
enforceMaintenanceMode();
