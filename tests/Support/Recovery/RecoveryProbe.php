<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use LogicException;
use PDO;

/**
 * Durable observations and receiver fixtures; independent of the scheduler connection.
 */
final readonly class RecoveryProbe
{
    public function __construct(
        private PDO $connection
    ) {}

    /**
     * @param list<int|string> $values
     * @throws \PDOException
     */
    public function change(string $sql, array $values = []): bool
    {
        $statement = $this->connection->prepare($sql);
        if ($statement === false) {
            throw new LogicException('Unable to prepare recovery probe.');
        }
        $statement->execute($values);
        return $statement->rowCount() === 1;
    }

    /**
     * @throws \PDOException
     */
    public function increment(string $name): void
    {
        $this->change(<<<'SQL'
            INSERT INTO recovery_probes VALUES (?, 1)
            ON CONFLICT (name) DO UPDATE SET value = recovery_probes.value + 1
            SQL
            , [$name]);
    }

    /**
     * @throws \PDOException
     */
    public function count(string $name): int
    {
        $statement = $this->connection->prepare('SELECT value FROM recovery_probes WHERE name = ?');
        if ($statement === false) {
            throw new LogicException('Unable to read recovery probe.');
        }
        $statement->execute([$name]);
        $value = $statement->fetchColumn();
        return $value === false ? 0 : (int) $value;
    }

    /**
     * @throws \PDOException
     */
    public function effect(): void
    {
        $this->change('INSERT INTO receiver_effects DEFAULT VALUES');
    }

    /**
     * @throws \PDOException
     */
    public function effectCount(): int
    {
        $statement = $this->connection->query('SELECT count(*) FROM receiver_effects');
        if ($statement === false) {
            throw new LogicException('Unable to count receiver effects.');
        }
        return (int) $statement->fetchColumn();
    }

    /**
     * @throws \PDOException
     */
    public function applyIdempotent(string $operationKey): bool
    {
        return $this->change(<<<'SQL'
            INSERT INTO receiver_effects (operation_key) VALUES (?) ON CONFLICT DO NOTHING
            SQL
            , [$operationKey]);
    }

    /**
     * @throws \PDOException
     */
    public function applyFenced(string $resource, int $generation): bool
    {
        return $this->change(<<<'SQL'
            WITH accepted AS (
                INSERT INTO receiver_generations VALUES (?, ?)
                ON CONFLICT (resource) DO UPDATE SET generation = EXCLUDED.generation
                WHERE receiver_generations.generation <= EXCLUDED.generation
                RETURNING resource
            )
            INSERT INTO receiver_effects (operation_key) SELECT NULL FROM accepted
            SQL
            , [$resource, $generation]);
    }

    /**
     * @throws \PDOException
     */
    public function completeRequest(int $id): bool
    {
        return $this->change(<<<'SQL'
            WITH accepted AS (
                UPDATE receiver_requests SET completed = true WHERE run_id = ? AND NOT completed RETURNING run_id
            )
            INSERT INTO receiver_effects (operation_key) SELECT NULL FROM accepted
            SQL
            , [$id]);
    }
}
