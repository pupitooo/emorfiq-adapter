<?php

declare(strict_types=1);

namespace Tests\Integration\Storage;

use App\Scheduler\Domain\RunOutcome;
use App\Scheduler\Domain\ScheduleDefinition;
use DateTimeImmutable;
use Tests\Support\MutexAssertions as A;
use Tests\Support\Storage\StorageDatabase;
use Tests\Support\Storage\StorageTestRunner;

final readonly class PostgresLockStorageTest
{
    public function run(StorageTestRunner $runner): void
    {
        $runner->run($this->recordsUnprotectedDuplicatesWithoutLocks(...));
        $runner->run($this->createsOnlyRequestedLocks(...));
        $runner->run($this->discardsPartialLocksAndDeniedHistory(...));
        $runner->run($this->releasesOnlyOwnedOverlapOnCompletion(...));
        $runner->run($this->keepsProtectionsAfterHistoryDeletion(...));
        $runner->run($this->recoversOrphanedOverlapAndRetainsOccurrence(...));
        $runner->run($this->rejectsDeletedHistoryCompletionWithoutReleasingLocks(...));
        $runner->run($this->preservesReplacementLocksAfterLateCompletion(...));
    }

    /**
     * @throws \Exception
     */
    private function recordsUnprotectedDuplicatesWithoutLocks(StorageDatabase $db): void
    {
        $first = new PostgresTaskRunStorageTest()->claim($db, single: false, overlap: false);
        $second = new PostgresTaskRunStorageTest()->claim($db, single: false, overlap: false);
        A::same(false, $first->id === $second->id);
        A::same(false, $first->ownerToken === $second->ownerToken);
        A::same(2, $db->value('SELECT count(*) FROM task_runs'));
        A::same(0, $db->value('SELECT count(*) FROM task_locks'));
        A::same(null, $db->row($first->id)['lease_expires_at'] ?? null);
        A::same(true, $db->storage->complete($first, RunOutcome::Succeeded, null));
        A::same(true, $db->storage->complete($second, RunOutcome::Failed, 'failure'));
        A::same('succeeded', $db->row($first->id)['status'] ?? null);
        A::same('failed', $db->row($second->id)['status'] ?? null);
    }

    /**
     * @throws \Exception
     */
    private function createsOnlyRequestedLocks(StorageDatabase $db): void
    {
        foreach ([[false, false], [false, true], [true, false], [true, true]] as [$single, $overlap]) {
            $id = (int) $single . '-' . (int) $overlap;
            $claim = new PostgresTaskRunStorageTest()->claim($db, $id, single: $single, overlap: $overlap);
            A::same((int) $single + (int) $overlap, $this->lockCount($db, $claim->id));
            $statement = $db->prepare('SELECT count(*) FROM task_locks WHERE run_id = ? AND owner_token = ?');
            $statement->execute([$claim->id, $claim->ownerToken]);
            A::same((int) $single + (int) $overlap, $statement->fetchColumn());
        }
    }

    /**
     * @throws \Exception
     */
    private function discardsPartialLocksAndDeniedHistory(StorageDatabase $db): void
    {
        $tests = new PostgresTaskRunStorageTest();
        $tests->claim($db, single: false);
        $this->assertDenied($db, 60);
        A::same(1, $db->value('SELECT count(*) FROM task_runs'));
        A::same(1, $db->value('SELECT count(*) FROM task_locks'));
        A::same(false, $db->storage->hasOccurrence('sync', new DateTimeImmutable('2026-10-26 10:01:00 UTC')));
        $tests->claim($db, second: 60, overlap: false);
        A::same(2, $db->value('SELECT count(*) FROM task_runs'));
    }

    /**
     * @throws \Exception
     */
    private function releasesOnlyOwnedOverlapOnCompletion(StorageDatabase $db): void
    {
        $first = new PostgresTaskRunStorageTest()->claim($db);
        $other = new PostgresTaskRunStorageTest()->claim($db, id: 'other');
        A::same(true, $db->storage->complete($first, RunOutcome::Succeeded, null));
        A::same(1, $this->lockCount($db, $first->id));
        A::same(2, $this->lockCount($db, $other->id));
        A::same('occurrence', $db->value('SELECT lock_type FROM task_locks WHERE run_id = ' . $first->id));
        A::same('in_progress', $db->row($other->id)['status'] ?? null);
    }

    /**
     * @throws \Exception
     */
    private function keepsProtectionsAfterHistoryDeletion(StorageDatabase $db): void
    {
        $claim = new PostgresTaskRunStorageTest()->claim($db);
        $db->connection->exec('DELETE FROM task_runs WHERE id = ' . $claim->id);
        A::same(2, $db->value('SELECT count(*) FROM task_locks WHERE run_id IS NULL'));
        A::same(true, $db->storage->hasLiveOverlap('sync'));
        A::same(true, $db->storage->hasOccurrence('sync', new DateTimeImmutable('2026-10-26 10:00:00 UTC')));
        $this->assertDenied($db, 60);
        A::same(0, $db->value('SELECT count(*) FROM task_runs'));
        A::same(0, $db->storage->abandonExpired('sync', 'early'));
    }

    /**
     * @throws \Exception
     */
    private function recoversOrphanedOverlapAndRetainsOccurrence(StorageDatabase $db): void
    {
        $claim = new PostgresTaskRunStorageTest()->claim($db);
        $db->connection->exec('DELETE FROM task_runs WHERE id = ' . $claim->id);
        $db->at(300);
        A::same(1, $db->storage->abandonExpired('sync', 'recovery'));
        A::same(0, $db->storage->abandonExpired('sync', 'again'));
        A::same(1, $db->value('SELECT count(*) FROM task_locks WHERE run_id IS NULL'));
        $this->assertDenied($db, 0);
        $replacement = new PostgresTaskRunStorageTest()->claim($db, second: 60);
        A::same(2, $this->lockCount($db, $replacement->id));
        A::same(1, $db->value('SELECT count(*) FROM task_runs'));
    }

    /**
     * @throws \Exception
     */
    private function rejectsDeletedHistoryCompletionWithoutReleasingLocks(StorageDatabase $db): void
    {
        $claim = new PostgresTaskRunStorageTest()->claim($db);
        $db->connection->exec('DELETE FROM task_runs WHERE id = ' . $claim->id);
        A::same(false, $db->storage->complete($claim, RunOutcome::Succeeded, null));
        A::same(2, $db->value('SELECT count(*) FROM task_locks'));
        A::same(true, $db->storage->hasLiveOverlap('sync'));
    }

    /**
     * @throws \Exception
     */
    private function preservesReplacementLocksAfterLateCompletion(StorageDatabase $db): void
    {
        $original = new PostgresTaskRunStorageTest()->claim($db);
        $db->at(300);
        $replacement = new PostgresTaskRunStorageTest()->claim($db, second: 60);
        A::same(true, $db->storage->complete($original, RunOutcome::Failed, 'late failure'));
        A::same(1, $this->lockCount($db, $original->id));
        A::same(2, $this->lockCount($db, $replacement->id));
        A::same('claim-worker', $db->row($original->id)['abandoned_by'] ?? null);
        A::same(true, $db->storage->hasLiveOverlap('sync'));
    }

    /**
     * @throws \PDOException
     */
    private function lockCount(StorageDatabase $db, int $id): int
    {
        $count = $db->value('SELECT count(*) FROM task_locks WHERE run_id = ' . $id);
        return is_int($count) ? $count : throw new \LogicException('Invalid lock count.');
    }

    /**
     * @throws \Exception
     */
    private function assertDenied(StorageDatabase $db, int $second): void
    {
        A::same(null, $db->storage->reserve(
            new ScheduleDefinition('sync', 'Task', '* * * * *', withoutOverlapping: true, onOneServer: true),
            new DateTimeImmutable('2026-10-26 10:00:00 UTC')->modify('+' . $second . ' seconds'),
            'denied-worker'
        ));
    }
}
