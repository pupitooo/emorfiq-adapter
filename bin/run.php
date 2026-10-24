<?php

declare(strict_types=1);

use App\Scheduler\Infrastructure\Configuration\InMemorySchedulerConfiguration;
use App\Scheduler\Infrastructure\Laravel\LaravelAdapter;
use App\Task\PingTask;
use Psr\Log\LoggerInterface;

require __DIR__ . '/../vendor/autoload.php';

/** @var LoggerInterface $logger */
$logger = require __DIR__ . '/../config/logger.php';

$configuration = new InMemorySchedulerConfiguration(__DIR__ . '/../config/scheduler.json');
$adapter = new LaravelAdapter(new PingTask($logger), $configuration);

$adapter->run();
