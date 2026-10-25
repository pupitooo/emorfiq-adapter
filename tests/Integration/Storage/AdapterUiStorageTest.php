<?php

declare(strict_types=1);

namespace Tests\Integration\Storage;

use App\Scheduler\Domain\RunOutcome;
use DevTools\AdapterUi\TaskLocks;
use DevTools\AdapterUi\TaskRunHistory;
use Tests\Support\MutexAssertions as A;
use Tests\Support\Storage\StorageDatabase;
use Tests\Support\Storage\StorageTestRunner;

final readonly class AdapterUiStorageTest
{
    public function run(StorageTestRunner $runner): void
    {
        $runner->run($this->showsCurrentLocksAndOmitsOwnerTokens(...));
        $runner->run($this->filtersOrphanedAndExpiredLocksWithoutRecovering(...));
    }

    /**
     * @throws \Exception
     */
    private function showsCurrentLocksAndOmitsOwnerTokens(StorageDatabase $db): void
    {
        $claim = new PostgresTaskRunStorageTest()->claim($db);
        $db->connection->exec("UPDATE task_locks SET expires_at = pg_catalog.clock_timestamp() + interval '1 hour'"
            . " WHERE lock_type = 'overlap'");
        $history = new TaskRunHistory($db->factory());
        $row = $history->read([
            'id' => (string) $claim->id,
        ])['rows'][0] ?? [];
        A::same(true, $row['overlap_locked'] ?? null);
        A::same(false, $row['overlap_expired'] ?? null);
        A::same(true, $row['occurrence_reserved'] ?? null);
        A::same(false, array_key_exists('owner_token', $row));
        A::same(2, new TaskLocks($db->factory())->read([
            'run_id' => (string) $claim->id,
        ])['total']);
        $db->storage->complete($claim, RunOutcome::Succeeded, null);
        $row = $history->read([
            'id' => (string) $claim->id,
        ])['rows'][0] ?? [];
        A::same(false, $row['overlap_locked'] ?? null);
        A::same(true, $row['occurrence_reserved'] ?? null);
    }

    /**
     * @throws \Exception
     */
    private function filtersOrphanedAndExpiredLocksWithoutRecovering(StorageDatabase $db): void
    {
        $claim = new PostgresTaskRunStorageTest()->claim($db, id: "special'%_");
        $db->connection->exec("UPDATE task_locks SET expires_at = pg_catalog.clock_timestamp() - interval '1 hour'"
            . " WHERE lock_type = 'overlap'");
        $row = new TaskRunHistory($db->factory())->read([
            'id' => (string) $claim->id,
        ])['rows'][0] ?? [];
        A::same(true, $row['overlap_expired'] ?? null);
        $db->connection->exec('DELETE FROM task_runs WHERE id = ' . $claim->id);
        $locks = new TaskLocks($db->factory());
        $result = $locks->read([
            'history' => 'deleted',
            'lock_state' => 'expired',
            'schedule_id' => "special'%_",
        ]);
        A::same(1, $result['total']);
        A::same([null], array_column($result['rows'], 'run_id'));
        A::same(false, array_key_exists('owner_token', $result['rows'][0] ?? []));
        $this->assertRetainedLocks($db, $locks);
    }

    /**
     * @throws \Exception
     */
    private function assertRetainedLocks(StorageDatabase $db, TaskLocks $locks): void
    {
        A::same(0, $locks->read([
            'history' => 'linked',
        ])['total']);
        A::same(1, $locks->read([
            'lock_type' => 'occurrence',
            'lock_state' => 'reserved',
        ])['total']);
        A::same(2, $db->value('SELECT count(*) FROM task_locks'));
    }
}
