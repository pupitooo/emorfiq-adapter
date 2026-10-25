<?php

declare(strict_types=1);

namespace App\Scheduler\Contract;

use App\Scheduler\Domain\RunClaim;
use App\Scheduler\Domain\RunOutcome;
use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Exception\MutexStorageException;
use App\Scheduler\Exception\ReservationUnconfirmedException;
use DateTimeImmutable;

/**
 * Storage boundary for execution coordination.
 * Implementations own SQL, transaction boundaries, database time, and driver error translation.
 * Operations confirm writes and close transactions before returning control to the task runner.
 */
interface TaskRunStorage
{
    /**
     * @see ExecutionMutex::isOverlapLocked()
     * @throws MutexStorageException
     */
    public function hasLiveOverlap(string $scheduleId): bool;

    /**
     * @see ExecutionMutex::isOccurrenceReserved()
     * @throws MutexStorageException
     */
    public function hasOccurrence(string $scheduleId, DateTimeImmutable $scheduledFor): bool;

    /**
     * Recovers this schedule's expired locks, records a run and acquires requested locks in one transaction.
     * Unprotected executions record history without creating locks. Conflicts retain no new run or locks.
     *
     * @see ExecutionMutex::claim()
     * @throws MutexStorageException
     * @throws ReservationUnconfirmedException
     */
    public function reserve(
        ScheduleDefinition $definition,
        DateTimeImmutable $scheduledFor,
        string $claimedBy,
    ): ?RunClaim;

    /**
     * Conditionally records completion and removes only that owner's overlap lock in one transaction.
     *
     * @see ExecutionMutex::finish()
     * @throws MutexStorageException
     */
    public function complete(RunClaim $claim, RunOutcome $outcome, ?string $error): bool;

    /**
     * Removes expired overlap locks and marks linked unfinished runs abandoned in one transaction.
     * Rechecks expiry after row-lock waits; orphaned locks remain recoverable after history deletion.
     *
     * @see ExecutionMutex::recover()
     * @throws MutexStorageException
     */
    public function abandonExpired(string $scheduleId, string $recoveredBy): int;
}
