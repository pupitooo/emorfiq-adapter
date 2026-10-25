<?php

declare(strict_types=1);

namespace Tests\Integration\Storage;

use App\Database\PostgresConnectionFactory;
use App\Scheduler\Domain\RunOutcome;
use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Exception\MutexStorageException;
use App\Scheduler\Exception\ReservationUnconfirmedException;
use App\Scheduler\Infrastructure\Database\PostgresTaskRunStorage;
use DateTimeImmutable;
use LogicException;
use Tests\Support\MutexAssertions as A;
use Tests\Support\Storage\CommitFaultConnection;
use Tests\Support\Storage\StorageDatabase;
use Tests\Support\Storage\StorageTestRunner;

final readonly class PostgresStorageFailureTest
{
    public function run(StorageTestRunner $runner): void
    {
        $runner->run($this->translatesConnectionFailures(...));
        $runner->run($this->rollsBackRecoveryWhenReservationFails(...));
        $runner->run($this->translatesReadCompletionAndRecoveryFailures(...));
        $runner->run($this->preservesCallerTransaction(...));
        $runner->run($this->rollsBackHistoryAndPartialLocksOnAcquisitionFailure(...));
        $runner->run($this->rollsBackCompletionWhenLockReleaseFails(...));
        $runner->run($this->rollsBackLockRemovalWhenRecoveryHistoryFails(...));
        $runner->run($this->distinguishesLostCommitAcknowledgement(...));
        $runner->run($this->distinguishesConfirmedCommitRejection(...));
    }

    /**
     * @throws \Exception
     */
    private function translatesConnectionFailures(StorageDatabase $db): void
    {
        $this->fails(fn() => PostgresTaskRunStorage::connect(
            new PostgresConnectionFactory('invalid-driver:', 'unused', 'unused')
        ));
        A::same(false, $db->connection->inTransaction());
    }

    /**
     * @throws \Exception
     */
    private function rollsBackRecoveryWhenReservationFails(StorageDatabase $db): void
    {
        $claim = new PostgresTaskRunStorageTest()->claim($db);
        $before = $db->row($claim->id);
        $db->at(300);
        $db->connection->exec(<<<'SQL'
            CREATE FUNCTION reject_insert() RETURNS trigger LANGUAGE plpgsql AS
                'BEGIN RAISE EXCEPTION ''Injected insert failure''; END';
            CREATE TRIGGER reject_insert BEFORE INSERT ON task_runs FOR EACH ROW EXECUTE FUNCTION reject_insert();
            SQL);
        $this->fails(fn() => $db->storage->reserve(
            new ScheduleDefinition('sync', 'Task', '* * * * *'),
            new DateTimeImmutable('2026-10-26 10:01:00 UTC'),
            'worker'
        ));
        A::same(false, $db->connection->inTransaction());
        A::same($before, $db->row($claim->id));
        A::same(2, $db->value('SELECT count(*) FROM task_locks'));
    }

    /**
     * @throws \Exception
     */
    private function translatesReadCompletionAndRecoveryFailures(StorageDatabase $db): void
    {
        $claim = new PostgresTaskRunStorageTest()->claim($db);
        $db->connection->exec('ALTER TABLE task_locks RENAME TO unavailable_locks');
        $this->fails(fn() => $db->storage->hasLiveOverlap('sync'));
        $this->fails(fn() => $db->storage->hasOccurrence('sync', new DateTimeImmutable()));
        $this->fails(fn() => $db->storage->complete($claim, RunOutcome::Succeeded, null));
        $this->fails(fn() => $db->storage->abandonExpired('sync', 'worker'));
        A::same(false, $db->connection->inTransaction());
    }

    /**
     * @throws \Exception
     */
    private function preservesCallerTransaction(StorageDatabase $db): void
    {
        $db->connection->beginTransaction();
        $db->at(10);
        $this->fails(fn() => $db->storage->hasLiveOverlap('sync'));
        A::same(true, $db->connection->inTransaction());
        A::same('2026-10-26 10:00:10+00', $db->value('SELECT now FROM test_clock'));
        $db->connection->rollBack();
    }

    /**
     * @throws \Exception
     */
    private function distinguishesLostCommitAcknowledgement(StorageDatabase $db): void
    {
        $connection = StorageDatabase::connect(CommitFaultConnection::class);
        $connection->exec('SET search_path TO ' . $db->schema . ', pg_catalog');
        $storage = new PostgresTaskRunStorage(PostgresConnectionFactory::wrap($connection));
        try {
            $storage->reserve(
                new ScheduleDefinition('sync', 'Task', '* * * * *', onOneServer: true),
                new DateTimeImmutable('2026-10-26 10:00:00 UTC'),
                'worker'
            );
        } catch (ReservationUnconfirmedException) {
            A::same(false, $connection->inTransaction());
            A::same(true, $db->storage->hasOccurrence('sync', new DateTimeImmutable('2026-10-26 10:00:00 UTC')));
            A::same(1, $db->value('SELECT count(*) FROM task_runs'));

            return;
        }
        throw new LogicException('Lost acknowledgement must not authorize task invocation.');
    }

    /**
     * @throws \Exception
     */
    private function distinguishesConfirmedCommitRejection(StorageDatabase $db): void
    {
        $db->connection->exec(<<<'SQL'
            CREATE FUNCTION reject_commit() RETURNS trigger LANGUAGE plpgsql AS
                'BEGIN RAISE EXCEPTION ''Injected deferred constraint failure'' USING ERRCODE = ''23514''; END';
            CREATE CONSTRAINT TRIGGER reject_commit AFTER INSERT ON task_runs
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION reject_commit();
            SQL);
        $this->fails(fn() => $db->storage->reserve(
            new ScheduleDefinition('sync', 'Task', '* * * * *'),
            new DateTimeImmutable('2026-10-26 10:00:00 UTC'),
            'worker'
        ));
        A::same(false, $db->connection->inTransaction());
        A::same(0, $db->value('SELECT count(*) FROM task_runs'));
    }

    /**
     * @throws \Exception
     */
    private function rollsBackHistoryAndPartialLocksOnAcquisitionFailure(StorageDatabase $db): void
    {
        $this->rejectChange($db, 'INSERT', 'task_locks', "NEW.lock_type = 'overlap'");
        $this->fails(fn() => new PostgresTaskRunStorageTest()->claim($db));
        A::same(0, $db->value('SELECT count(*) FROM task_runs'));
        A::same(0, $db->value('SELECT count(*) FROM task_locks'));
        A::same(false, $db->connection->inTransaction());
    }

    /**
     * @throws \Exception
     */
    private function rollsBackCompletionWhenLockReleaseFails(StorageDatabase $db): void
    {
        $claim = new PostgresTaskRunStorageTest()->claim($db);
        $before = $db->row($claim->id);
        $this->rejectChange($db, 'DELETE', 'task_locks', "OLD.lock_type = 'overlap'");
        $this->fails(fn() => $db->storage->complete($claim, RunOutcome::Succeeded, null));
        A::same($before, $db->row($claim->id));
        A::same(2, $db->value('SELECT count(*) FROM task_locks'));
        A::same(false, $db->connection->inTransaction());
    }

    /**
     * @throws \Exception
     */
    private function rollsBackLockRemovalWhenRecoveryHistoryFails(StorageDatabase $db): void
    {
        $claim = new PostgresTaskRunStorageTest()->claim($db);
        $before = $db->row($claim->id);
        $db->at(300);
        $this->rejectChange($db, 'UPDATE', 'task_runs', "NEW.status = 'abandoned'");
        $this->fails(fn() => $db->storage->abandonExpired('sync', 'recovery'));
        A::same($before, $db->row($claim->id));
        A::same(2, $db->value('SELECT count(*) FROM task_locks'));
        A::same(false, $db->connection->inTransaction());
    }

    /**
     * @throws \PDOException
     */
    private function rejectChange(StorageDatabase $db, string $operation, string $table, string $condition): void
    {
        $db->connection->exec(<<<'SQL'
            CREATE FUNCTION reject_change() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN RAISE EXCEPTION 'Injected lock lifecycle failure'; END;
            $$;
            SQL);
        $db->connection->exec('CREATE TRIGGER reject_change BEFORE ' . $operation . ' ON ' . $table
            . ' FOR EACH ROW WHEN (' . $condition . ') EXECUTE FUNCTION reject_change()');
    }

    /**
     * @param \Closure(): mixed $operation
     * @throws \Exception
     */
    private function fails(\Closure $operation): void
    {
        try {
            $operation();
        } catch (MutexStorageException) {
            return;
        }
        throw new LogicException('Expected storage failure.');
    }
}
