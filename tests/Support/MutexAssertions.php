<?php

declare(strict_types=1);

namespace Tests\Support;

use LogicException;

final readonly class MutexAssertions
{
    public static function same(mixed $expected, mixed $actual): void
    {
        if ($expected !== $actual) {
            throw new LogicException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }
}
