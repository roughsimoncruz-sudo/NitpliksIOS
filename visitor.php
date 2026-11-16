<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/config.php';

function visitorResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function visitorBody(): array
{
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($body) ? $body : [];
}

function visitorIp(): string
{
    return clientIp();
}

function visitorDevice(string $userAgent): string
{
    if (preg_match('/bot|crawler|spider|slurp/i', $userAgent)) return 'Bot';
    if (preg_match('/tablet|ipad|playbook|silk/i', $userAgent)) return 'Tablet';
    if (preg_match('/mobile|android|iphone|ipod|windows phone/i', $userAgent)) return 'Mobile';
    return 'Desktop';
}

function visitorCountryCode(): string
{
    $country = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
    if (!preg_match('/^[A-Z]{2}$/D', $country) || $country === 'XX') return '';
    return $country;
}

function visitorLocationHeader(string $header): string
{
    $value = trim((string) ($_SERVER[$header] ?? ''));
    $value = preg_replace('/[\p{C}]/u', '', $value) ?? '';
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    if (!preg_match('/^.{0,100}/us', $value, $matches)) return '';
    return trim($matches[0]);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        visitorResponse(['allowed' => true]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        visitorResponse(['error' => 'This action requires a POST request.'], 405);
    }

    $body = visitorBody();
    $page = substr(trim((string) ($body['page'] ?? '')), 0, 500);
    $userAgent = substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 1000);
    $referrer = substr(trim((string) ($_SERVER['HTTP_REFERER'] ?? '')), 0, 500);

    database()->prepare(
        'INSERT INTO visitor_logs (ip_address, device_type, country_code, city, region, user_agent, page_url, referrer) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        visitorIp(),
        visitorDevice($userAgent),
        visitorCountryCode(),
        visitorLocationHeader('HTTP_CF_IPCITY'),
        visitorLocationHeader('HTTP_CF_REGION'),
        $userAgent,
        $page,
        $referrer,
    ]);

    visitorResponse(['logged' => true], 201);
} catch (Throwable $error) {
    error_log('Nitpliks visitor logging error: ' . $error->getMessage());
    visitorResponse(['error' => 'Unable to record visit.'], 503);
}
