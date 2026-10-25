<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use App\Database\PostgresConnectionFactory;
use App\Scheduler\Application\ExecutionCoordinator;
use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Infrastructure\Database\PostgresTaskRunStorage;
use DateTimeImmutable;
use LogicException;
use PDO;
use Tests\Support\Storage\StorageDatabase;

final readonly class RecoveryDatabase
{
    public const string EPOCH = '2026-10-26 10:00:00 UTC';

    /**
     * @param class-string<PDO> $driver
     * @throws \PDOException
     */
    public static function connect(string $schema, string $driver = PDO::class): PDO
    {
        if (preg_match('/^storage_test_[a-f0-9]+$/D', $schema) !== 1) {
            throw new LogicException('Invalid recovery schema.');
        }
        $connection = StorageDatabase::connect($driver);
        $connection->exec('SET search_path TO ' . $schema . ', pg_catalog');
        $connection->exec("SET statement_timeout = '10s'");
        return $connection;
    }

    /**
     * @throws \Dibi\Exception
     * @throws \PDOException
     */
    public static function coordinator(PDO $connection): ExecutionCoordinator
    {
        return new ExecutionCoordinator(new PostgresTaskRunStorage(PostgresConnectionFactory::wrap($connection)));
    }

    public static function definition(
        string $schedule,
        bool $onOneServer = true,
        bool $withoutOverlapping = true,
    ): ScheduleDefinition {
        return new ScheduleDefinition(
            $schedule,
            RecoveryTask::class,
            '* * * * *',
            withoutOverlapping: $withoutOverlapping,
            overlapExpiresAfterMinutes: 5,
            onOneServer: $onOneServer,
        );
    }

    public static function instant(int $seconds): DateTimeImmutable
    {
        $epoch = new DateTimeImmutable(self::EPOCH);
        return $epoch->setTimestamp($epoch->getTimestamp() + $seconds);
    }

    /**
     * @throws \PDOException
     */
    public static function initialize(PDO $connection): void
    {
        $connection->exec(<<<'SQL'
            CREATE TABLE recovery_probes (name text PRIMARY KEY, value integer NOT NULL);
            CREATE TABLE receiver_effects (id bigint GENERATED ALWAYS AS IDENTITY, operation_key text UNIQUE);
            CREATE TABLE receiver_generations (resource text PRIMARY KEY, generation bigint NOT NULL);
            CREATE TABLE receiver_requests (run_id bigint PRIMARY KEY, completed boolean NOT NULL DEFAULT false);
            SQL);
    }
}
