<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use Closure;
use ReflectionFunction;

/**
 * Stores a contract callback whose checked failures are caught by tests/recovery.php.
 */
final readonly class RecoveryCase
{
    public string $name;

    /**
     * @param Closure(RecoveryHarness): void $callback
     */
    public function __construct(
        private Closure $callback
    ) {
        try {
            $this->name = new ReflectionFunction($callback)->getName();
        } catch (\ReflectionException $failure) {
            throw new \LogicException('Unable to name recovery case.', previous: $failure);
        }
    }

    public function run(RecoveryHarness $harness): void
    {
        ($this->callback)($harness);
    }
}
