<?php

declare(strict_types=1);

namespace App\Scheduler\Domain;

final readonly class ScheduleDefinition
{
    public const string DEFAULT_TIMEZONE = 'UTC';

    public const int DEFAULT_OVERLAP_EXPIRES_AFTER_MINUTES = 60; // 1 hour

    public function __construct(
        public string $id,
        public string $task,
        public string $cron,
        public string $timezone = self::DEFAULT_TIMEZONE,
        public bool $withoutOverlapping = false,
        public int $overlapExpiresAfterMinutes = self::DEFAULT_OVERLAP_EXPIRES_AFTER_MINUTES,
        public bool $onOneServer = false,
    ) {}
}
