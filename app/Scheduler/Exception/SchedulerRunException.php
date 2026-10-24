<?php

declare(strict_types=1);

namespace App\Scheduler\Exception;

use RuntimeException;

final class SchedulerRunException extends RuntimeException
{
    /**
     * @param non-empty-list<TaskExecutionException> $failures
     */
    public function __construct(
        public readonly array $failures,
    ) {
        $identifiers = array_map(
            static fn(TaskExecutionException $failure): string => $failure->definition->id,
            $failures,
        );
        parent::__construct(
            sprintf('%d scheduled task(s) failed: %s.', count($failures), implode(', ', $identifiers)),
            previous: $failures[0],
        );
    }
}
