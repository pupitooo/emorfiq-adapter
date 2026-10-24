<?php

declare(strict_types=1);

namespace App\Task\Execution;

use App\Task\Contract\Task;
use Override;
use Psr\Log\LoggerInterface;

final readonly class PingTask implements Task
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
        $this->logger->info('ping', [
            'task' => 'ping',
        ]);
    }
}
