<?php

declare(strict_types=1);

namespace Tests\Support\Storage;

use Override;
use PDO;
use PDOException;

/**
 * Simulates losing the acknowledgement after a real PostgreSQL commit.
 */
final class CommitFaultConnection extends PDO
{
    #[Override]
    public function commit(): bool
    {
        parent::commit();
        throw new PDOException('Injected lost commit acknowledgement.');
    }
}
