<?php

declare(strict_types=1);

namespace Tests\Support;

use LogicException;

final readonly class SchedulerFixtures
{
    public static function read(string $fixture): string
    {
        $json = file_get_contents(__DIR__ . '/../Fixtures/Scheduler/' . $fixture);
        if ($json === false) {
            throw new LogicException('Unable to read scheduler test fixture: ' . $fixture);
        }

        return $json;
    }
}
