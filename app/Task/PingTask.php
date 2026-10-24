<?php

declare(strict_types=1);

namespace App\Task;

use Psr\Log\LoggerInterface;

final readonly class PingTask
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function run(): void
    {
        $this->logger->info('ping', [
            'task' => 'ping',
        ]);
    }
}
