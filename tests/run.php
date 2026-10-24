<?php

declare(strict_types=1);

use Tests\Integration\Scheduler\DueEventsTest;
use Tests\Integration\Scheduler\InMemorySchedulerConfigurationTest;
use Tests\Integration\Scheduler\LaravelAdapterTest;
use Tests\Integration\Scheduler\SchedulerApplicationTest;
use Tests\Integration\Task\SymfonyTaskResolverTest;
use Tests\Unit\Scheduler\CronConfigurationParserTest;
use Tests\Unit\Scheduler\InvalidJsonConfigurationParserTest;
use Tests\Unit\Scheduler\JsonConfigurationParserTest;
use Tests\Unit\Task\PingTaskTest;

$projectDir = dirname(__DIR__);

require $projectDir . '/vendor/autoload.php';

new PingTaskTest()->run();
new JsonConfigurationParserTest()->run();
new InvalidJsonConfigurationParserTest()->run();
new CronConfigurationParserTest()->run();
new InMemorySchedulerConfigurationTest()->run();
new SymfonyTaskResolverTest()->run();
new LaravelAdapterTest()->run();
new DueEventsTest()->run();
new SchedulerApplicationTest()->run();
