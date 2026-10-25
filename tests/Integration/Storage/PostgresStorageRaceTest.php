<?php

declare(strict_types=1);

namespace Tests\Integration\Storage;

use App\Scheduler\Domain\RunClaim;
use Tests\Support\MutexAssertions as A;
use Tests\Support\Storage\StorageDatabase;
use Tests\Support\Storage\StorageTestRunner;
use Tests\Support\Storage\StorageWorker;

final readonly class PostgresStorageRaceTest
{
    public function run(StorageTestRunner $runner): void
    {
        $runner->run($this->choosesOneWinnerAcrossConcurrentClaims(...));
        $runner->run($this->choosesOneWinnerAcrossRecoveryAndClaims(...));
        $runner->run($this->rechecksExpiryAfterRecoveryLockWait(...));
        $runner->run($this->startsReservationClockAfterLockWait(...));
        $runner->run($this->acceptsCompletionAfterWaitingPastExpiry(...));
        $runner->run($this->keepsCompletionWhenItWinsRecoveryRace(...));
        $runner->run($this->keepsAbandonmentWhenItWinsCompletionRace(...));
    }

    /**
     * @throws \Exception
     */
    private function choosesOneWinnerAcrossConcurrentClaims(StorageDatabase $db): void
    {
        $db->connection->beginTransaction();
        $db->connection->exec('LOCK TABLE task_runs IN SHARE MODE');
        $this->raceClaims($db);
    }

    /**
     * @throws \Exception
     */
    private function choosesOneWinnerAcrossRecoveryAndClaims(StorageDatabase $db): void
    {
        $claim = new PostgresTaskRunStorageTest()->claim($db);
        $db->at(300);
        $this->lock($db, $claim);
        $this->raceClaims($db);
        A::same('abandoned', ($db->row($claim->id)['status'] ?? null));
        A::same('claim-worker', ($db->row($claim->id)['abandoned_by'] ?? null));
    }

    /**
     * @throws \Exception
     */
    private function raceClaims(StorageDatabase $db): void
    {
        $first = new StorageWorker($db, [
            'operation' => 'claim',
        ]);
        $second = new StorageWorker($db, [
            'operation' => 'claim',
        ]);
        try {
            $first->awaitLock($db);
            $second->awaitLock($db);
            $db->connection->commit();
            $results = [$first->result(), $second->result()];
            A::same(1, count(array_filter($results, static fn(mixed $id): bool => $id !== null)));
            A::same(1, $db->value("SELECT count(*) FROM task_runs WHERE status = 'in_progress'"));
        } finally {
            $first->close();
            $second->close();
        }
    }

    /**
     * @throws \Exception
     */
    private function rechecksExpiryAfterRecoveryLockWait(StorageDatabase $db): void
    {
        $claim = new PostgresTaskRunStorageTest()->claim($db);
        $this->lock($db, $claim);
        $worker = new StorageWorker($db, [
            'operation' => 'recover',
        ]);
        try {
            $worker->awaitLock($db);
            $db->at(300);
            $db->connection->commit();
            A::same(1, $worker->result());
            A::same('2026-10-26 10:05:00+00', ($db->row($claim->id)['abandoned_at'] ?? null));
        } finally {
            $worker->close();
        }
    }

    /**
     * @throws \Exception
     */
    private function startsReservationClockAfterLockWait(StorageDatabase $db): void
    {
        $claim = new PostgresTaskRunStorageTest()->claim($db);
        $this->lock($db, $claim);
        $worker = new StorageWorker($db, [
            'operation' => 'claim',
        ]);
        try {
            $worker->awaitLock($db);
            $db->at(300);
            $db->connection->commit();
            $id = $worker->result();
            if (! is_int($id)) {
                throw new \LogicException('Expected replacement claim.');
            }
            A::same('2026-10-26 10:05:00+00', ($db->row($id)['started_at'] ?? null));
            A::same('2026-10-26 10:10:00+00', ($db->row($id)['lease_expires_at'] ?? null));
        } finally {
            $worker->close();
        }
    }

    /**
     * @throws \Exception
     */
    private function acceptsCompletionAfterWaitingPastExpiry(StorageDatabase $db): void
    {
        $claim = new PostgresTaskRunStorageTest()->claim($db);
        $this->lock($db, $claim);
        $worker = $this->completionWorker($db, $claim);
        try {
            $worker->awaitLock($db);
            $db->at(301);
            $db->connection->commit();
            A::same(true, $worker->result());
            A::same('2026-10-26 10:05:01+00', ($db->row($claim->id)['finished_at'] ?? null));
            A::same(null, ($db->row($claim->id)['abandoned_at'] ?? null));
        } finally {
            $worker->close();
        }
    }

    /**
     * @throws \Exception
     */
    private function keepsCompletionWhenItWinsRecoveryRace(StorageDatabase $db): void
    {
        $this->orderedRace($db, true);
    }

    /**
     * @throws \Exception
     */
    private function keepsAbandonmentWhenItWinsCompletionRace(StorageDatabase $db): void
    {
        $this->orderedRace($db, false);
    }

    /**
     * @throws \Exception
     */
    private function orderedRace(StorageDatabase $db, bool $completionWins): void
    {
        $claim = new PostgresTaskRunStorageTest()->claim($db);
        $db->at(300);
        $first = $completionWins ? $this->completionWorker($db, $claim, true)
            : $this->recoveryWorker($db, true);
        $second = null;
        try {
            $first->awaitCommit();
            $second = $completionWins ? $this->recoveryWorker($db) : $this->completionWorker($db, $claim);
            $second->awaitLock($db);
            $first->releaseCommit();
            A::same($completionWins ? true : 1, $first->result());
            A::same($completionWins ? 0 : true, $second->result());
            A::same('succeeded', $db->row($claim->id)['status'] ?? null);
            A::same($completionWins ? null : 'recovery-worker', $db->row($claim->id)['abandoned_by'] ?? null);
        } finally {
            $first->close();
            $second?->close();
        }
    }

    /**
     * @throws \PDOException
     */
    private function lock(StorageDatabase $db, RunClaim $claim): void
    {
        $db->connection->beginTransaction();
        $db->connection->query('SELECT id FROM task_runs WHERE id = ' . $claim->id . ' FOR UPDATE');
    }

    /**
     * @throws \JsonException
     */
    private function recoveryWorker(StorageDatabase $db, bool $barrier = false): StorageWorker
    {
        return new StorageWorker($db, [
            'operation' => 'recover',
            'barrier' => (int) $barrier,
        ]);
    }

    /**
     * @throws \JsonException
     */
    private function completionWorker(StorageDatabase $db, RunClaim $claim, bool $barrier = false): StorageWorker
    {
        return new StorageWorker($db, [
            'operation' => 'complete',
            'id' => $claim->id,
            'token' => $claim->ownerToken,
            'barrier' => (int) $barrier,
        ]);
    }
}
