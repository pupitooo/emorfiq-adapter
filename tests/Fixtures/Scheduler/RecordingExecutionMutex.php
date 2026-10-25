<?php

declare(strict_types=1);

namespace Tests\Fixtures\Scheduler;

use App\Scheduler\Contract\ExecutionMutex;
use App\Scheduler\Domain\RunClaim;
use App\Scheduler\Domain\RunOutcome;
use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Exception\MutexStorageException;
use App\Scheduler\Exception\ReservationUnconfirmedException;
use DateTimeImmutable;
use Override;

/**
 * Records interactions and supplies configured responses; it does not implement database coordination.
 */
final class RecordingExecutionMutex implements ExecutionMutex
{
    /**
     * @param list<string> $lockedSchedules
     * @param list<string> $deniedSchedules
     * @param list<string> $reservedSchedules
     */
    public function __construct(
        public readonly array $lockedSchedules = [],
        public readonly array $deniedSchedules = [],
        public readonly array $reservedSchedules = [],
        public readonly MutexStorageException|ReservationUnconfirmedException|null $claimFailure = null,
        public readonly ?MutexStorageException $completionFailure = null,
        public readonly bool $acceptCompletion = true,
    ) {}

    /**
     * @var list<array{definition: ScheduleDefinition, scheduledFor: DateTimeImmutable, claimedBy: string}>
     */
    public private(set) array $claims = [];

    /**
     * @var list<RunClaim>
     */
    public private(set) array $issuedClaims = [];

    /**
     * @var list<array{claim: RunClaim, outcome: RunOutcome, error: ?string}>
     */
    public private(set) array $finishes = [];

    /**
     * @var list<string>
     */
    public private(set) array $overlapChecks = [];

    /**
     * @var list<array{scheduleId: string, scheduledFor: DateTimeImmutable}>
     */
    public private(set) array $occurrenceChecks = [];

    /**
     * @throws void
     */
    #[Override]
    public function isOverlapLocked(string $scheduleId): bool
    {
        $this->overlapChecks[] = $scheduleId;

        return in_array($scheduleId, $this->lockedSchedules, true);
    }

    /**
     * @throws void
     */
    #[Override]
    public function isOccurrenceReserved(string $scheduleId, DateTimeImmutable $scheduledFor): bool
    {
        $this->occurrenceChecks[] = [
            'scheduleId' => $scheduleId,
            'scheduledFor' => $scheduledFor,
        ];

        return in_array($scheduleId, $this->reservedSchedules, true);
    }

    /**
     * @throws MutexStorageException
     * @throws ReservationUnconfirmedException
     */
    #[Override]
    public function claim(
        ScheduleDefinition $definition,
        DateTimeImmutable $scheduledFor,
        string $claimedBy,
    ): ?RunClaim {
        $this->claims[] = [
            'definition' => $definition,
            'scheduledFor' => $scheduledFor,
            'claimedBy' => $claimedBy,
        ];
        if ($this->claimFailure !== null) {
            throw $this->claimFailure;
        }
        if (in_array($definition->id, $this->deniedSchedules, true)) {
            return null;
        }
        $id = count($this->claims);
        $claim = new RunClaim($id, sprintf('00000000-0000-4000-8000-%012d', $id));
        $this->issuedClaims[] = $claim;

        return $claim;
    }

    /**
     * @throws MutexStorageException
     */
    #[Override]
    public function finish(RunClaim $claim, RunOutcome $outcome, ?string $error = null): bool
    {
        $this->finishes[] = [
            'claim' => $claim,
            'outcome' => $outcome,
            'error' => $error,
        ];
        if ($this->completionFailure !== null) {
            throw $this->completionFailure;
        }

        return $this->acceptCompletion;
    }

    /**
     * @throws MutexStorageException
     */
    #[Override]
    public function recover(string $scheduleId, string $recoveredBy): int
    {
        throw new MutexStorageException('Unexpected standalone recovery: ' . $scheduleId . ' by ' . $recoveredBy);
    }
}
