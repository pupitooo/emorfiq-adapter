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
 * Coordinates scheduled executions independently of scheduler and storage implementations.
 */
interface ExecutionMutex
{
    /**
     * Reports whether this schedule has a live overlap-protected reservation.
     * Expired locks return false so a subsequent claim can recover them.
     * This advisory read does not authorize execution or replace an atomic claim.
     *
     * @throws MutexStorageException
     */
    public function isOverlapLocked(string $scheduleId): bool;

    /**
     * Reports whether single-server protection has reserved this UTC occurrence, including terminal runs.
     *
     * @throws MutexStorageException
     */
    public function isOccurrenceReserved(string $scheduleId, DateTimeImmutable $scheduledFor): bool;

    /**
     * Atomically recovers expired overlap locks for this schedule and reserves its occurrence.
     * Returns only after commit is confirmed and the reservation transaction is closed.
     * Callers may resolve and execute the task only after receiving a claim.
     *
     * @param DateTimeImmutable $scheduledFor Intended occurrence, normalized to a UTC minute.
     * @param string $claimedBy Unique scheduler process instance identifier, used for recovery attribution.
     * @return RunClaim|null Committed reservation, or null when concurrency protection denies the claim.
     * @throws MutexStorageException When a storage operation fails.
     * @throws ReservationUnconfirmedException When commit cannot be confirmed; do not execute or blindly retry.
     */
    public function claim(
        ScheduleDefinition $definition,
        DateTimeImmutable $scheduledFor,
        string $claimedBy,
    ): ?RunClaim;

    /**
     * Records the original owner's result by run ID and token, including after expiry or abandonment.
     * Preserves abandonment history and leaves replacement runs and confirmed results unchanged.
     *
     * @param string|null $error Failure diagnostic for Failed; null for Succeeded.
     * @return bool Whether completion was recorded; false indicates rejection, not a storage failure.
     * @throws MutexStorageException When completion cannot be confirmed.
     */
    public function finish(RunClaim $claim, RunOutcome $outcome, ?string $error = null): bool;

    /**
     * Marks expired overlap-protected in-progress runs for this schedule as abandoned.
     * Records recovery time and actor without setting completion time or overwriting prior recovery history.
     * Recovery neither stops nor retries tasks. A lock is expired at or after its deadline.
     *
     * @param string $recoveredBy Unique process instance identifier responsible for recovery.
     * @return int Number of newly abandoned runs.
     * @throws MutexStorageException When recovery cannot be confirmed.
     */
    public function recover(string $scheduleId, string $recoveredBy): int;
}
