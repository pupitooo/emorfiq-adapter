<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use Override;
use PDO;

final class RecoveryCommitConnection extends PDO
{
    /**
     * @throws \PDOException
     */
    #[Override]
    public function commit(): bool
    {
        try {
            RecoveryChannel::pause('commit');
        } catch (\JsonException $failure) {
            throw new \PDOException('Commit barrier encoding failed.', previous: $failure);
        }
        return parent::commit();
    }
}
