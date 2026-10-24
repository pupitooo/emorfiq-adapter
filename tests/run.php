<?php

declare(strict_types=1);

use Tests\Integration\Scheduler\InMemorySchedulerConfigurationTest;
use Tests\Unit\Scheduler\InvalidJsonConfigurationParserTest;
use Tests\Unit\Scheduler\JsonConfigurationParserTest;
use Tests\Unit\Task\PingTaskTest;

require __DIR__ . '/../vendor/autoload.php';

new PingTaskTest()->run();
new JsonConfigurationParserTest()->run();
new InvalidJsonConfigurationParserTest()->run();
new InMemorySchedulerConfigurationTest()->run();
