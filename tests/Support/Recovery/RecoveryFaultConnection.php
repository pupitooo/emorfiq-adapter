<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use LogicException;
use Override;
use PDO;
use PDOException;

/**
 * Let advisory reads commit normally; lose acknowledgement only after a real reservation commit.
 */
final class RecoveryFaultConnection extends PDO
{
    #[Override]
    public function commit(): bool
    {
        $statement = $this->query(<<<'SQL'
            SELECT 1 FROM task_runs
            WHERE xmin = pg_current_xact_id()::text::xid AND status = 'in_progress'
            LIMIT 1
            SQL);
        if ($statement === false) {
            throw new LogicException('Unable to inspect fault injection boundary.');
        }
        $hasReservation = $statement->fetchColumn() !== false;
        $committed = parent::commit();
        if ($hasReservation) {
            throw new PDOException('Injected lost reservation commit acknowledgement.');
        }
        return $committed;
    }
}
