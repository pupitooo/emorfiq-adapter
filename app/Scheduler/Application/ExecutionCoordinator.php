<?php

declare(strict_types=1);

namespace App\Scheduler\Application;

use App\Scheduler\Contract\ExecutionMutex;
use App\Scheduler\Contract\TaskRunStorage;
use App\Scheduler\Domain\RunClaim;
use App\Scheduler\Domain\RunOutcome;
use App\Scheduler\Domain\ScheduleDefinition;
use DateTimeImmutable;
use Override;

/**
 * Delegates execution coordination to interchangeable storage.
 */
final readonly class ExecutionCoordinator implements ExecutionMutex
{
    public function __construct(
        private TaskRunStorage $storage,
    ) {}

    #[Override]
    public function isOverlapLocked(string $scheduleId): bool
    {
        return $this->storage->hasLiveOverlap($scheduleId);
    }

    #[Override]
    public function isOccurrenceReserved(string $scheduleId, DateTimeImmutable $scheduledFor): bool
    {
        return $this->storage->hasOccurrence($scheduleId, $scheduledFor);
    }

    #[Override]
    public function claim(
        ScheduleDefinition $definition,
        DateTimeImmutable $scheduledFor,
        string $claimedBy,
    ): ?RunClaim {
        return $this->storage->reserve($definition, $scheduledFor, $claimedBy);
    }

    #[Override]
    public function finish(RunClaim $claim, RunOutcome $outcome, ?string $error = null): bool
    {
        return $this->storage->complete($claim, $outcome, $error);
    }

    #[Override]
    public function recover(string $scheduleId, string $recoveredBy): int
    {
        return $this->storage->abandonExpired($scheduleId, $recoveredBy);
    }
}
