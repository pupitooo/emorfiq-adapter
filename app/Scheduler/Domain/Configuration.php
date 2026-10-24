<?php

declare(strict_types=1);

namespace App\Scheduler\Domain;

final readonly class Configuration
{
    public const int FORMAT_VERSION = 1;

    /**
     * @param list<ScheduleDefinition> $tasks
     */
    public function __construct(
        public int $version,
        public array $tasks,
    ) {}
}
