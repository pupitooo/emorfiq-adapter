<?php

declare(strict_types=1);

use App\Scheduler\Infrastructure\Laravel\LaravelAdapter;
use App\Task\PingTask;
use Psr\Log\LoggerInterface;

require __DIR__ . '/../vendor/autoload.php';

/** @var LoggerInterface $logger */
$logger = require __DIR__ . '/../config/logger.php';
$task = new PingTask($logger);
$adapter = new LaravelAdapter($task);

$adapter->run();
