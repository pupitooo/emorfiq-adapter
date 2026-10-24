<?php

declare(strict_types=1);

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

$handler = new StreamHandler(__DIR__ . '/../storage/logs/adapter.log', Level::Info, useLocking: true);
$handler->setFormatter(new JsonFormatter());

return new Logger('tasks', [$handler]);
