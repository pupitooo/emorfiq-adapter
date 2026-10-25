<?php

declare(strict_types=1);

namespace Tests\Support\Storage;

use LogicException;
use Override;
use PDO;

/**
 * Pauses production SQL after writes, while its transaction still owns the row lock.
 */
final class BarrierConnection extends PDO
{
    #[Override]
    public function commit(): bool
    {
        echo "commit\n";
        flush();
        if (fgets(STDIN) !== "commit\n") {
            throw new LogicException('Commit barrier was not released.');
        }

        return parent::commit();
    }
}
