<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Laravel;

use App\Scheduler\Contract\Adapter;
use App\Task\PingTask;
use Override;

final readonly class LaravelAdapter implements Adapter
{
    public function __construct(
        private PingTask $pingTask,
    ) {}

    /**
     * @throws void
     */
    #[Override]
    public function run(): void
    {
        $this->pingTask->run();
    }
}
