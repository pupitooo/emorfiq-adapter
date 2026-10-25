<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use App\Scheduler\Application\ExecutionCoordinator;
use Override;
use Tests\Support\Storage\StorageDatabase;

final readonly class PostgresRecoveryHarness implements RecoveryHarness
{
    private RecoveryProcesses $processes;

    private RecoveryRecords $records;

    private RecoveryOwners $owners;

    private RecoveryRaces $races;

    private RecoveryExecutions $executions;

    private RecoveryProbe $probe;

    private ExecutionCoordinator $coordinator;

    /**
     * @throws \Exception
     */
    public function __construct(
        private StorageDatabase $database
    ) {
        $this->processes = new RecoveryProcesses($database->schema);
        RecoveryDatabase::initialize($database->connection);
        $this->probe = new RecoveryProbe(RecoveryDatabase::connect($database->schema));
        $this->coordinator = RecoveryDatabase::coordinator(RecoveryDatabase::connect($database->schema));
        $this->records = new RecoveryRecords($database);
        $this->owners = new RecoveryOwners($database, $this->processes);
        $this->races = new RecoveryRaces($database, $this->processes, $this->records);
        $this->executions = new RecoveryExecutions($database, $this->processes);
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function close(): void
    {
        $this->processes->close();
        $this->owners->close();
        $this->database->close();
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function at(int $seconds): void
    {
        $this->database->at($seconds);
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function claim(
        string $schedule,
        int $minute,
        bool $onOneServer = true,
        bool $withoutOverlapping = true,
    ): ?Claim {
        return $this->owners->claim($schedule, $minute, $onOneServer, $withoutOverlapping);
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function recover(string $schedule, string $recoveredBy = 'recovery-worker'): int
    {
        return $this->coordinator->recover($schedule, $recoveredBy);
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function finish(Claim $claim, bool $succeeded = true): bool
    {
        return $this->owners->finish($claim, $succeeded);
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function record(Claim $claim): RunRecord
    {
        return $this->records->record($claim->id);
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function runs(string $schedule): array
    {
        return $this->records->runs($schedule);
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function raceClaims(string $schedule, int $minute): array
    {
        return $this->races->claims($schedule, $minute);
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function raceRecoveryAndClaim(string $schedule, int $minute): array
    {
        return $this->races->claims($schedule, $minute);
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function raceCompletionAndRecovery(Claim $claim, bool $completionWins): void
    {
        $this->races->completionAndRecovery($claim, $completionWins);
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function finishAfterWaitingPastDeadline(Claim $claim): bool
    {
        return $this->races->finishAfterWaiting($claim);
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function execute(string $schedule, int $minute, ExecutionFault $fault = ExecutionFault::None): void
    {
        $this->executions->execute($schedule, $minute, $fault);
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function executeLongTask(string $schedule, int $minute, int $duration): void
    {
        $this->executions->longTask($schedule, $minute, $duration);
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function taskResolutions(): int
    {
        return $this->probe->count('resolutions');
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function taskStarts(): int
    {
        return $this->probe->count('starts');
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function effectCount(): int
    {
        return $this->probe->effectCount();
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function committedBeforeInvocation(): bool
    {
        return $this->probe->count('committed') > 0;
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function reservationTransactionClosedDuringTask(): bool
    {
        return $this->probe->count('transaction_closed') > 0;
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function disconnectOwner(Claim $claim): void
    {
        $this->owners->disconnect($claim);
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function resumeEffect(Claim $claim): bool
    {
        return $this->owners->resumeEffect($claim);
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function submitEffect(Claim $claim): void
    {
        $this->owners->submitEffect($claim);
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function completeSubmittedEffect(Claim $claim): bool
    {
        return $this->owners->completeSubmittedEffect($claim);
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function applyIdempotentEffect(string $operationKey): bool
    {
        return $this->probe->applyIdempotent($operationKey);
    }

    /**
     * @throws \Exception
     */
    #[Override]
    public function applyFencedEffect(string $resource, int $generation): bool
    {
        return $this->probe->applyFenced($resource, $generation);
    }
}
