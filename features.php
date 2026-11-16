<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
session_start();
require __DIR__ . '/config.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function featureResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function featureUserId(): int
{
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if ($id < 1) featureResponse(['error' => 'Sign in required.'], 401);
    return $id;
}

function featureBody(): array
{
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($body) ? $body : $_POST;
}

function requireAdmin(PDO $pdo, int $userId): void
{
    $query = $pdo->prepare('SELECT is_admin, admin_role FROM users WHERE id = ?');
    $query->execute([$userId]);
    $admin = $query->fetch();
    if (!$admin || !(int) $admin['is_admin']) featureResponse(['error' => 'Administrator access required.'], 403);
}

function requirePermission(PDO $pdo, int $userId, string $permission): string
{
    $query = $pdo->prepare('SELECT is_admin, admin_role FROM users WHERE id = ?');
    $query->execute([$userId]);
    $admin = $query->fetch();
    if (!$admin || !(int) $admin['is_admin']) featureResponse(['error' => 'Administrator access required.'], 403);
    $role = (string) $admin['admin_role'];
    $permissions = [
        'owner' => ['owner', 'users', 'users_write', 'roles', 'admin_access', 'site_controls', 'content', 'content_write', 'moderation', 'moderation_write', 'analytics', 'security', 'security_write'],
        'moderator' => ['users', 'users_write', 'content', 'content_write', 'moderation', 'moderation_write', 'security'],
        'analyst' => ['users', 'content', 'moderation', 'analytics', 'security'],
    ];
    if (!in_array($permission, $permissions[$role] ?? [], true)) {
        featureResponse(['error' => 'Your administrator role does not have permission for this action.'], 403);
    }
    return $role;
}

function auditAdmin(PDO $pdo, int $adminId, string $action, string $targetType = '', string $targetKey = '', string $details = ''): void
{
    $query = $pdo->prepare('INSERT INTO admin_audit_logs (admin_id, action, target_type, target_key, details) VALUES (?, ?, ?, ?, ?)');
    $query->execute([$adminId, $action, $targetType, $targetKey, substr($details, 0, 500)]);
}

try {
    $action = $_GET['action'] ?? 'notifications';
    if ($action === 'site-status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $pdo = database();
        $query = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('maintenance_mode', 'maintenance_message', 'announcement_enabled', 'announcement_message')");
        $settings = [];
        foreach ($query->fetchAll() as $setting) {
            $settings[$setting['setting_key']] = $setting['setting_value'];
        }
        featureResponse([
            'maintenance_mode' => ($settings['maintenance_mode'] ?? '0') === '1',
            'maintenance_message' => $settings['maintenance_message'] ?? 'We are performing scheduled maintenance. Please check back soon.',
            'announcement_enabled' => ($settings['announcement_enabled'] ?? '0') === '1',
            'announcement_message' => $settings['announcement_message'] ?? '',
        ]);
    }

    $pdo = database();
    $userId = featureUserId();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        featureResponse(['error' => 'Security token expired. Refresh the page and try again.'], 419);
    }

    if ($action === 'site-settings' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requirePermission($pdo, $userId, 'site_controls');
        $query = $pdo->query('SELECT setting_key, setting_value FROM site_settings');
        $settings = [];
        foreach ($query->fetchAll() as $setting) {
            $settings[$setting['setting_key']] = $setting['setting_value'];
        }
        featureResponse([
            'maintenance_mode' => ($settings['maintenance_mode'] ?? '0') === '1',
            'maintenance_message' => $settings['maintenance_message'] ?? 'We are performing scheduled maintenance. Please check back soon.',
            'announcement_enabled' => ($settings['announcement_enabled'] ?? '0') === '1',
            'announcement_message' => $settings['announcement_message'] ?? '',
        ]);
    }

    if ($action === 'save-site-settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requirePermission($pdo, $userId, 'site_controls');
        $body = featureBody();
        $maintenanceMode = filter_var($body['maintenance_mode'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $announcementEnabled = filter_var($body['announcement_enabled'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $maintenanceMessage = trim((string) ($body['maintenance_message'] ?? ''));
        $announcementMessage = trim((string) ($body['announcement_message'] ?? ''));
        if ($maintenanceMode === null || $announcementEnabled === null
            || strlen($maintenanceMessage) > 500 || strlen($announcementMessage) > 500
            || ($announcementEnabled && $announcementMessage === '')) {
            featureResponse(['error' => 'Choose valid settings and keep messages under 500 characters. An enabled announcement needs text.'], 422);
        }
        $save = $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)');
        $settings = [
            'maintenance_mode' => $maintenanceMode ? '1' : '0',
            'maintenance_message' => $maintenanceMessage !== '' ? $maintenanceMessage : 'We are performing scheduled maintenance. Please check back soon.',
            'announcement_enabled' => $announcementEnabled ? '1' : '0',
            'announcement_message' => $announcementMessage,
        ];
        foreach ($settings as $key => $value) {
            $save->execute([$key, $value, $userId]);
        }
        auditAdmin($pdo, $userId, 'update_site_settings', 'site', 'settings');
        featureResponse(['saved' => true, 'settings' => [
            'maintenance_mode' => $maintenanceMode,
            'maintenance_message' => $settings['maintenance_message'],
            'announcement_enabled' => $announcementEnabled,
            'announcement_message' => $announcementMessage,
        ]]);
    }

    if ($action === 'notifications' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $query = $pdo->prepare('SELECT id, title, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
        $query->execute([$userId]);
        featureResponse(['items' => $query->fetchAll()]);
    }

    if ($action === 'read-notification' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int) (featureBody()['id'] ?? 0);
        $query = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
        $query->execute([$id, $userId]);
        featureResponse(['saved' => true]);
    }

    if ($action === 'collections' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $query = $pdo->prepare('SELECT id, name, description, is_public, share_token, updated_at FROM user_collections WHERE user_id = ? ORDER BY updated_at DESC');
        $query->execute([$userId]);
        featureResponse(['items' => $query->fetchAll()]);
    }

    if ($action === 'profile-stats' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $query = $pdo->prepare("SELECT
            COALESCE(SUM(collection = 'progress' AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.deleted')), 'false') <> 'true'), 0) AS watched_titles,
            COALESCE(SUM(CASE WHEN collection = 'progress' AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.deleted')), 'false') <> 'true' THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.duration')) AS UNSIGNED) ELSE 0 END), 0) AS watched_seconds,
            COALESCE(SUM(collection = 'reviews' AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.deleted')), 'false') <> 'true'), 0) AS rated_titles
            FROM user_library WHERE user_id = ?");
        $query->execute([$userId]);
        featureResponse(['stats' => $query->fetch()]);
    }

    if ($action === 'create-collection' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = featureBody();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || strlen($name) > 80) featureResponse(['error' => 'Collection name is required and must be 80 characters or fewer.'], 422);
        $query = $pdo->prepare('INSERT INTO user_collections (user_id, name, description, is_public, share_token) VALUES (?, ?, ?, ?, ?)');
        $query->execute([$userId, $name, substr(trim((string) ($body['description'] ?? '')), 0, 255), !empty($body['is_public']) ? 1 : 0, bin2hex(random_bytes(24))]);
        featureResponse(['id' => (int) $pdo->lastInsertId()], 201);
    }

    if ($action === 'public-collection' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $token = trim((string) ($_GET['token'] ?? ''));
        $query = $pdo->prepare('SELECT id, name, description, created_at FROM user_collections WHERE share_token = ? AND is_public = 1');
        $query->execute([$token]);
        $collection = $query->fetch();
        if (!$collection) featureResponse(['error' => 'Public collection not found.'], 404);
        $query = $pdo->prepare('SELECT payload FROM user_collection_items WHERE collection_id = ? ORDER BY added_at DESC');
        $query->execute([$collection['id']]);
        featureResponse(['collection' => $collection, 'items' => $query->fetchAll()]);
    }

    if ($action === 'providers' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $tmdbToken = appEnv('TMDB_ACCESS_TOKEN');
        $tmdbKey = appEnv('TMDB_API_KEY');
        $titleId = (int) ($_GET['id'] ?? 0);
        $type = $_GET['type'] === 'tv' ? 'tv' : 'movie';
        if (($tmdbToken === '' && $tmdbKey === '') || $titleId < 1) featureResponse(['items' => []]);
        $query = $tmdbToken === '' ? '?api_key=' . rawurlencode($tmdbKey) : '';
        $url = "https://api.themoviedb.org/3/{$type}/{$titleId}/watch/providers{$query}";
        $headers = $tmdbToken === '' ? '' : "Authorization: Bearer {$tmdbToken}\r\n";
        $context = stream_context_create(['http' => ['timeout' => 5, 'header' => $headers]]);
        $payload = @file_get_contents($url, false, $context);
        $data = json_decode($payload ?: '{}', true);
        featureResponse(['items' => $data['results'] ?? []]);
    }

    if ($action === 'collection-items' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $collectionId = (int) ($_GET['id'] ?? 0);
        $query = $pdo->prepare('SELECT id, name, description, is_public FROM user_collections WHERE id = ? AND user_id = ?');
        $query->execute([$collectionId, $userId]);
        $collection = $query->fetch();
        if (!$collection) featureResponse(['error' => 'Collection not found.'], 404);
        $query = $pdo->prepare('SELECT item_key, payload, added_at FROM user_collection_items WHERE collection_id = ? ORDER BY added_at DESC');
        $query->execute([$collectionId]);
        featureResponse(['collection' => $collection, 'items' => $query->fetchAll()]);
    }

    if ($action === 'add-collection-item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = featureBody();
        $collectionId = (int) ($body['collection_id'] ?? 0);
        $item = $body['item'] ?? null;
        if (!is_array($item) || empty($item['id'])) featureResponse(['error' => 'A valid title is required.'], 422);
        $query = $pdo->prepare('SELECT id FROM user_collections WHERE id = ? AND user_id = ?');
        $query->execute([$collectionId, $userId]);
        if (!$query->fetch()) featureResponse(['error' => 'Collection not found.'], 404);
        $query = $pdo->prepare('INSERT INTO user_collection_items (collection_id, item_key, payload) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE payload = VALUES(payload)');
        $query->execute([$collectionId, substr((string) $item['id'], 0, 100), json_encode($item, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        featureResponse(['saved' => true]);
    }

    if ($action === 'admin-stats' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requirePermission($pdo, $userId, 'analytics');
        $users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $collections = (int) $pdo->query('SELECT COUNT(*) FROM user_collections')->fetchColumn();
        $notifications = (int) $pdo->query('SELECT COUNT(*) FROM notifications WHERE is_read = 0')->fetchColumn();
        $visitsToday = (int) $pdo->query('SELECT COUNT(*) FROM visitor_logs WHERE visited_at >= CURDATE()')->fetchColumn();
        $visits30Days = (int) $pdo->query('SELECT COUNT(*) FROM visitor_logs WHERE visited_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)')->fetchColumn();
        $uniqueIps30Days = (int) $pdo->query('SELECT COUNT(DISTINCT ip_address) FROM visitor_logs WHERE visited_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)')->fetchColumn();
        $securityEvents = (int) $pdo->query('SELECT COUNT(*) FROM security_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)')->fetchColumn();
        $suspended = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE suspended = 1')->fetchColumn();
        $openReports = (int) $pdo->query("SELECT COUNT(*) FROM content_reports WHERE status = 'open'")->fetchColumn();
        featureResponse(compact('users', 'collections', 'notifications', 'visitsToday', 'visits30Days', 'uniqueIps30Days', 'securityEvents', 'suspended', 'openReports'));
    }

    if ($action === 'admin-users' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requirePermission($pdo, $userId, 'users');
        $search = trim((string) ($_GET['search'] ?? ''));
        $query = $pdo->prepare('SELECT id, username, email, is_admin, admin_role, suspended, created_at FROM users WHERE username LIKE ? OR email LIKE ? ORDER BY created_at DESC LIMIT 100');
        $like = '%' . $search . '%';
        $query->execute([$like, $like]);
        featureResponse(['items' => $query->fetchAll()]);
    }

    if ($action === 'admin-user-details' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requirePermission($pdo, $userId, 'users');
        $targetId = (int) ($_GET['user_id'] ?? 0);
        if ($targetId < 1) featureResponse(['error' => 'Choose a valid user.'], 422);
        $query = $pdo->prepare('SELECT id, username, email, avatar_url, is_admin, admin_role, suspended, created_at FROM users WHERE id = ?');
        $query->execute([$targetId]);
        $target = $query->fetch();
        if (!$target) featureResponse(['error' => 'User not found.'], 404);
        $libraryQuery = $pdo->prepare("SELECT collection, COUNT(*) AS item_count, MAX(updated_at) AS last_updated FROM user_library WHERE user_id = ? AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.deleted')), 'false') <> 'true' GROUP BY collection");
        $libraryQuery->execute([$targetId]);
        $collectionsQuery = $pdo->prepare('SELECT COUNT(*) FROM user_collections WHERE user_id = ?');
        $collectionsQuery->execute([$targetId]);
        featureResponse([
            'user' => $target,
            'library' => $libraryQuery->fetchAll(),
            'collections' => (int) $collectionsQuery->fetchColumn(),
        ]);
    }

    if ($action === 'admin-user-status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requirePermission($pdo, $userId, 'users_write');
        $body = featureBody();
        $targetId = (int) ($body['user_id'] ?? 0);
        if ($targetId < 1 || $targetId === $userId) featureResponse(['error' => 'You cannot change your own administrator status.'], 422);
        $query = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
        $query->execute([$targetId]);
        $targetIsAdmin = $query->fetchColumn();
        if ($targetIsAdmin === false) featureResponse(['error' => 'User not found.'], 404);
        if ((int) $targetIsAdmin === 1) featureResponse(['error' => 'Revoke administrator access before suspending this account.'], 422);
        $suspended = !empty($body['suspended']) ? 1 : 0;
        $query = $pdo->prepare('UPDATE users SET suspended = ? WHERE id = ?');
        $query->execute([$suspended, $targetId]);
        auditAdmin($pdo, $userId, $suspended ? 'suspend_user' : 'unsuspend_user', 'user', (string) $targetId);
        featureResponse(['saved' => true]);
    }

    if ($action === 'admin-user-role' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requirePermission($pdo, $userId, 'roles');
        $body = featureBody();
        $targetId = (int) ($body['user_id'] ?? 0);
        $role = (string) ($body['admin_role'] ?? '');
        if ($targetId < 1 || $targetId === $userId || !in_array($role, ['owner', 'moderator', 'analyst'], true)) {
            featureResponse(['error' => 'Choose a valid role for another administrator.'], 422);
        }
        $pdo->beginTransaction();
        $query = $pdo->prepare('SELECT admin_role FROM users WHERE id = ? AND is_admin = 1 FOR UPDATE');
        $query->execute([$targetId]);
        $target = $query->fetch();
        if (!$target) {
            $pdo->rollBack();
            featureResponse(['error' => 'Administrator not found.'], 404);
        }
        if ($target['admin_role'] === 'owner' && $role !== 'owner') {
            $owners = $pdo->query('SELECT id FROM users WHERE is_admin = 1 AND admin_role = "owner" FOR UPDATE')->fetchAll();
            if (count($owners) <= 1) {
                $pdo->rollBack();
                featureResponse(['error' => 'The last owner cannot be demoted. Assign another owner first.'], 409);
            }
        }
        $query = $pdo->prepare('UPDATE users SET admin_role = ? WHERE id = ? AND is_admin = 1');
        $query->execute([$role, $targetId]);
        $pdo->commit();
        auditAdmin($pdo, $userId, 'change_admin_role', 'user', (string) $targetId, $role);
        featureResponse(['saved' => true]);
    }

    if ($action === 'admin-user-access' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requirePermission($pdo, $userId, 'admin_access');
        $body = featureBody();
        $targetId = (int) ($body['user_id'] ?? 0);
        $isAdmin = filter_var($body['is_admin'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $role = (string) ($body['admin_role'] ?? 'moderator');
        if ($targetId < 1 || $targetId === $userId || $isAdmin === null
            || ($isAdmin && !in_array($role, ['owner', 'moderator', 'analyst'], true))) {
            featureResponse(['error' => 'Choose another user and a valid administrator access setting.'], 422);
        }

        $pdo->beginTransaction();
        $query = $pdo->prepare('SELECT is_admin, admin_role, suspended FROM users WHERE id = ? FOR UPDATE');
        $query->execute([$targetId]);
        $target = $query->fetch();
        if (!$target) {
            $pdo->rollBack();
            featureResponse(['error' => 'User not found.'], 404);
        }
        if ($isAdmin && (int) $target['suspended'] === 1) {
            $pdo->rollBack();
            featureResponse(['error' => 'Unsuspend this account before granting administrator access.'], 422);
        }
        if (!$isAdmin && (int) $target['is_admin'] === 1 && $target['admin_role'] === 'owner') {
            $owners = $pdo->query('SELECT id FROM users WHERE is_admin = 1 AND admin_role = "owner" FOR UPDATE')->fetchAll();
            if (count($owners) <= 1) {
                $pdo->rollBack();
                featureResponse(['error' => 'The last owner cannot be removed. Assign another owner first.'], 409);
            }
        }
        $query = $pdo->prepare('UPDATE users SET is_admin = ?, admin_role = ? WHERE id = ?');
        $query->execute([$isAdmin ? 1 : 0, $isAdmin ? $role : $target['admin_role'], $targetId]);
        $pdo->commit();
        auditAdmin($pdo, $userId, $isAdmin ? 'grant_admin_access' : 'revoke_admin_access', 'user', (string) $targetId, $isAdmin ? $role : '');
        featureResponse(['saved' => true]);
    }

    if ($action === 'admin-delete-user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requirePermission($pdo, $userId, 'owner');
        $targetId = (int) (featureBody()['user_id'] ?? 0);
        if ($targetId < 1 || $targetId === $userId) featureResponse(['error' => 'You cannot delete your own administrator account.'], 422);
        $query = $pdo->prepare('DELETE FROM users WHERE id = ? AND is_admin = 0');
        $query->execute([$targetId]);
        if (!$query->rowCount()) featureResponse(['error' => 'User not found or administrator accounts cannot be deleted here.'], 404);
        auditAdmin($pdo, $userId, 'delete_user', 'user', (string) $targetId);
        featureResponse(['deleted' => true]);
    }

    if ($action === 'featured-content' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requirePermission($pdo, $userId, 'content');
        $query = $pdo->query('SELECT id, title_id, media_type, title, image_url, note, sort_order, is_active, created_at FROM featured_content ORDER BY sort_order ASC, created_at DESC');
        featureResponse(['items' => $query->fetchAll()]);
    }

    if ($action === 'save-featured-content' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requirePermission($pdo, $userId, 'content_write');
        $body = featureBody();
        $id = (int) ($body['id'] ?? 0);
        $titleId = (int) ($body['title_id'] ?? 0);
        $type = ($body['media_type'] ?? '') === 'tv' ? 'tv' : 'movie';
        $title = trim((string) ($body['title'] ?? ''));
        if ($titleId < 1 || $title === '' || strlen($title) > 255) featureResponse(['error' => 'A valid title and TMDB ID are required.'], 422);
        if ($id > 0) {
            $query = $pdo->prepare('UPDATE featured_content SET title_id = ?, media_type = ?, title = ?, image_url = ?, note = ?, sort_order = ?, is_active = ? WHERE id = ?');
            $query->execute([$titleId, $type, $title, substr((string) ($body['image_url'] ?? ''), 0, 500), substr((string) ($body['note'] ?? ''), 0, 255), (int) ($body['sort_order'] ?? 0), !empty($body['is_active']) ? 1 : 0, $id]);
            $target = (string) $id;
        } else {
            $query = $pdo->prepare('INSERT INTO featured_content (title_id, media_type, title, image_url, note, sort_order, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $query->execute([$titleId, $type, $title, substr((string) ($body['image_url'] ?? ''), 0, 500), substr((string) ($body['note'] ?? ''), 0, 255), (int) ($body['sort_order'] ?? 0), !empty($body['is_active']) ? 1 : 0, $userId]);
            $target = (string) $pdo->lastInsertId();
        }
        auditAdmin($pdo, $userId, $id > 0 ? 'update_featured' : 'create_featured', 'featured', $target, $title);
        featureResponse(['saved' => true]);
    }

    if ($action === 'delete-featured-content' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requirePermission($pdo, $userId, 'content_write');
        $id = (int) (featureBody()['id'] ?? 0);
        $query = $pdo->prepare('DELETE FROM featured_content WHERE id = ?');
        $query->execute([$id]);
        if (!$query->rowCount()) featureResponse(['error' => 'Featured item not found.'], 404);
        auditAdmin($pdo, $userId, 'delete_featured', 'featured', (string) $id);
        featureResponse(['deleted' => true]);
    }

    if ($action === 'admin-reports' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requirePermission($pdo, $userId, 'moderation');
        $query = $pdo->query('SELECT id, reporter_id, target_type, target_key, reason, status, created_at, resolved_at FROM content_reports ORDER BY (status = "open") DESC, created_at DESC LIMIT 100');
        featureResponse(['items' => $query->fetchAll()]);
    }

    if ($action === 'admin-reviews' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requirePermission($pdo, $userId, 'moderation');
        $query = $pdo->query("SELECT ul.user_id, u.username, ul.item_key, ul.payload, ul.updated_at FROM user_library ul JOIN users u ON u.id = ul.user_id WHERE ul.collection = 'reviews' AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ul.payload, '$.deleted')), 'false') <> 'true' ORDER BY ul.updated_at DESC LIMIT 100");
        featureResponse(['items' => $query->fetchAll()]);
    }

    if ($action === 'delete-review' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requirePermission($pdo, $userId, 'moderation_write');
        $body = featureBody();
        $reviewUserId = (int) ($body['user_id'] ?? 0);
        $itemKey = substr((string) ($body['item_key'] ?? ''), 0, 100);
        $query = $pdo->prepare("SELECT payload FROM user_library WHERE user_id = ? AND collection = 'reviews' AND item_key = ?");
        $query->execute([$reviewUserId, $itemKey]);
        $payload = json_decode((string) $query->fetchColumn(), true);
        if (!is_array($payload)) featureResponse(['error' => 'Review not found.'], 404);
        $payload['deleted'] = true;
        $payload['updatedAt'] = max((int) ($payload['updatedAt'] ?? 0) + 1, (int) floor(microtime(true) * 1000));
        $query = $pdo->prepare("UPDATE user_library SET payload = ? WHERE user_id = ? AND collection = 'reviews' AND item_key = ?");
        $query->execute([json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $reviewUserId, $itemKey]);
        auditAdmin($pdo, $userId, 'delete_review', 'review', $itemKey, 'Removed by administrator');
        featureResponse(['deleted' => true]);
    }

    if ($action === 'resolve-report' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requirePermission($pdo, $userId, 'moderation_write');
        $body = featureBody();
        $id = (int) ($body['id'] ?? 0);
        $status = in_array(($body['status'] ?? ''), ['resolved', 'dismissed'], true) ? $body['status'] : 'resolved';
        $query = $pdo->prepare('UPDATE content_reports SET status = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?');
        $query->execute([$status, $userId, $id]);
        if (!$query->rowCount()) featureResponse(['error' => 'Report not found.'], 404);
        auditAdmin($pdo, $userId, 'resolve_report', 'report', (string) $id, $status);
        featureResponse(['saved' => true]);
    }

    if ($action === 'admin-audit' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requirePermission($pdo, $userId, 'security');
        $query = $pdo->query('SELECT a.action, a.target_type, a.target_key, a.details, a.created_at, u.username AS admin_username FROM admin_audit_logs a LEFT JOIN users u ON u.id = a.admin_id ORDER BY a.created_at DESC LIMIT 100');
        featureResponse(['items' => $query->fetchAll()]);
    }

    if ($action === 'visitor-logs' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requirePermission($pdo, $userId, 'analytics');
        $days = max(1, min(90, (int) ($_GET['days'] ?? 30)));
        $search = trim((string) ($_GET['search'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(10, min(100, (int) ($_GET['per_page'] ?? 25)));
        $sql = 'FROM visitor_logs WHERE visited_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
        $params = [$days];
        if ($search !== '') {
            $sql .= ' AND (ip_address LIKE ? OR page_url LIKE ? OR user_agent LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        $query = $pdo->prepare('SELECT COUNT(*) ' . $sql);
        $query->execute($params);
        $total = (int) $query->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $query = $pdo->prepare('SELECT id, ip_address, device_type, country_code, city, region, user_agent, page_url, referrer, visited_at ' . $sql . ' ORDER BY visited_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
        $query->execute($params);
        featureResponse(['items' => $query->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages]);
    }

    if ($action === 'visitor-analytics' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requirePermission($pdo, $userId, 'analytics');
        $days = max(1, min(90, (int) ($_GET['days'] ?? 30)));
        $query = $pdo->prepare('SELECT DATE(visited_at) AS day, COUNT(*) AS visits FROM visitor_logs WHERE visited_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY DATE(visited_at) ORDER BY day');
        $query->execute([$days]);
        $daily = $query->fetchAll();
        $query = $pdo->prepare('SELECT page_url, COUNT(*) AS visits FROM visitor_logs WHERE visited_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY page_url ORDER BY visits DESC LIMIT 10');
        $query->execute([$days]);
        $pages = $query->fetchAll();
        $query = $pdo->prepare('SELECT device_type, COUNT(*) AS visits FROM visitor_logs WHERE visited_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY device_type ORDER BY visits DESC');
        $query->execute([$days]);
        $devices = $query->fetchAll();
        featureResponse(['daily' => $daily, 'pages' => $pages, 'devices' => $devices]);
    }

    if ($action === 'delete-old-visitors' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requirePermission($pdo, $userId, 'owner');
        $days = max(30, min(3650, (int) (featureBody()['days'] ?? 180)));
        $query = $pdo->prepare('DELETE FROM visitor_logs WHERE visited_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
        $query->execute([$days]);
        featureResponse(['deleted' => $query->rowCount()]);
    }

    if ($action === 'export-visitor-csv' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requirePermission($pdo, $userId, 'analytics');
        $rows = $pdo->query('SELECT visited_at, ip_address, device_type, country_code, city, region, page_url, user_agent, referrer FROM visitor_logs ORDER BY visited_at DESC LIMIT 10000')->fetchAll();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="visitor-logs.csv"');
        $output = fopen('php://output', 'wb');
        fputcsv($output, ['visited_at', 'ip_address', 'device_type', 'country_code', 'city', 'region', 'page_url', 'user_agent', 'referrer']);
        foreach ($rows as $row) fputcsv($output, $row);
        fclose($output);
        exit;
    }

    if ($action === 'security-events' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requirePermission($pdo, $userId, 'security');
        $query = $pdo->query('SELECT event_type, email, ip_address, created_at FROM security_events ORDER BY created_at DESC LIMIT 100');
        featureResponse(['items' => $query->fetchAll()]);
    }

    if ($action === 'ip-bans' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requirePermission($pdo, $userId, 'security');
        $query = $pdo->query('SELECT ip_address, reason, created_at FROM ip_bans ORDER BY created_at DESC');
        featureResponse(['items' => $query->fetchAll()]);
    }

    if ($action === 'ban-ip' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requirePermission($pdo, $userId, 'security_write');
        $body = featureBody();
        $ip = trim((string) ($body['ip_address'] ?? ''));
        $reason = substr(trim((string) ($body['reason'] ?? '')), 0, 255);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) featureResponse(['error' => 'Enter a valid IP address.'], 422);
        $query = $pdo->prepare('INSERT INTO ip_bans (ip_address, reason, banned_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE reason = VALUES(reason), banned_by = VALUES(banned_by)');
        $query->execute([$ip, $reason, $userId]);
        $pdo->prepare('INSERT INTO security_events (event_type, email, ip_address) VALUES (?, ?, ?)')->execute(['ip_banned', '', $ip]);
        featureResponse(['saved' => true]);
    }

    if ($action === 'unban-ip' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requirePermission($pdo, $userId, 'security_write');
        $ip = trim((string) (featureBody()['ip_address'] ?? ''));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) featureResponse(['error' => 'Enter a valid IP address.'], 422);
        $query = $pdo->prepare('DELETE FROM ip_bans WHERE ip_address = ?');
        $query->execute([$ip]);
        $pdo->prepare('INSERT INTO security_events (event_type, email, ip_address) VALUES (?, ?, ?)')->execute(['ip_unbanned', '', $ip]);
        featureResponse(['deleted' => $query->rowCount()]);
    }

    featureResponse(['error' => 'Unknown feature action.'], 400);
} catch (Throwable $error) {
    error_log('Nitpliks feature error: ' . $error->getMessage());
    featureResponse(['error' => 'Feature service is temporarily unavailable.'], 503);
}
