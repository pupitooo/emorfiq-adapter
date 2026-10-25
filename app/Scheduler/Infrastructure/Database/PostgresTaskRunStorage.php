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
            SELECT 1 FROM task_runs
            WHERE schedule_id = %s AND without_overlapping AND status = 'in_progress'
              AND lease_expires_at > clock_timestamp()
            SQL
            , $scheduleId) !== null);
    }

    #[Override]
    public function hasOccurrence(string $scheduleId, DateTimeImmutable $scheduledFor): bool
    {
        return $this->transaction->run(fn(): bool => $this->connection->fetchSingle(<<<'SQL'
            SELECT 1 FROM task_runs WHERE schedule_id = %s AND scheduled_for = %s AND on_one_server
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
            // Acquire the lock before reading the completion clock, including across expiry.
            $this->connection->query('SELECT id FROM task_runs WHERE id = %i FOR UPDATE', $claim->id);
            $this->connection->query(
                'UPDATE task_runs SET %a WHERE id = %i AND owner_token = %s AND status IN %in',
                [
                    'status' => $outcome->value,
                    'finished_at' => Connection::expression('clock_timestamp()'),
                    'error_message' => $error,
                ],
                $claim->id,
                $claim->ownerToken,
                ['in_progress', 'abandoned']
            );

            return $this->connection->getAffectedRows() === 1;
        });
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
        // Lock even currently live candidates: expiry can pass while waiting for their row locks.
        $this->connection->query(<<<'SQL'
            SELECT id FROM task_runs
            WHERE schedule_id = %s AND without_overlapping AND status = 'in_progress'
            ORDER BY id FOR UPDATE
            SQL
            , $scheduleId);
        $this->connection->query(<<<'SQL'
            UPDATE task_runs
            SET status = 'abandoned', abandoned_at = COALESCE(abandoned_at, clock_timestamp()),
                abandoned_by = COALESCE(abandoned_by, %s)
            WHERE schedule_id = %s AND without_overlapping AND status = 'in_progress'
              AND lease_expires_at <= clock_timestamp()
            SQL
            , $recoveredBy, $scheduleId);

        return $this->connection->getAffectedRows();
    }

    /**
     * @throws Exception
     */
    private function insertReservation(ScheduleDefinition $definition, DateTimeImmutable $scheduledFor): ?RunClaim
    {
        $row = $this->connection->fetch(<<<'SQL'
            WITH instant AS MATERIALIZED (SELECT clock_timestamp() AS now)
            INSERT INTO task_runs %v ON CONFLICT DO NOTHING RETURNING id, owner_token
            SQL
            , $this->reservationValues($definition, $scheduledFor));
        if ($row === null) {
            return null;
        }
        if (! is_int($row['id']) || ! is_string($row['owner_token'])) {
            throw new \LogicException('Invalid PostgreSQL reservation result.');
        }

        return new RunClaim(id: $row['id'], ownerToken: $row['owner_token']);
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
            'status' => 'in_progress',
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
