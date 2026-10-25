<?php

declare(strict_types=1);

namespace App\Task\Execution;

use App\Task\Contract\Task;
use Override;
use Psr\Log\LoggerInterface;

/**
 * Leaves the run unfinished so the next claim after overlap expiry can abandon it.
 */
final readonly class SimulateWorkerCrashTask implements Task
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws void
     */
    #[Override]
    public function run(): void
    {
        $this->logger->critical('Demo: Simulating worker crash without recording completion.', [
            'event' => 'demo.task.crashed',
            'schedule_id' => 'worker-crash',
        ]);

        exit(1);
    }
}
