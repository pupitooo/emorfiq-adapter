<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Laravel;

use App\Scheduler\Contract\Adapter;
use App\Scheduler\Contract\SchedulerConfiguration;
use App\Task\Contract\TaskResolver;
use Exception;
use Override;

final readonly class LaravelAdapter implements Adapter
{
    public function __construct(
        private TaskResolver $taskResolver,
        private SchedulerConfiguration $configuration,
    ) {}

    /**
     * @throws Exception
     */
    #[Override]
    public function run(): void
    {
        // Prototype: execute each definition once; cron evaluation belongs to scheduler integration.
        foreach ($this->configuration->getConfiguration()->tasks as $definition) {
            $this->taskResolver->resolve($definition->task)->run();
        }
    }
}
