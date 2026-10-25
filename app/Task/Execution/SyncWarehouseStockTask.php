<?php

declare(strict_types=1);

namespace App\Task\Execution;

use App\Task\Contract\Task;
use App\Task\Infrastructure\Demo\TaskSimulation;
use Override;

final readonly class SyncWarehouseStockTask implements Task
{
    public function __construct(
        private TaskSimulation $simulation,
    ) {}

    /**
     * @throws void
     */
    #[Override]
    public function run(): void
    {
        $this->simulation->run(
            scheduleId: 'warehouse-stock-sync',
            operation: 'Synchronizing stock from ERP.'
        );
    }
}
