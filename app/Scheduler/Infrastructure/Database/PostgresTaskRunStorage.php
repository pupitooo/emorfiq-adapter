<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Database;

use App\Database\PostgresConnectionFactory;
use App\Scheduler\Contract\TaskRunStorage;
use App\Scheduler\Domain\RunClaim;
use App\Scheduler\Domain\RunOutcome;
use App\Scheduler\Domain\RunStatus;
use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Exception\MutexStorageException;
use DateTimeImmutable;
use Dibi\Connection;
use Dibi\Exception;
use Dibi\Expression;
use Override;

/**
 * PostgreSQL coordination on a dedicated, autocommit connection.
 */
final readonly class PostgresTaskRunStorage implements TaskRunStorage
{
    private PostgresRunTransaction $transaction;

    public function __construct(
        private Connection $connection
    ) {
        $this->transaction = new PostgresRunTransaction($connection);
    }

    /**
     * @throws MutexStorageException
     */
    public static function connect(PostgresConnectionFactory $connections): self
    {
        try {
            return new self($connections->connect());
        } catch (Exception $failure) {
            throw new MutexStorageException('Unable to connect to PostgreSQL task run storage.', previous: $failure);
        }
    }

    #[Override]
    public function hasLiveOverlap(string $scheduleId): bool
    {
        return $this->transaction->run(fn(): bool => $this->connection->fetchSingle(<<<'SQL'
            SELECT 1 FROM task_locks
            WHERE schedule_id = %s AND lock_type = 'overlap'
              AND expires_at > clock_timestamp()
            SQL
            , $scheduleId) !== null);
    }

    #[Override]
    public function hasOccurrence(string $scheduleId, DateTimeImmutable $scheduledFor): bool
    {
        return $this->transaction->run(fn(): bool => $this->connection->fetchSingle(<<<'SQL'
            SELECT 1 FROM task_locks
            WHERE schedule_id = %s AND scheduled_for = %s AND lock_type = 'occurrence'
            SQL
            , $scheduleId, $scheduledFor->format('Y-m-d H:i:s.uP')) !== null);
    }

    #[Override]
    public function reserve(
        ScheduleDefinition $definition,
        DateTimeImmutable $scheduledFor,
        string $claimedBy,
    ): ?RunClaim {
        return $this->transaction->reserve(function () use ($definition, $scheduledFor, $claimedBy): ?RunClaim {
            $this->abandonExpiredWithinTransaction(scheduleId: $definition->id, recoveredBy: $claimedBy);

            return $this->insertReservation($definition, $scheduledFor);
        });
    }

    #[Override]
    public function complete(RunClaim $claim, RunOutcome $outcome, ?string $error): bool
    {
        return $this->transaction->run(function () use ($claim, $outcome, $error): bool {
            $this->lockCompletionRows($claim);
            if (! $this->recordCompletion($claim, $outcome, $error)) {
                return false;
            }
            $this->connection->query(<<<'SQL'
                DELETE FROM task_locks WHERE run_id = %i AND owner_token = %s AND lock_type = 'overlap'
                SQL
                , $claim->id, $claim->ownerToken);

            return true;
        });
    }

    /**
     * @throws Exception
     */
    private function recordCompletion(RunClaim $claim, RunOutcome $outcome, ?string $error): bool
    {
        $this->connection->query(
            'UPDATE task_runs SET %a WHERE id = %i AND owner_token = %s AND status IN %in',
            [
                'status' => $outcome->value,
                'finished_at' => Connection::expression('clock_timestamp()'),
                'error_message' => $error,
            ],
            $claim->id,
            $claim->ownerToken,
            [RunStatus::InProgress->value, RunStatus::Abandoned->value]
        );
        return $this->connection->getAffectedRows() === 1;
    }

    /**
     * @throws Exception
     */
    private function lockCompletionRows(RunClaim $claim): void
    {
        // Match recovery's lock order before reading the completion clock, including across expiry.
        $this->connection->query(<<<'SQL'
            SELECT lock_type FROM task_locks
            WHERE run_id = %i AND owner_token = %s ORDER BY lock_type FOR UPDATE
            SQL
            , $claim->id, $claim->ownerToken);
        $this->connection->query('SELECT id FROM task_runs WHERE id = %i FOR UPDATE', $claim->id);
    }

    #[Override]
    public function abandonExpired(string $scheduleId, string $recoveredBy): int
    {
        return $this->transaction->run(fn(): int => $this->abandonExpiredWithinTransaction($scheduleId, $recoveredBy));
    }

    /**
     * @throws Exception
     */
    private function abandonExpiredWithinTransaction(string $scheduleId, string $recoveredBy): int
    {
        $this->lockOverlap($scheduleId);
        $count = $this->connection->fetchSingle(<<<'SQL'
            WITH expired AS (DELETE FROM task_locks
                WHERE schedule_id = %s AND lock_type = 'overlap' AND expires_at <= clock_timestamp()
                RETURNING run_id
            ), abandoned AS (
                UPDATE task_runs
                SET status = %s, abandoned_at = COALESCE(abandoned_at, clock_timestamp()),
                    abandoned_by = COALESCE(abandoned_by, %s)
                FROM expired
                WHERE task_runs.id = expired.run_id AND task_runs.status = %s
                RETURNING task_runs.id
            )
            SELECT count(*) FROM expired
            SQL
            , $scheduleId, RunStatus::Abandoned->value, $recoveredBy, RunStatus::InProgress->value);
        if (! is_int($count)) {
            throw new \LogicException('Invalid PostgreSQL recovery result.');
        }

        return $count;
    }

    /**
     * @throws Exception
     */
    private function lockOverlap(string $scheduleId): void
    {
        // Lock even currently live candidates: expiry can pass while waiting for their row locks.
        $this->connection->query(<<<'SQL'
            SELECT run_id FROM task_locks
            WHERE schedule_id = %s AND lock_type = 'overlap' FOR UPDATE
            SQL
            , $scheduleId);
    }

    /**
     * @throws Exception
     */
    private function insertReservation(ScheduleDefinition $definition, DateTimeImmutable $scheduledFor): ?RunClaim
    {
        $row = $this->connection->fetch(<<<'SQL'
            WITH instant AS MATERIALIZED (SELECT clock_timestamp() AS now)
            INSERT INTO task_runs %v RETURNING id, owner_token
            SQL
            , $this->reservationValues($definition, $scheduledFor));
        if ($row === null || ! is_int($row['id']) || ! is_string($row['owner_token'])) {
            throw new \LogicException('Invalid PostgreSQL reservation result.');
        }

        $claim = new RunClaim(id: $row['id'], ownerToken: $row['owner_token']);
        if (! $this->acquireLocks($definition, $claim)) {
            // Keep recovery, but discard this denied attempt and any partially acquired locks.
            $this->connection->query('DELETE FROM task_locks WHERE run_id = %i', $claim->id);
            $this->connection->query('DELETE FROM task_runs WHERE id = %i', $claim->id);

            return null;
        }

        return $claim;
    }

    /**
     * Acquires only requested protections; history rows themselves never enforce concurrency.
     *
     * @throws Exception
     */
    private function acquireLocks(ScheduleDefinition $definition, RunClaim $claim): bool
    {
        $protections = [
            'occurrence' => $definition->onOneServer,
            'overlap' => $definition->withoutOverlapping,
        ];
        foreach ($protections as $type => $enabled) {
            if (! $enabled) {
                continue;
            }
            if (! $this->insertLock($claim, $type)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @throws Exception
     */
    private function insertLock(RunClaim $claim, string $type): bool
    {
        $this->connection->query(<<<'SQL'
            INSERT INTO task_locks (schedule_id, lock_type, scheduled_for, owner_token, run_id, expires_at)
            SELECT schedule_id, %s, CASE WHEN %s = 'occurrence' THEN scheduled_for END,
                owner_token, id, CASE WHEN %s = 'overlap' THEN lease_expires_at END
            FROM task_runs WHERE id = %i
            ON CONFLICT DO NOTHING
            SQL
            , $type, $type, $type, $claim->id);
        return $this->connection->getAffectedRows() === 1;
    }

    /**
     * @return array<string, int|string|bool|Expression>
     */
    private function reservationValues(ScheduleDefinition $definition, DateTimeImmutable $scheduledFor): array
    {
        return [
            'schedule_id' => $definition->id,
            // PDO's Dibi datetime modifier omits the timezone; keep the full instant and microseconds.
            'scheduled_for' => $scheduledFor->format('Y-m-d H:i:s.uP'),
            'task_class' => $definition->task,
            'cron_expression' => $definition->cron,
            'schedule_timezone' => $definition->timezone,
            'without_overlapping%b' => $definition->withoutOverlapping,
            'overlap_expires_after_minutes%i' => $definition->overlapExpiresAfterMinutes,
            'on_one_server%b' => $definition->onOneServer,
            'status' => RunStatus::InProgress->value,
            'owner_token' => Connection::expression('gen_random_uuid()'),
            'started_at' => Connection::expression('(SELECT now FROM instant)'),
            'lease_expires_at' => Connection::expression(
                "CASE WHEN %b THEN (SELECT now FROM instant) + %i * interval '1 minute' END",
                $definition->withoutOverlapping,
                $definition->overlapExpiresAfterMinutes
            ),
        ];
    }
}
