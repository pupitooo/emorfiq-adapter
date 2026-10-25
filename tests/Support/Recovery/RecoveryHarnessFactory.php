<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use Tests\Support\Storage\StorageDatabase;

final readonly class RecoveryHarnessFactory
{
    /**
     * @throws \Exception
     */
    public static function create(): RecoveryHarness
    {
        $database = new StorageDatabase();
        try {
            return new PostgresRecoveryHarness($database);
        } catch (\Throwable $failure) {
            $database->close();
            throw $failure;
        }
    }
}
