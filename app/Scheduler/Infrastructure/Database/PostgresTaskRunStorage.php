<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Database;

use App\Database\PostgresConnectionFactory;
use App\Scheduler\Contract\TaskRunStorage;
use App\Scheduler\Domain\RunClaim;
use App\Scheduler\Domain\RunOutcome;
use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Exception\MutexStorageException;
use DateTimeImmutable;
use Override;
use PDO;
use PDOException;

/**
 * PostgreSQL coordination on a dedicated, autocommit connection.
 */
final readonly class PostgresTaskRunStorage implements TaskRunStorage
{
    private PostgresRunTransaction $transaction;

    public function __construct(
        private PDO $connection
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
        } catch (PDOException $failure) {
            throw new MutexStorageException('Unable to connect to PostgreSQL task run storage.', previous: $failure);
        }
    }

    #[Override]
    public function hasLiveOverlap(string $scheduleId): bool
    {
        return $this->transaction->run(fn(): bool => $this->query(<<<'SQL'
            SELECT 1 FROM task_runs
            WHERE schedule_id = ? AND without_overlapping AND status = 'in_progress'
              AND lease_expires_at > clock_timestamp()
            SQL
            , [$scheduleId])->fetchColumn() !== false);
    }

    #[Override]
    public function hasOccurrence(string $scheduleId, DateTimeImmutable $scheduledFor): bool
    {
        return $this->transaction->run(fn(): bool => $this->query(<<<'SQL'
            SELECT 1 FROM task_runs WHERE schedule_id = ? AND scheduled_for = ? AND on_one_server
            SQL
            , [$scheduleId, $scheduledFor->format('Y-m-d H:i:s.uP')])->fetchColumn() !== false);
    }

    #[Override]
    public function reserve(
        ScheduleDefinition $definition,
        DateTimeImmutable $scheduledFor,
        string $claimedBy,
    ): ?RunClaim {
        return $this->transaction->reserve(function () use ($definition, $scheduledFor, $claimedBy): ?RunClaim {
            $this->recover($definition->id, $claimedBy);

            return $this->insert($definition, $scheduledFor);
        });
    }

    #[Override]
    public function complete(RunClaim $claim, RunOutcome $outcome, ?string $error): bool
    {
        return $this->transaction->run(function () use ($claim, $outcome, $error): bool {
            // Acquire the lock before reading the completion clock, including across expiry.
            $this->query('SELECT id FROM task_runs WHERE id = ? FOR UPDATE', [$claim->id]);

            return $this->query(<<<'SQL'
                UPDATE task_runs SET status = ?, finished_at = clock_timestamp(), error_message = ?
                WHERE id = ? AND owner_token = ? AND status IN ('in_progress', 'abandoned')
                SQL
                , [$outcome->value, $error, $claim->id, $claim->ownerToken])->rowCount() === 1;
        });
    }

    #[Override]
    public function abandonExpired(string $scheduleId, string $recoveredBy): int
    {
        return $this->transaction->run(fn(): int => $this->recover($scheduleId, $recoveredBy));
    }

    /**
     * @throws \PDOException
     */
    private function recover(string $scheduleId, string $recoveredBy): int
    {
        // Lock even currently live candidates: expiry can pass while waiting for their row locks.
        $this->query(<<<'SQL'
            SELECT id FROM task_runs
            WHERE schedule_id = ? AND without_overlapping AND status = 'in_progress'
            ORDER BY id FOR UPDATE
            SQL
            , [$scheduleId]);

        return $this->query(<<<'SQL'
            UPDATE task_runs
            SET status = 'abandoned', abandoned_at = COALESCE(abandoned_at, clock_timestamp()),
                abandoned_by = COALESCE(abandoned_by, ?)
            WHERE schedule_id = ? AND without_overlapping AND status = 'in_progress'
              AND lease_expires_at <= clock_timestamp()
            SQL
            , [$recoveredBy, $scheduleId])->rowCount();
    }

    /**
     * @throws \PDOException
     */
    private function insert(ScheduleDefinition $definition, DateTimeImmutable $scheduledFor): ?RunClaim
    {
        $statement = $this->query(<<<'SQL'
            WITH instant AS MATERIALIZED (SELECT clock_timestamp() AS now)
            INSERT INTO task_runs (
                schedule_id, scheduled_for, task_class, cron_expression, schedule_timezone,
                without_overlapping, overlap_expires_after_minutes, on_one_server,
                status, owner_token, started_at, lease_expires_at
            ) SELECT ?, ?, ?, ?, ?, CAST(? AS boolean), CAST(? AS integer), CAST(? AS boolean),
                'in_progress', gen_random_uuid(), now,
                CASE WHEN CAST(? AS boolean) THEN now + CAST(? AS integer) * interval '1 minute' END
            FROM instant ON CONFLICT DO NOTHING RETURNING id, owner_token
            SQL
            , $this->snapshot($definition, $scheduledFor));
        $row = $statement->fetch(PDO::FETCH_NUM);
        if ($row === false) {
            return null;
        }
        if (! is_int($row[0] ?? null) || ! is_string($row[1] ?? null)) {
            throw new \LogicException('Invalid PostgreSQL reservation result.');
        }

        return new RunClaim($row[0], $row[1]);
    }

    /**
     * @return list<int|string>
     */
    private function snapshot(ScheduleDefinition $definition, DateTimeImmutable $scheduledFor): array
    {
        return [
            $definition->id, $scheduledFor->format('Y-m-d H:i:s.uP'), $definition->task,
            $definition->cron, $definition->timezone, $definition->withoutOverlapping ? 'true' : 'false',
            $definition->overlapExpiresAfterMinutes, $definition->onOneServer ? 'true' : 'false',
            $definition->withoutOverlapping ? 'true' : 'false', $definition->overlapExpiresAfterMinutes,
        ];
    }

    /**
     * @param list<int|string|null> $parameters
     * @throws \PDOException
     */
    private function query(string $sql, array $parameters): \PDOStatement
    {
        $statement = $this->connection->prepare($sql);
        if ($statement === false) {
            throw new \PDOException('Unable to prepare task run statement.');
        }
        $statement->execute($parameters);

        return $statement;
    }
}
