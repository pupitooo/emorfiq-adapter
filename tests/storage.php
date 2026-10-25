<?php

declare(strict_types=1);

use Tests\Integration\Storage\AdapterUiStorageTest;
use Tests\Integration\Storage\PostgresLockStorageTest;
use Tests\Integration\Storage\PostgresStorageFailureTest;
use Tests\Integration\Storage\PostgresStorageRaceTest;
use Tests\Integration\Storage\PostgresTaskRunStorageTest;
use Tests\Support\Storage\StorageTestRunner;

require dirname(__DIR__) . '/vendor/autoload.php';

$runner = new StorageTestRunner();
new PostgresTaskRunStorageTest()->run($runner);
new PostgresLockStorageTest()->run($runner);
new AdapterUiStorageTest()->run($runner);
new PostgresStorageRaceTest()->run($runner);
new PostgresStorageFailureTest()->run($runner);
echo $runner->total . ' storage scenarios; ' . $runner->failures . ' failures.' . PHP_EOL;
exit($runner->failures > 0 ? 1 : 0);
