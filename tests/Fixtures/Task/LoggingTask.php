<?php

declare(strict_types=1);

namespace Tests\Fixtures\Task;

use App\Task\Contract\Task;
use Override;
use Psr\Log\LoggerInterface;

final readonly class LoggingTask implements Task
{
    public function __construct(
        public LoggerInterface $logger,
    ) {}

    /**
     * @throws void
     */
    #[Override]
    public function run(): void
    {
        $this->logger->info('fixture task');
    }
}
