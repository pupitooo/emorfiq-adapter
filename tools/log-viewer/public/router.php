<?php

declare(strict_types=1);

use DevTools\LogViewer\LogReader;
use DevTools\LogViewer\LogStream;

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = is_string($uri) ? parse_url($uri, PHP_URL_PATH) : '/';

if ($path === '/health') {
    header('Content-Type: application/json');
    echo '{"status":"ok"}';
    return true;
}

if ($path !== '/api/logs/stream') {
    return false;
}

$applicationRoot = dirname(__DIR__, 3);
require $applicationRoot . '/vendor/autoload.php';

$lastEventId = $_SERVER['HTTP_LAST_EVENT_ID'] ?? null;
$cursor = is_string($lastEventId) && ctype_digit($lastEventId) ? (int) $lastEventId : null;
$reader = new LogReader($applicationRoot . '/storage/logs/adapter.log');

new LogStream($reader)->run($cursor);

return true;
