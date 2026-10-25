<?php

declare(strict_types=1);

use DevTools\AdapterUi\Api;
use DevTools\AdapterUi\LogReader;
use DevTools\AdapterUi\LogStream;

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = is_string($uri) ? parse_url($uri, PHP_URL_PATH) : '/';

if ($path === '/health') {
    header('Content-Type: application/json');
    echo '{"status":"ok"}';
    return true;
}

if (! is_string($path) || ! str_starts_with($path, '/api/')) {
    return false;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    return true;
}

$applicationRoot = dirname(__DIR__, 3);
require $applicationRoot . '/vendor/autoload.php';

if ($path === '/api/logs/stream') {
    $lastEventId = $_SERVER['HTTP_LAST_EVENT_ID'] ?? null;
    $cursor = is_string($lastEventId) && ctype_digit($lastEventId) ? (int) $lastEventId : null;
    new LogStream(new LogReader($applicationRoot . '/storage/logs/adapter.log'))->run($cursor);
} else {
    new Api($applicationRoot)->run($path, $_GET);
}

return true;
