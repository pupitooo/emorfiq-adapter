<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use Exception;

/**
 * Test port for production coordination, real database sessions, and worker fault injection.
 * Times are seconds relative to 2026-10-26 10:00:00 UTC; overlap locks last 300 seconds without renewal.
 * Each factory call must provide isolated storage and a shared controllable ownership clock.
 * See docs/execution/verification.md for barriers, observation, and receiver requirements.
 */
interface RecoveryHarness
{
    /**
     * Release connections, stop workers, and remove isolated storage, including after failed scenarios.
     *
     * @throws Exception
     */
    public function close(): void;

    /**
     * @throws Exception
     */
    public function at(int $seconds): void;

    /**
     * @throws Exception
     */
    public function claim(
        string $schedule,
        int $minute,
        bool $onOneServer = true,
        bool $withoutOverlapping = true,
    ): ?Claim;

    /**
     * @throws Exception
     */
    public function recover(string $schedule, string $recoveredBy = 'recovery-worker'): int;

    /**
     * @throws Exception
     */
    public function finish(Claim $claim, bool $succeeded = true): bool;

    /**
     * @throws Exception
     */
    public function record(Claim $claim): RunRecord;

    /**
     * @return list<RunRecord>
     * @throws Exception
     */
    public function runs(string $schedule): array;

    /**
     * @return list<Claim> Winners from two independent sessions released at one claim barrier.
     * @throws Exception
     */
    public function raceClaims(string $schedule, int $minute): array;

    /**
     * @return list<Claim> Winners from two sessions recovering and claiming the same next occurrence.
     * @throws Exception
     */
    public function raceRecoveryAndClaim(string $schedule, int $minute): array;

    /**
     * At second 300, order two real sessions completing and recovering the same run.
     * completionWins=true: recovery rechecks the completed row and leaves audit fields empty.
     * completionWins=false: completion succeeds after abandonment and retains recovery-worker attribution.
     * @throws Exception
     */
    public function raceCompletionAndRecovery(Claim $claim, bool $completionWins): void;

    /**
     * Complete at second 301 after waiting on a row lock across expiry, without intervening recovery.
     * Expiry alone does not reject the original owner's result.
     * @throws Exception
     */
    public function finishAfterWaitingPastDeadline(Claim $claim): bool;

    /**
     * Exercise the real runner with a recording task; capture injected worker death/DB failures.
     * @throws Exception
     */
    public function execute(string $schedule, int $minute, ExecutionFault $fault = ExecutionFault::None): void;

    /**
     * Block the real task without renewal; recover at second 300 as recovery-worker.
     * Release the task at duration and persist its result while retaining abandonment history.
     * @throws Exception
     */
    public function executeLongTask(string $schedule, int $minute, int $duration): void;

    /**
     * @throws Exception
     */
    public function taskResolutions(): int;

    /**
     * @throws Exception
     */
    public function taskStarts(): int;

    /**
     * @throws Exception
     */
    public function effectCount(): int;

    /**
     * Inspect from another connection while the callback is entered.
     * @throws Exception
     */
    public function committedBeforeInvocation(): bool;

    /**
     * @throws Exception
     */
    public function reservationTransactionClosedDuringTask(): bool;

    /**
     * @throws Exception
     */
    public function disconnectOwner(Claim $claim): void;

    /**
     * Resume a paused fixture worker without automatic cancellation or ownership checks.
     * @throws Exception
     */
    public function resumeEffect(Claim $claim): bool;

    /**
     * Pause an external request after submission, before its durable effect.
     * @throws Exception
     */
    public function submitEffect(Claim $claim): void;

    /**
     * Complete that already submitted request without another ownership check.
     * @throws Exception
     */
    public function completeSubmittedEffect(Claim $claim): bool;

    /**
     * Receiver probe: return true only when an effect was newly applied.
     * @throws Exception
     */
    public function applyIdempotentEffect(string $operationKey): bool;

    /**
     * Receiver probe atomically enforces generation per resource; not scheduler UUID fencing.
     * @throws Exception
     */
    public function applyFencedEffect(string $resource, int $generation): bool;
}
