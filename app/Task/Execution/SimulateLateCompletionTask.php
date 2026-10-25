<?php

declare(strict_types=1);

namespace App\Task\Execution;

use App\Task\Contract\Task;
use Override;
use Psr\Log\LoggerInterface;

/**
 * Outlives its overlap lock so a later occurrence can recover this run before it completes.
 */
final readonly class SimulateLateCompletionTask implements Task
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    #[Override]
    public function run(): void
    {
        $this->logger->info('Demo: Waiting beyond overlap expiry without renewing the lock.', [
            'event' => 'demo.task.waiting',
            'schedule_id' => 'late-completion',
            'duration_seconds' => 150,
        ]);

        sleep(150);

        $this->logger->info('Demo: Resuming after overlap expiry and completing successfully.', [
            'event' => 'demo.task.resumed',
            'schedule_id' => 'late-completion',
        ]);
    }
}
