<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Database;

use App\Scheduler\Exception\MutexStorageException;
use App\Scheduler\Exception\ReservationUnconfirmedException;
use Closure;
use PDO;
use PDOException;
use Throwable;

/**
 * Owns short transactions; never commits or rolls back a caller's transaction.
 */
final readonly class PostgresRunTransaction
{
    public function __construct(
        private PDO $connection
    ) {}

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     * @throws MutexStorageException
     */
    public function run(Closure $operation): mixed
    {
        try {
            return $this->execute($operation, false);
        } catch (ReservationUnconfirmedException $failure) {
            throw new MutexStorageException('PostgreSQL task run commit is unconfirmed.', previous: $failure);
        }
    }

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     * @throws MutexStorageException
     * @throws ReservationUnconfirmedException
     */
    public function reserve(Closure $operation): mixed
    {
        return $this->execute($operation, true);
    }

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     * @throws MutexStorageException
     * @throws ReservationUnconfirmedException
     */
    private function execute(Closure $operation, bool $reservation): mixed
    {
        $this->begin();
        try {
            $result = $operation();
        } catch (Throwable $failure) {
            $this->rollback();
            throw new MutexStorageException('PostgreSQL task run operation failed.', previous: $failure);
        }
        $this->commit($reservation);

        return $result;
    }

    /**
     * @throws MutexStorageException
     */
    private function begin(): void
    {
        try {
            if ($this->connection->inTransaction()) {
                throw new MutexStorageException('Task run storage requires its own transaction.');
            }
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $this->connection->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
            if (! $this->connection->beginTransaction()) {
                throw new PDOException('PostgreSQL transaction did not begin.');
            }
            $this->connection->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        } catch (PDOException $failure) {
            $this->rollback();
            throw new MutexStorageException('Unable to begin PostgreSQL task run transaction.', previous: $failure);
        }
    }

    /**
     * @throws MutexStorageException
     * @throws ReservationUnconfirmedException
     */
    private function commit(bool $reservation): void
    {
        try {
            if (! $this->connection->commit()) {
                throw new PDOException('PostgreSQL commit was not acknowledged.');
            }
        } catch (PDOException $failure) {
            $this->rollback();
            // Server-reported constraint and transaction rollbacks are definitive failures.
            $rejected = in_array(substr((string) $failure->getCode(), 0, 2), ['23', '40'], true);
            if ($reservation && ! $rejected) {
                throw new ReservationUnconfirmedException(
                    'PostgreSQL reservation commit is unconfirmed.',
                    previous: $failure
                );
            }
            throw new MutexStorageException('PostgreSQL task run commit failed.', previous: $failure);
        }
    }

    private function rollback(): void
    {
        try {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
        } catch (PDOException) {
            // Preserve the original failure, including an unknown commit outcome after connection loss.
        }
    }
}
