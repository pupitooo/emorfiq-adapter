<?php

declare(strict_types=1);

namespace App\Scheduler\Application;

use App\Scheduler\Contract\ExecutionMutex;
use App\Scheduler\Domain\RunClaim;
use App\Scheduler\Domain\RunOutcome;
use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Exception\CompletionRejectedException;
use App\Scheduler\Exception\MutexStorageException;
use App\Scheduler\Exception\ReservationUnconfirmedException;
use App\Scheduler\Exception\TaskExecutionException;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Coordinates one scheduled occurrence shared by both Laravel mutex adapters and the task callback.
 * `onOneServer` reserves this occurrence.
 * `withoutOverlapping` blocks concurrent runs of this schedule.
 * When both are enabled, one claim enforces both protections and is reused by every scheduling gate.
 * Atomic reservation, expiry recovery and completion persistence belong to ExecutionMutex and its storage.
 */
final class ScheduledExecution
{
    private bool $claimAttempted = false;

    private ?RunClaim $claim = null;

    /**
     * Binds scheduling gates and task execution to the same definition, occurrence and process identity.
     * Construction does not access storage or reserve execution; claim() does so only when needed.
     *
     * @param DateTimeImmutable $scheduledFor Intended occurrence, normalized to a UTC minute by the adapter.
     */
    public function __construct(
        private readonly ScheduleDefinition $definition,
        private readonly DateTimeImmutable $scheduledFor,
        private readonly string $processId,
        private readonly ExecutionMutex $mutex,
        private readonly TaskExecutor $executor,
    ) {}

    /**
     * Attempts one atomic reservation using both protection flags from the definition.
     * SchedulingMutex::create(), EventMutex::create() and execute() share the confirmed claim,
     * so combining onOneServer and withoutOverlapping never makes this execution compete with itself.
     * A denial is cached too; a failed or unconfirmed attempt is not retried until forget() resets state.
     *
     * @return RunClaim|null Confirmed reservation, or null when concurrency protection denies execution.
     * @throws MutexStorageException
     * @throws ReservationUnconfirmedException
     */
    public function claim(): ?RunClaim
    {
        if (! $this->claimAttempted) {
            $this->claimAttempted = true;
            $this->claim = $this->mutex->claim(
                definition: $this->definition,
                scheduledFor: $this->scheduledFor,
                claimedBy: $this->processId,
            );
        }

        return $this->claim;
    }

    /**
     * Provides EventMutex::exists() with an advisory check for a live withoutOverlapping reservation.
     * An already acquired local claim returns false: this execution must not block on its own reservation
     * when onOneServer acquired it before the overlap gate. Without a local claim, storage decides.
     * A false result still requires claim() before execution because another process may win the race.
     *
     * @throws MutexStorageException
     */
    public function isOverlapLocked(): bool
    {
        return $this->claim === null && $this->mutex->isOverlapLocked($this->definition->id);
    }

    /**
     * Provides SchedulingMutex::exists() with the reservation state of this onOneServer occurrence.
     * A local claim already proves reservation; otherwise storage checks this schedule and UTC minute.
     * Stored single-server reservations remain effective after completion, preventing the same occurrence
     * from running again even when its overlap lock no longer blocks subsequent occurrences.
     *
     * @throws MutexStorageException
     */
    public function isOccurrenceReserved(): bool
    {
        return $this->claim !== null
            || $this->mutex->isOccurrenceReserved(
                scheduleId: $this->definition->id,
                scheduledFor: $this->scheduledFor,
            );
    }

    /**
     * Runs the task callback after the scheduler gates have passed.
     * Always records a run, acquiring locks only for enabled protections.
     * Reuses the gates' claim or acquires one before resolving the task;
     * a denied claim skips execution. After a claimed run, clears local state even if execution or finish fails.
     *
     * @throws CompletionRejectedException
     * @throws MutexStorageException
     * @throws ReservationUnconfirmedException
     * @throws TaskExecutionException
     */
    public function execute(): void
    {
        $claim = $this->claim();
        if ($claim === null) {
            return;
        }
        try {
            $this->executeClaimed($claim);
        } finally {
            $this->forget();
        }
    }

    /**
     * Clears only the cached claim and attempt flag, also serving Laravel's EventMutex::forget() hook.
     * Persistent completion belongs to finish(); this reset neither releases a stored lock nor erases
     * the onOneServer occurrence reservation. Repeated cleanup after execute() is harmless.
     */
    public function forget(): void
    {
        $this->claim = null;
        $this->claimAttempted = false;
    }

    /**
     * Lets EventExecutions reject a SchedulingMutex lookup for a different occurrence.
     * Accepts any instant within the registered minute, including the scheduler's start time with seconds.
     * Timestamp comparison makes this check independent of the supplied timezone.
     */
    public function isScheduledFor(DateTimeInterface $instant): bool
    {
        $start = $this->scheduledFor->getTimestamp();

        return $instant->getTimestamp() >= $start && $instant->getTimestamp() < $start + 60;
    }

    /**
     * Executes an owned reservation and records exactly one outcome for that run.
     * TaskExecutionException is recorded as Failed with the underlying diagnostic, then rethrown so the
     * adapter can continue with later tasks. Normal return records Succeeded. Completion failures propagate
     * without attempting a second outcome and may take precedence over the original task failure.
     *
     * @throws CompletionRejectedException
     * @throws MutexStorageException
     * @throws TaskExecutionException
     */
    private function executeClaimed(RunClaim $claim): void
    {
        try {
            $this->executor->execute($this->definition);
        } catch (TaskExecutionException $failure) {
            $error = $failure->getPrevious()?->getMessage() ?? $failure->getMessage();
            $this->finish(
                claim: $claim,
                outcome: RunOutcome::Failed,
                error: $error !== '' ? $error : $failure->getMessage()
            );

            throw $failure;
        }

        $this->finish(claim: $claim, outcome: RunOutcome::Succeeded);
    }

    /**
     * Persists the outcome for this claim's run ID and owner token through ExecutionMutex.
     * Rejection is an infrastructure error rather than a task failure; it must remain visible to the caller.
     * Storage updates the original run, preserving occurrence history and any replacement run's lock.
     *
     * @throws CompletionRejectedException
     * @throws MutexStorageException
     */
    private function finish(RunClaim $claim, RunOutcome $outcome, ?string $error = null): void
    {
        if (! $this->mutex->finish($claim, $outcome, $error)) {
            throw new CompletionRejectedException('Completion was rejected for run ' . $claim->id . '.');
        }
    }
}
