<?php

declare(strict_types=1);

use Tests\Support\Recovery\RecoveryInput;
use Tests\Support\Recovery\RecoveryWorker;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    RecoveryWorker::run(RecoveryInput::read());
} catch (Throwable $failure) {
    fwrite(STDERR, $failure::class . ': ' . $failure->getMessage() . PHP_EOL . $failure->getTraceAsString());
    exit(1);
}
