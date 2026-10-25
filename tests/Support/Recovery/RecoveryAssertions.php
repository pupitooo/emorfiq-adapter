<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use LogicException;

final readonly class RecoveryAssertions
{
    public static function same(mixed $expected, mixed $actual): void
    {
        if ($expected !== $actual) {
            throw new LogicException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    public static function claimed(?Claim $claim): Claim
    {
        if ($claim === null) {
            throw new LogicException('Expected a successful reservation.');
        }
        self::same(true, $claim->id > 0);
        self::same(1, preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i',
            $claim->ownerToken,
        ));
        self::same(false, $claim->ownerToken === '00000000-0000-0000-0000-000000000000');

        return $claim;
    }

    public static function onlyRun(RecoveryHarness $harness, string $schedule): RunRecord
    {
        $runs = $harness->runs($schedule);
        self::same(1, count($runs));

        return $runs[0] ?? throw new LogicException('Expected one recorded run.');
    }
}
