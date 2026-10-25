<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use Tests\Support\Storage\StorageDatabase;

final readonly class RecoveryRaces
{
    public function __construct(
        private StorageDatabase $database,
        private RecoveryProcesses $processes,
        private RecoveryRecords $records,
    ) {}

    /**
     * @return list<Claim>
     * @throws \Exception
     */
    public function claims(string $schedule, int $minute): array
    {
        $this->database->connection->beginTransaction();
        $this->database->connection->exec('LOCK TABLE task_runs IN SHARE MODE');
        try {
            $operation = [
                'operation' => 'claim',
                'schedule' => $schedule,
                'minute' => $minute,
            ];
            $first = $this->processes->start($operation);
            $second = $this->processes->start($operation);
            $first->awaitLock($this->database);
            $second->awaitLock($this->database);
            $this->database->connection->commit();
            return $this->winners([$first->result(), $second->result()]);
        } finally {
            $this->rollback();
        }
    }

    /**
     * @param list<int|string|bool|null> $ids
     * @return list<Claim>
     * @throws \PDOException
     */
    private function winners(array $ids): array
    {
        $winners = [];
        foreach ($ids as $id) {
            if (is_int($id)) {
                $winners[] = $this->records->record($id)->claim;
            } else {
                RecoveryAssertions::same(null, $id);
            }
        }
        return $winners;
    }

    /**
     * @throws \Exception
     */
    public function completionAndRecovery(Claim $claim, bool $completionWins): void
    {
        $this->database->at(300);
        $schedule = $this->schedule($claim);
        $finish = [
            'operation' => 'finish',
            'id' => $claim->id,
            'token' => $claim->ownerToken,
        ];
        $recover = [
            'operation' => 'recover',
            'schedule' => $schedule,
        ];
        $first = $this->processes->start([
            'barrier' => true,
        ] + ($completionWins ? $finish : $recover));
        $first->await('commit');
        $second = $this->processes->start($completionWins ? $recover : $finish);
        $second->awaitLock($this->database);
        $first->release();
        RecoveryAssertions::same($completionWins ? true : 1, $first->result());
        RecoveryAssertions::same($completionWins ? 0 : true, $second->result());
    }

    /**
     * @throws \Exception
     */
    public function finishAfterWaiting(Claim $claim): bool
    {
        $this->database->connection->beginTransaction();
        $statement = $this->database->prepare('SELECT id FROM task_runs WHERE id = ? FOR UPDATE');
        $statement->execute([$claim->id]);
        try {
            $worker = $this->processes->start([
                'operation' => 'finish',
                'id' => $claim->id,
                'token' => $claim->ownerToken,
            ]);
            $worker->awaitLock($this->database);
            $this->database->at(301);
            $this->database->connection->commit();
            return $worker->result() === true;
        } finally {
            $this->rollback();
        }
    }

    /**
     * @throws \PDOException
     */
    private function schedule(Claim $claim): string
    {
        $row = $this->database->row($claim->id);
        $schedule = $row['schedule_id'] ?? null;
        return is_string($schedule) ? $schedule : throw new \LogicException('Missing schedule ID.');
    }

    /**
     * @throws \PDOException
     */
    private function rollback(): void
    {
        if (! $this->database->connection->inTransaction()) {
            return;
        }

        $this->database->connection->rollBack();
    }
}
