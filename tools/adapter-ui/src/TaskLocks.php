<?php

declare(strict_types=1);

namespace DevTools\AdapterUi;

use App\Database\PostgresConnectionFactory;
use Dibi\Connection;
use Dibi\Exception;
use Dibi\Result;
use Dibi\Row;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Read-only lock overview. Never acquires, releases, or recovers locks and never returns owner tokens.
 */
final readonly class TaskLocks
{
    private const string SOURCE = <<<'SQL'
        (SELECT schedule_id, lock_type, scheduled_for, run_id, expires_at,
            CASE WHEN lock_type = 'occurrence' THEN 'reserved'
                WHEN expires_at > CURRENT_TIMESTAMP THEN 'active' ELSE 'expired' END AS lock_state
        FROM task_locks) AS locks
        SQL;

    private const string COLUMNS = <<<'SQL'
        schedule_id, lock_type, lock_state, run_id::text,
        to_char(scheduled_for AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS scheduled_for,
        to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS expires_at
        SQL;

    public function __construct(
        private ?PostgresConnectionFactory $connections = null
    ) {}

    /**
     * @param array<array-key, mixed> $input
     * @return array{rows: list<array<array-key, mixed>>, total: int, page: int, pageSize: int, pages: int}
     * @throws Exception
     * @throws RuntimeException
     */
    public function read(array $input): array
    {
        $conditions = $this->conditions($input);
        $size = $this->pageSize($this->value($input, 'page_size'));
        $page = $this->page($this->value($input, 'page'));
        $connection = ($this->connections ?? PostgresConnectionFactory::fromEnvironment())->connect();
        $connection->begin();
        try {
            $connection->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $connection->query("SET LOCAL statement_timeout = '5s'");
            $result = $this->results($connection, $conditions, $page, $size);
            $connection->commit();

            return $result;
        } catch (Throwable $failure) {
            $connection->rollback();
            throw $failure;
        }
    }

    /**
     * @param array<array-key, mixed> $input
     * @return list<list<string|bool>>
     */
    private function conditions(array $input): array
    {
        $conditions = [];
        foreach (['schedule_id', 'run_id', 'lock_type', 'lock_state', 'history'] as $key) {
            $value = $this->value($input, $key);
            if ($value === '') {
                continue;
            }
            $conditions[] = match ($key) {
                'schedule_id' => ['schedule_id ILIKE %~like~', $value],
                'run_id' => ['run_id = %i', $this->runId($value)],
                'lock_type' => ['lock_type = %s', $this->choice($value, ['overlap', 'occurrence'])],
                'lock_state' => ['lock_state = %s', $this->choice($value, ['active', 'expired', 'reserved'])],
                'history' => ['(run_id IS NOT NULL) = %b', $this->choice($value, ['linked', 'deleted']) === 'linked'],
            };
        }

        return $conditions;
    }

    /**
     * @param array<array-key, mixed> $input
     */
    private function value(array $input, string $key): string
    {
        $value = $input[$key] ?? '';
        if (! is_string($value) || strlen($value) > 500) {
            throw new InvalidArgumentException('Filtr má neplatný formát nebo je příliš dlouhý.');
        }

        return trim($value);
    }

    private function runId(string $value): string
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1
            || (strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0)) {
            throw new InvalidArgumentException('ID běhu musí být kladné celé číslo v rozsahu bigint.');
        }

        return $value;
    }

    private function pageSize(string $value): int
    {
        if ($value === '') {
            return 50;
        }
        return (int) $this->choice($value, ['25', '50', '100']);
    }

    private function page(string $value): int
    {
        if ($value === '') {
            return 1;
        }
        if (preg_match('/^[1-9][0-9]{0,5}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Neplatné číslo stránky.');
        }

        return (int) $value;
    }

    /**
     * @param list<string> $options
     */
    private function choice(string $value, array $options): string
    {
        if (! in_array($value, $options, true)) {
            throw new InvalidArgumentException('Filtr obsahuje nepodporovanou hodnotu.');
        }

        return $value;
    }

    /**
     * @param list<list<string|bool>> $conditions
     * @return array{rows: list<array<array-key, mixed>>, total: int, page: int, pageSize: int, pages: int}
     * @throws Exception
     * @throws RuntimeException
     */
    private function results(Connection $connection, array $conditions, int $page, int $size): array
    {
        $total = $connection->fetchSingle('SELECT count(*) FROM ' . self::SOURCE . ' WHERE %and', $conditions);
        if (! is_int($total)) {
            throw new RuntimeException('Neplatná odpověď databáze.');
        }
        $pages = max(1, (int) ceil($total / $size));
        $page = min($page, $pages);
        $rows = $this->rows($connection->query(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::SOURCE
                . ' WHERE %and ORDER BY schedule_id, lock_type, scheduled_for DESC NULLS LAST %lmt %ofs',
            $conditions,
            $size,
            ($page - 1) * $size
        ));

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pageSize' => $size,
            'pages' => $pages,
        ];
    }

    /**
     * @return list<array<array-key, mixed>>
     * @throws RuntimeException
     */
    private function rows(Result $statement): array
    {
        $rows = [];
        while (($row = $statement->fetch()) !== null) {
            if (! $row instanceof Row) {
                throw new RuntimeException('Neplatná odpověď databáze.');
            }
            $rows[] = $row->toArray();
        }

        return $rows;
    }
}
