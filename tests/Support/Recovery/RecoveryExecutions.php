<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use App\Scheduler\Exception\MutexStorageException;
use App\Scheduler\Exception\ReservationUnconfirmedException;
use Tests\Support\Storage\StorageDatabase;

final readonly class RecoveryExecutions
{
    public function __construct(
        private StorageDatabase $database,
        private RecoveryProcesses $processes
    ) {}

    /**
     * @throws \Exception
     */
    public function execute(string $schedule, int $minute, ExecutionFault $fault): void
    {
        if ($fault === ExecutionFault::ReservationRollback) {
            $this->database->connection->exec(<<<'SQL'
                ALTER TABLE task_runs ADD CONSTRAINT reject_fixture_reservation CHECK (schedule_id = '')
                SQL);
        }
        try {
            $worker = $this->processes->start([
                'operation' => 'execute',
                'schedule' => $schedule,
                'minute' => $minute,
                'fault' => $fault->name,
            ]);
            $this->collect($worker, $fault);
        } finally {
            if ($fault === ExecutionFault::ReservationRollback) {
                $this->database->connection->exec('ALTER TABLE task_runs DROP CONSTRAINT reject_fixture_reservation');
            }
        }
    }

    /**
     * @throws \Exception
     */
    private function collect(RecoveryProcess $worker, ExecutionFault $fault): void
    {
        if (in_array($fault, [
            ExecutionFault::CrashBeforeTask, ExecutionFault::CrashDuringTask, ExecutionFault::CrashAfterEffect,
        ], true)) {
            $worker->await('crash');
            $worker->kill();
            return;
        }
        if ($fault === ExecutionFault::CompletionUnavailable) {
            $worker->await('disconnect');
            $this->disconnect($worker);
            $worker->release();
        }
        $expected = match ($fault) {
            ExecutionFault::ReservationAcknowledgementLost => ReservationUnconfirmedException::class,
            ExecutionFault::ReservationRollback, ExecutionFault::CompletionUnavailable => MutexStorageException::class,
            ExecutionFault::None, ExecutionFault::TaskException => 'completed',
        };
        RecoveryAssertions::same($expected, $worker->result());
    }

    /**
     * @throws \PDOException
     */
    private function disconnect(RecoveryProcess $worker): void
    {
        $statement = $this->database->prepare('SELECT pg_terminate_backend(?)');
        $statement->execute([$worker->pid]);
        RecoveryAssertions::same(true, $statement->fetchColumn());
    }

    /**
     * @throws \Exception
     */
    public function longTask(string $schedule, int $minute, int $duration): void
    {
        $worker = $this->processes->start([
            'operation' => 'execute',
            'schedule' => $schedule,
            'minute' => $minute,
            'long' => true,
        ]);
        $worker->await('task');
        $this->database->at(300);
        RecoveryAssertions::same(1, RecoveryDatabase::coordinator(
            RecoveryDatabase::connect($this->database->schema)
        )->recover($schedule, 'recovery-worker'));
        $this->database->at($duration);
        $worker->release();
        RecoveryAssertions::same('completed', $worker->result());
    }
}
