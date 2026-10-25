<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Database;

use App\Scheduler\Exception\MutexStorageException;
use App\Scheduler\Exception\ReservationUnconfirmedException;
use Closure;
use Dibi\Connection;
use Dibi\Exception;
use PDO;
use PDOException;
use Throwable;

/**
 * Owns short transactions; never commits or rolls back a caller's transaction.
 */
final readonly class PostgresRunTransaction
{
    private PDO $resource;

    public function __construct(
        private Connection $connection
    ) {
        $resource = $connection->getDriver()->getResource();
        if (! $resource instanceof PDO) {
            throw new \LogicException('Task run storage requires PDO PostgreSQL driver.');
        }
        $this->resource = $resource;
    }

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     * @throws MutexStorageException
     */
    public function run(Closure $operation): mixed
    {
        return $this->execute(operation: $operation, reservation: false);
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
        return $this->execute(operation: $operation, reservation: true);
    }

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     * @throws MutexStorageException
     * @throws ($reservation is true ? ReservationUnconfirmedException : void)
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
            if ($this->resource->inTransaction()) {
                throw new MutexStorageException('Task run storage requires its own transaction.');
            }
            $this->connection->begin();
            $this->connection->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        } catch (Exception|PDOException $failure) {
            $this->rollback();
            throw new MutexStorageException('Unable to begin PostgreSQL task run transaction.', previous: $failure);
        }
    }

    /**
     * @throws MutexStorageException
     * @throws ($reservation is true ? ReservationUnconfirmedException : void)
     */
    private function commit(bool $reservation): void
    {
        try {
            $this->connection->commit();
        } catch (Exception|PDOException $failure) {
            $rejected = $this->commitWasRejected($failure);
            $this->rollback();
            if ($reservation && ! $rejected) {
                throw new ReservationUnconfirmedException(
                    'PostgreSQL reservation commit is unconfirmed.',
                    previous: $failure
                );
            }
            throw new MutexStorageException('PostgreSQL task run commit failed.', previous: $failure);
        }
    }

    private function commitWasRejected(Throwable $failure): bool
    {
        // PDO's Dibi driver may expose a numeric driver code instead of SQLSTATE at commit.
        $state = $this->resource->errorInfo()[0] ?? null;
        $state = is_string($state) ? $state : (string) $failure->getCode();

        return in_array(substr($state, 0, 2), ['23', '40'], true);
    }

    private function rollback(): void
    {
        try {
            if ($this->resource->inTransaction()) {
                $this->connection->rollback();
            }
        } catch (Exception|PDOException) {
            // Preserve the original failure, including an unknown commit outcome after connection loss.
        }
    }
}
