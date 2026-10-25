<?php

declare(strict_types=1);

use Tests\Contract\Recovery\CoordinationContractTest;
use Tests\Contract\Recovery\ExecutionContractTest;
use Tests\Contract\Recovery\ExternalEffectsContractTest;
use Tests\Contract\Recovery\OwnershipContractTest;
use Tests\Contract\Recovery\RecoveryContractTest;
use Tests\Support\Recovery\RecoveryHarnessFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$list = in_array('--list', $argv ?? [], true);
$failures = 0;
$total = 0;
foreach ([
    new CoordinationContractTest(),
    new RecoveryContractTest(),
    new OwnershipContractTest(),
    new ExecutionContractTest(),
    new ExternalEffectsContractTest(),
] as $suite) {
    foreach ($suite->cases() as $test) {
        $name = $suite::class . '::' . new ReflectionFunction($test)->getName();
        $total++;
        if ($list) {
            echo $name . PHP_EOL;
            continue;
        }

        $harness = null;
        $errors = [];
        try {
            $harness = RecoveryHarnessFactory::create();
            $test($harness);
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
        } finally {
            try {
                $harness?->close();
            } catch (Throwable $error) {
                $errors[] = 'Cleanup failed: ' . $error->getMessage();
            }
        }
        if ($errors === []) {
            echo 'PASS ' . $name . PHP_EOL;
        } else {
            $failures++;
            echo 'FAIL ' . $name . ': ' . implode('; ', $errors) . PHP_EOL;
        }
    }
}

echo $list
    ? $total . ' scenarios listed; no tests executed.' . PHP_EOL
    : $total . ' recovery scenarios; ' . $failures . ' failures.' . PHP_EOL;
exit($failures > 0 ? 1 : 0);
