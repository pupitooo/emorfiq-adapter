<?php

declare(strict_types=1);

namespace Tests\Support\Storage;

use App\Scheduler\Infrastructure\Database\PostgresTaskRunStorage;
use LogicException;
use PDO;

final readonly class StorageDatabase
{
    public PDO $connection;

    public PostgresTaskRunStorage $storage;

    public string $schema;

    /**
     * @throws \Exception
     */
    public function __construct()
    {
        $this->schema = 'storage_test_' . bin2hex(random_bytes(8));
        $this->connection = self::connect();
        $this->connection->exec('CREATE SCHEMA ' . $this->schema);
        try {
            $this->initialize();
        } catch (\Throwable $failure) {
            $this->close();
            throw $failure;
        }
        $this->storage = new PostgresTaskRunStorage($this->connection);
    }

    /**
     * @param class-string<PDO> $driver
     * @throws \PDOException
     */
    public static function connect(string $driver = PDO::class): PDO
    {
        return new $driver(
            self::environment('TEST_DATABASE_DSN'),
            self::environment('TEST_DATABASE_USER'),
            self::environment('TEST_DATABASE_PASSWORD'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]
        );
    }

    private static function environment(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new LogicException('Missing required storage test setting: ' . $name);
        }

        return $value;
    }

    /**
     * @throws \PDOException
     */
    private function initialize(): void
    {
        $this->connection->exec('SET search_path TO ' . $this->schema . ', pg_catalog');
        foreach (['001_task_runs.sql', '002_task_run_completion.sql'] as $migration) {
            $sql = file_get_contents(dirname(__DIR__, 3) . '/migrations/' . $migration);
            if ($sql === false) {
                throw new LogicException('Unable to read storage migration.');
            }
            $this->connection->exec($sql);
        }
        $this->connection->exec(<<<'SQL'
            CREATE TABLE test_clock (now timestamptz NOT NULL);
            INSERT INTO test_clock VALUES ('2026-10-26 10:00:00+00');
            CREATE FUNCTION clock_timestamp() RETURNS timestamptz LANGUAGE sql VOLATILE
                AS 'SELECT now FROM test_clock';
            SQL);
    }

    /**
     * @throws \PDOException
     */
    public function at(int $seconds): void
    {
        $statement = $this->prepare(<<<'SQL'
            UPDATE test_clock SET now = '2026-10-26 10:00:00+00'::timestamptz + ? * interval '1 second'
            SQL);
        $statement->execute([$seconds]);
    }

    /**
     * @return array<string, mixed>
     * @throws \PDOException
     */
    public function row(int $id): array
    {
        $statement = $this->prepare('SELECT * FROM task_runs WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new LogicException('Expected stored run.');
        }

        return $row;
    }

    /**
     * @throws \PDOException
     */
    public function prepare(string $sql): \PDOStatement
    {
        $statement = $this->connection->prepare($sql);
        if ($statement === false) {
            throw new LogicException('Unable to prepare fixture query.');
        }

        return $statement;
    }

    /**
     * @throws \PDOException
     */
    public function value(string $sql): int|string|bool|null
    {
        $statement = $this->connection->query($sql);
        if ($statement === false) {
            throw new LogicException('Fixture query failed.');
        }

        return $statement->fetchColumn();
    }

    /**
     * @throws \PDOException
     */
    public function close(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
        $this->connection->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
    }
}
