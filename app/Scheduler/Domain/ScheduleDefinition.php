<?php

declare(strict_types=1);

namespace App\Scheduler\Domain;

final readonly class ScheduleDefinition
{
    public const string DEFAULT_TIMEZONE = 'UTC';

    public function __construct(
        public string $id,
        public string $task,
        public string $cron,
        public string $timezone = self::DEFAULT_TIMEZONE,
    ) {}
}
