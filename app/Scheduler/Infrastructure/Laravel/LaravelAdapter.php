<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Laravel;

use App\Scheduler\Contract\Adapter;
use App\Scheduler\Contract\SchedulerConfiguration;
use App\Scheduler\Exception\ConfigurationException;
use App\Task\PingTask;
use LogicException;
use Override;

final readonly class LaravelAdapter implements Adapter
{
    public function __construct(
        private PingTask $pingTask,
        private SchedulerConfiguration $configuration,
    ) {}

    /**
     * @throws ConfigurationException
     */
    #[Override]
    public function run(): void
    {
        // Prototype: execute each definition once; cron evaluation belongs to scheduler integration.
        foreach ($this->configuration->getConfiguration()->tasks as $definition) {
            if ($definition->task !== PingTask::class) {
                throw new LogicException(
                    'Unsupported task in schedule "' . $definition->id . '": ' . $definition->task,
                );
            }

            $this->pingTask->run();
        }
    }
}
