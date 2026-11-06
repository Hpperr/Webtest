<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use TrangThu\Core\Config;
use TrangThu\Core\Database;
use TrangThu\Notifications\NotificationRepository;

$baseUrl = rtrim(Config::get('REALTIME_URL', 'http://127.0.0.1:3100'), '/');
$serviceToken = Config::get('REALTIME_SERVICE_TOKEN');
if (strlen($serviceToken) < 32 || preg_match('/[\r\n]/', $serviceToken) === 1
    || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
    throw new RuntimeException('Realtime URL and service token must be configured before running the notification worker.');
}

$repository = new NotificationRepository(Database::connection());
$job = $repository->claimNext();
if ($job === null) {
    fwrite(STDOUT, "No queued notifications.\n");
    exit(0);
}

$payload = json_encode([
    'userId' => (int) $job['user_id'],
    'type' => $job['event_type'],
    'payload' => $job['payload'],
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$context = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$serviceToken}\r\n",
    'content' => $payload,
    'timeout' => 5,
    'ignore_errors' => true,
]]);

try {
    error_clear_last();
    $response = file_get_contents($baseUrl . '/publish', false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if ($response === false || preg_match('/\s202\s/', $statusLine) !== 1) {
        $lastError = error_get_last();
        throw new RuntimeException($lastError['message'] ?? 'Realtime service did not accept the notification.');
    }
    $repository->markSent((int) $job['id']);
    fwrite(STDOUT, sprintf("Delivered notification outbox item %d.\n", $job['id']));
} catch (Throwable $error) {
    $repository->retry((int) $job['id'], (int) $job['attempts'], $error->getMessage());
    error_log(sprintf('[ERROR] [Notification worker] Outbox item %d failed: %s', $job['id'], $error->getMessage()));
    fwrite(STDERR, sprintf("Notification outbox item %d will be retried or marked failed.\n", $job['id']));
    exit(1);
}
