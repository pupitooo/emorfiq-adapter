<?php

declare(strict_types=1);

namespace App\Task\Execution;

use App\Task\Contract\Task;
use App\Task\Infrastructure\Demo\TaskSimulation;
use Override;

final readonly class PrepareSalesReportTask implements Task
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
            scheduleId: 'sales-report',
            operation: 'Preparing sales report.'
        );
    }
}
