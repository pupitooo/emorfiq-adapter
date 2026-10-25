<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use LogicException;

final readonly class RecoveryHarnessFactory
{
    /**
     * @throws LogicException
     */
    public static function create(): RecoveryHarness
    {
        throw new LogicException(
            'Pending: connect RecoveryHarness to production PostgreSQL coordination and worker fault injection.',
        );
    }
}
