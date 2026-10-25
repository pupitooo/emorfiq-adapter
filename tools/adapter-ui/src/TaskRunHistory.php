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
 * Read-only tooling query; never invokes execution coordination or exposes owner tokens.
 */
final readonly class TaskRunHistory
{
    private const string COLUMNS = <<<'SQL'
        id::text, schedule_id, task_class, cron_expression, schedule_timezone,
        without_overlapping, overlap_expires_after_minutes, on_one_server, status,
        to_char(scheduled_for AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS scheduled_for,
        to_char(started_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS started_at,
        to_char(lease_expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS lease_expires_at,
        to_char(finished_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS finished_at,
        to_char(abandoned_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS abandoned_at,
        error_message, abandoned_by,
        EXISTS (SELECT 1 FROM task_locks WHERE run_id = task_runs.id
            AND lock_type = 'overlap' AND expires_at > CURRENT_TIMESTAMP) AS overlap_locked,
        EXISTS (SELECT 1 FROM task_locks WHERE run_id = task_runs.id
            AND lock_type = 'overlap' AND expires_at <= CURRENT_TIMESTAMP) AS overlap_expired,
        EXISTS (SELECT 1 FROM task_locks WHERE run_id = task_runs.id
            AND lock_type = 'occurrence') AS occurrence_reserved
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
        $filters = new HistoryFilters($input);
        $conditions = $filters->conditions();
        $size = $this->pageSize($filters->value('page_size'));
        $page = $this->page($filters->value('page'));
        $connection = $this->connect();
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
     * @param list<list<string|bool>> $conditions
     * @return array{rows: list<array<array-key, mixed>>, total: int, page: int, pageSize: int, pages: int}
     * @throws Exception
     * @throws RuntimeException
     */
    private function results(Connection $connection, array $conditions, int $page, int $size): array
    {
        $total = $connection->fetchSingle('SELECT count(*) FROM task_runs WHERE %and', $conditions);
        if (! is_int($total)) {
            throw new RuntimeException('Neplatná odpověď databáze.');
        }
        $pages = max(1, (int) ceil($total / $size));
        $page = min($page, $pages);
        $rows = $this->rows($connection->query(
            'SELECT ' . self::COLUMNS . ' FROM task_runs WHERE %and ORDER BY task_runs.id DESC %lmt %ofs',
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
        while (true) {
            $row = $statement->fetch();
            if ($row === null) {
                return $rows;
            }
            if (! $row instanceof Row) {
                throw new RuntimeException('Neplatná odpověď databáze.');
            }
            $rows[] = $row->toArray();
        }
    }

    /**
     * @throws Exception
     * @throws RuntimeException
     */
    private function connect(): Connection
    {
        return ($this->connections ?? PostgresConnectionFactory::fromEnvironment())->connect();
    }

    private function pageSize(string $value): int
    {
        if ($value === '') {
            return 50;
        }
        if (! in_array($value, ['25', '50', '100'], true)) {
            throw new InvalidArgumentException('Nepodporovaná velikost stránky.');
        }

        return (int) $value;
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
}
