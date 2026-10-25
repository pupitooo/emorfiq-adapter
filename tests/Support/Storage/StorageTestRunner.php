<?php

declare(strict_types=1);

namespace Tests\Support\Storage;

use Closure;
use ReflectionFunction;
use Throwable;

final class StorageTestRunner
{
    public private(set) int $total = 0;

    public private(set) int $failures = 0;

    /**
     * @param Closure(StorageDatabase): void $test
     */
    public function run(Closure $test): void
    {
        $this->total++;
        $database = null;
        $name = 'storage scenario';
        try {
            $name = new ReflectionFunction($test)->getName();
            $database = new StorageDatabase();
            $test($database);
            echo 'PASS ' . $name . PHP_EOL;
        } catch (Throwable $failure) {
            $this->failures++;
            echo 'FAIL ' . $name . ': ' . $failure->getMessage() . PHP_EOL;
        } finally {
            $this->close($database);
        }
    }

    private function close(?StorageDatabase $database): void
    {
        try {
            $database?->close();
        } catch (Throwable $failure) {
            $this->failures++;
            echo 'FAIL storage cleanup: ' . $failure->getMessage() . PHP_EOL;
        }
    }
}
