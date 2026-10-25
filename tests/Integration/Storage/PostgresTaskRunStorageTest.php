<?php

declare(strict_types=1);

namespace Tests\Integration\Storage;

use App\Scheduler\Domain\RunClaim;
use App\Scheduler\Domain\RunOutcome;
use App\Scheduler\Domain\ScheduleDefinition;
use DateTimeImmutable;
use Tests\Support\MutexAssertions as A;
use Tests\Support\Storage\StorageDatabase;
use Tests\Support\Storage\StorageTestRunner;

final readonly class PostgresTaskRunStorageTest
{
    public function run(StorageTestRunner $runner): void
    {
        $runner->run($this->storesCommittedSnapshotAndFreshTokens(...));
        $runner->run($this->preservesSpecialCharactersAndPreciseInstants(...));
        $runner->run($this->combinesIndependentProtections(...));
        $runner->run($this->keepsCompletedOccurrencesReserved(...));
        $runner->run($this->recoversBeforeClaimAndRetainsHistory(...));
        $runner->run($this->acceptsLateOutcomesWithoutChangingReplacement(...));
        $runner->run($this->rejectsWrongOwnersAndRepeatedCompletion(...));
        $runner->run($this->acceptsCompletionAtAndAfterExpiry(...));
        $runner->run($this->ignoresLiveUnprotectedAndOtherSchedules(...));
    }

    /**
     * @throws \Exception
     */
    private function storesCommittedSnapshotAndFreshTokens(StorageDatabase $db): void
    {
        $definition = new ScheduleDefinition('sync', 'ExampleTask', '*/2 * * * *', 'Europe/Prague', true, 5, true);
        $first = $db->storage->reserve(
            $definition,
            new DateTimeImmutable('2026-10-26 11:00:00 Europe/Prague'),
            'worker'
        );
        A::same(false, $db->connection->inTransaction());
        $claim = $first ?? throw new \LogicException('Expected claim.');
        $row = $db->row($claim->id);
        foreach ($this->expectedSnapshot() as $key => $value) {
            A::same($value, $row[$key] ?? null);
        }
        $other = $this->claim($db, id: 'other');
        A::same(false, $claim->ownerToken === $other->ownerToken);
        A::same(false, $claim->id === $other->id);
    }

    /**
     * @throws \Exception
     */
    private function preservesSpecialCharactersAndPreciseInstants(StorageDatabase $db): void
    {
        $id = "sync'\\%_; DROP TABLE task_runs; --";
        $instant = new DateTimeImmutable('2026-10-26 15:30:00.123456+05:30');
        $claim = $db->storage->reserve(
            new ScheduleDefinition($id, "Task'\\%_", '* * * * *', onOneServer: true),
            $instant,
            'worker'
        ) ?? throw new \LogicException('Expected claim.');
        A::same($id, $db->row($claim->id)['schedule_id'] ?? null);
        A::same('2026-10-26 10:00:00.123456+00', $db->row($claim->id)['scheduled_for'] ?? null);
        A::same(true, $db->storage->hasOccurrence($id, $instant));
        A::same(false, $db->storage->hasOccurrence("' OR true --", $instant));
        $error = "Failure's detail \\ %_";
        A::same(true, $db->storage->complete($claim, RunOutcome::Failed, $error));
        A::same($error, $db->row($claim->id)['error_message'] ?? null);
        A::same(false, $db->storage->hasLiveOverlap($id));
    }

    /**
     * @return array<string, int|string|bool|null>
     */
    private function expectedSnapshot(): array
    {
        return [
            'schedule_id' => 'sync',
            'task_class' => 'ExampleTask',
            'cron_expression' => '*/2 * * * *',
            'schedule_timezone' => 'Europe/Prague',
            'without_overlapping' => true,
            'on_one_server' => true,
            'overlap_expires_after_minutes' => 5,
            'status' => 'in_progress',
            'scheduled_for' => '2026-10-26 10:00:00+00',
            'started_at' => '2026-10-26 10:00:00+00',
            'lease_expires_at' => '2026-10-26 10:05:00+00',
            'finished_at' => null,
        ];
    }

    /**
     * @throws \Exception
     */
    private function combinesIndependentProtections(StorageDatabase $db): void
    {
        foreach ([[true, true], [true, false], [false, true], [false, false]] as [$single, $overlap]) {
            $id = (int) $single . '-' . (int) $overlap;
            $first = $this->claim($db, id: $id, single: $single, overlap: $overlap);
            A::same($overlap, $db->storage->hasLiveOverlap($id));
            A::same($single, $db->storage->hasOccurrence($id, $this->minute(0)));
            A::same($single || $overlap, $this->reserve($db, $id, 0, $single, $overlap) === null);
            A::same($overlap, $this->reserve($db, $id, 60, $single, $overlap) === null);
            if (! $overlap) {
                A::same(null, ($db->row($first->id)['lease_expires_at'] ?? null));
            }
            A::same(false, $db->connection->inTransaction());
        }
    }

    /**
     * @throws \Exception
     */
    private function keepsCompletedOccurrencesReserved(StorageDatabase $db): void
    {
        foreach (RunOutcome::cases() as $outcome) {
            $id = $outcome->value;
            $claim = $this->claim($db, id: $id);
            A::same(true, $db->storage->complete($claim, $outcome, $outcome === RunOutcome::Failed ? 'failure' : null));
            A::same(false, $db->storage->hasLiveOverlap($id));
            A::same(true, $db->storage->hasOccurrence($id, $this->minute(0)));
            A::same(null, $this->reserve($db, $id));
            $this->claim($db, id: $id, second: 60);
        }
        $claim = $this->claim($db, id: 'overlap', single: false);
        $db->storage->complete($claim, RunOutcome::Succeeded, null);
        $this->claim($db, id: 'overlap', single: false);
    }

    /**
     * @throws \Exception
     */
    private function recoversBeforeClaimAndRetainsHistory(StorageDatabase $db): void
    {
        $original = $this->claim($db);
        $db->at(300);
        A::same(false, $db->storage->hasLiveOverlap('sync'));
        // Recovery commits even when the insert loses the historical occurrence conflict.
        A::same(null, $this->reserve($db, 'sync'));
        $row = $db->row($original->id);
        A::same('abandoned', ($row['status'] ?? null));
        A::same('2026-10-26 10:05:00+00', ($row['abandoned_at'] ?? null));
        A::same('claim-worker', ($row['abandoned_by'] ?? null));
        A::same(null, ($row['finished_at'] ?? null));
        A::same(0, $db->storage->abandonExpired('sync', 'different-worker'));
        A::same($row, $db->row($original->id));
        $this->claim($db, second: 60);
    }

    /**
     * @throws \Exception
     */
    private function acceptsLateOutcomesWithoutChangingReplacement(StorageDatabase $db): void
    {
        foreach (RunOutcome::cases() as $outcome) {
            $id = $outcome->value;
            $original = $this->claim($db, id: $id);
            $db->at(600);
            A::same(1, $db->storage->abandonExpired($id, 'recovery-worker'));
            $before = $db->row($original->id);
            $replacement = $this->claim($db, id: $id, second: 60);
            $replacementRow = $db->row($replacement->id);
            A::same(true, $db->storage->complete($original, $outcome, 'diagnostic'));
            $after = $db->row($original->id);
            foreach (['abandoned_at', 'abandoned_by', 'lease_expires_at'] as $field) {
                A::same(($before[$field] ?? null), ($after[$field] ?? null));
            }
            A::same($outcome->value, ($after['status'] ?? null));
            A::same('diagnostic', ($after['error_message'] ?? null));
            A::same($replacementRow, $db->row($replacement->id));
            $db->at(0);
        }
    }

    /**
     * @throws \Exception
     */
    private function rejectsWrongOwnersAndRepeatedCompletion(StorageDatabase $db): void
    {
        $claim = $this->claim($db);
        $wrong = new RunClaim($claim->id, '11111111-1111-4111-8111-111111111111');
        A::same(false, $db->storage->complete($wrong, RunOutcome::Succeeded, null));
        A::same(false, $db->storage->complete(new RunClaim(PHP_INT_MAX, $claim->ownerToken), RunOutcome::Failed, 'x'));
        $db->at(300);
        $db->storage->abandonExpired('sync', 'worker');
        A::same(false, $db->storage->complete($wrong, RunOutcome::Succeeded, null));
        A::same(true, $db->storage->complete($claim, RunOutcome::Failed, 'original failure'));
        $row = $db->row($claim->id);
        foreach (RunOutcome::cases() as $outcome) {
            A::same(false, $db->storage->complete($claim, $outcome, 'replacement diagnostic'));
            A::same($row, $db->row($claim->id));
        }
    }

    /**
     * @throws \Exception
     */
    private function acceptsCompletionAtAndAfterExpiry(StorageDatabase $db): void
    {
        foreach ([300, 301] as $second) {
            $db->at(0);
            $claim = $this->claim($db, id: (string) $second);
            $db->at($second);
            A::same(true, $db->storage->complete($claim, RunOutcome::Succeeded, null));
            A::same(null, ($db->row($claim->id)['abandoned_at'] ?? null));
            A::same(0, $db->storage->abandonExpired((string) $second, 'worker'));
        }
    }

    /**
     * @throws \Exception
     */
    private function ignoresLiveUnprotectedAndOtherSchedules(StorageDatabase $db): void
    {
        $expired = $this->claim($db);
        $other = $this->claim($db, id: 'other');
        $unprotected = $this->claim($db, id: 'unprotected', overlap: false);
        $db->at(299);
        A::same(0, $db->storage->abandonExpired('sync', 'early'));
        $db->at(300);
        A::same(1, $db->storage->abandonExpired('sync', 'worker'));
        A::same('abandoned', ($db->row($expired->id)['status'] ?? null));
        A::same('in_progress', ($db->row($other->id)['status'] ?? null));
        A::same('in_progress', ($db->row($unprotected->id)['status'] ?? null));
    }

    /**
     * @throws \Exception
     */
    public function claim(
        StorageDatabase $db,
        string $id = 'sync',
        int $second = 0,
        bool $single = true,
        bool $overlap = true
    ): RunClaim {
        return $this->reserve($db, $id, $second, $single, $overlap) ?? throw new \LogicException('Expected claim.');
    }

    /**
     * @throws \Exception
     */
    private function reserve(
        StorageDatabase $db,
        string $id,
        int $second = 0,
        bool $single = true,
        bool $overlap = true
    ): ?RunClaim {
        return $db->storage->reserve(
            new ScheduleDefinition(
                $id,
                'Task',
                '* * * * *',
                withoutOverlapping: $overlap,
                overlapExpiresAfterMinutes: 5,
                onOneServer: $single
            ),
            $this->minute($second),
            'claim-worker'
        );
    }

    /**
     * @throws \DateMalformedStringException
     */
    private function minute(int $second): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-26 10:00:00 UTC')->modify('+' . $second . ' seconds');
    }
}
