<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

session_start();
require __DIR__ . '/config.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function syncResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function requireUserId(): int
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        syncResponse(['error' => 'Sign in to sync your library.'], 401);
    }
    return $userId;
}

function readSyncBody(): array
{
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($data) ? $data : [];
}

function cleanItems(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    return array_values(array_filter($value, static fn ($item): bool => is_array($item) && !empty($item['id'])));
}

try {
    $userId = requireUserId();
    $pdo = database();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $result = ['watchlist' => [], 'reviews' => [], 'progress' => []];
        $statement = $pdo->prepare('SELECT collection, item_key, payload FROM user_library WHERE user_id = ?');
        $statement->execute([$userId]);
        foreach ($statement as $row) {
            if (array_key_exists($row['collection'], $result)) {
                $payload = json_decode($row['payload'], true);
                if (is_array($payload)) {
                    $result[$row['collection']][] = $payload;
                }
            }
        }
        syncResponse($result);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        syncResponse(['error' => 'This action requires GET or POST.'], 405);
    }

    if (!hash_equals($_SESSION['csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        syncResponse(['error' => 'Security token expired. Refresh the page and try again.'], 419);
    }

    $body = readSyncBody();
    $allowed = ['watchlist', 'reviews', 'progress'];
    $pdo->beginTransaction();
    $select = $pdo->prepare('SELECT payload FROM user_library WHERE user_id = ? AND collection = ?');
    $delete = $pdo->prepare('DELETE FROM user_library WHERE user_id = ? AND collection = ?');
    $insert = $pdo->prepare('INSERT INTO user_library (user_id, collection, item_key, payload) VALUES (?, ?, ?, ?)');

    foreach ($allowed as $collection) {
        if (!array_key_exists($collection, $body)) {
            continue;
        }
        $items = cleanItems($body[$collection]);
        $incoming = [];
        foreach ($items as $item) {
            $identity = (string) $item['id'] . ':' . (string) ($item['type'] ?? '');
            $incoming[$identity] = $item;
        }

        $select->execute([$userId, $collection]);
        $tombstones = [];
        foreach ($select as $row) {
            $existing = json_decode($row['payload'], true);
            if (!is_array($existing) || empty($existing['id'])) {
                continue;
            }
            $identity = (string) $existing['id'] . ':' . (string) ($existing['type'] ?? '');
            if (!empty($existing['deleted'])) {
                if (!isset($incoming[$identity])) {
                    $tombstones[$identity] = $existing;
                }
                continue;
            }
            if (!isset($incoming[$identity])) {
                $existing['deleted'] = true;
                $existing['updatedAt'] = max(
                    (int) ($existing['updatedAt'] ?? 0) + 1,
                    (int) floor(microtime(true) * 1000)
                );
                $tombstones[$identity] = $existing;
            }
        }

        $delete->execute([$userId, $collection]);
        foreach (array_merge(array_values($incoming), array_values($tombstones)) as $item) {
            $itemKey = substr((string) $item['id'] . ':' . (string) ($item['type'] ?? ''), 0, 100);
            $insert->execute([$userId, $collection, $itemKey, json_encode($item, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        }
    }

    $pdo->commit();
    syncResponse(['saved' => true]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Nitpliks library sync error: ' . $error->getMessage());
    syncResponse(['error' => 'Unable to sync your library right now.'], 503);
}
