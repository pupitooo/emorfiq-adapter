<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use Exception;

/**
 * Test port for production coordination, real database sessions, and worker fault injection.
 * Times are seconds relative to 2026-10-26 10:00:00 UTC; leases last 300 seconds.
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

    public function at(int $seconds): void;

    public function claim(
        string $schedule,
        int $minute,
        bool $onOneServer = true,
        bool $withoutOverlapping = true,
    ): ?Claim;

    public function recover(string $schedule): int;

    public function renew(Claim $claim): bool;

    public function finish(Claim $claim, bool $succeeded = true): bool;

    public function record(Claim $claim): RunRecord;

    /**
     * @return list<RunRecord>
     */
    public function runs(string $schedule): array;

    /**
     * @return list<Claim> Winners from two independent sessions released at one claim barrier.
     */
    public function raceClaims(string $schedule, int $minute): array;

    /**
     * @return list<Claim> Winners from two sessions recovering and claiming the same next occurrence.
     */
    public function raceRecoveryAndClaim(string $schedule, int $minute): array;

    /**
     * Run real concurrent statements across expiry with controlled lock order.
     * renewalWins=true: valid renewal commits first; recovery rechecks the extended lease.
     * renewalWins=false: recovery commits first; waiting renewal cannot revive the row.
     */
    public function raceRenewalAndRecovery(Claim $claim, bool $renewalWins): void;

    /**
     * Waiting completion must check time again after crossing the lease deadline.
     */
    public function finishAfterWaitingPastDeadline(Claim $claim): bool;

    /**
     * Exercise the real runner with a recording task; capture injected worker death/DB failures.
     */
    public function execute(string $schedule, int $minute, ExecutionFault $fault = ExecutionFault::None): void;

    /**
     * Keep the recording task blocked while its supervisor renews; sweep at the original deadline.
     * Advance the controlled clock to duration, release the task, and observe completion.
     */
    public function executeLongTask(string $schedule, int $minute, int $duration): void;

    public function taskResolutions(): int;

    public function taskStarts(): int;

    public function effectCount(): int;

    /**
     * Inspect from another connection while the callback is entered.
     */
    public function committedBeforeInvocation(): bool;

    public function reservationTransactionClosedDuringTask(): bool;

    public function disconnectOwner(Claim $claim): void;

    public function ownershipLossSignalled(Claim $claim): bool;

    /**
     * Resume a paused fixture worker; arbitrary tasks can ignore lost ownership.
     */
    public function resumeEffect(Claim $claim, bool $cooperative): bool;

    /**
     * Pause an external request after submission, before its durable effect.
     */
    public function submitEffect(Claim $claim): void;

    /**
     * Complete that already submitted request without another ownership check.
     */
    public function completeSubmittedEffect(Claim $claim): bool;

    /**
     * Receiver probe: return true only when an effect was newly applied.
     */
    public function applyIdempotentEffect(string $operationKey): bool;

    /**
     * Receiver probe atomically enforces generation per resource; not scheduler UUID fencing.
     */
    public function applyFencedEffect(string $resource, int $generation): bool;
}
