<?php

declare(strict_types=1);

namespace App\Scheduler\Exception;

use App\Scheduler\Domain\ScheduleDefinition;
use RuntimeException;
use Throwable;

final class TaskExecutionException extends RuntimeException
{
    public function __construct(
        public readonly ScheduleDefinition $definition,
        Throwable $previous,
    ) {
        parent::__construct('Scheduled task failed: ' . $definition->id, previous: $previous);
    }
}
