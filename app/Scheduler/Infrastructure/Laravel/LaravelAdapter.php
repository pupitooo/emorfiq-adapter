<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Laravel;

use App\Scheduler\Contract\Adapter;
use App\Scheduler\Contract\SchedulerConfiguration;
use App\Scheduler\Infrastructure\Laravel\Runtime\SchedulerApplication;
use App\Task\Contract\TaskResolver;
use Exception;
use Illuminate\Console\Scheduling\Schedule;
use Override;

final readonly class LaravelAdapter implements Adapter
{
    public function __construct(
        private TaskResolver $taskResolver,
        private SchedulerConfiguration $configuration,
        private SchedulerApplication $application,
    ) {}

    /**
     * @throws Exception
     */
    #[Override]
    public function run(): void
    {
        // Due-time filtering will be added in the next integration step.
        foreach ($this->createSchedule()->events() as $event) {
            $event->run($this->application);
        }
    }

    /**
     * @throws Exception
     */
    public function createSchedule(): Schedule
    {
        $schedule = $this->application->createSchedule();
        foreach ($this->configuration->getConfiguration()->tasks as $definition) {
            $schedule->call(function () use ($definition): void {
                $this->taskResolver->resolve($definition->task)->run();
            })
                ->name($definition->id)
                ->cron($definition->cron)
                ->timezone($definition->timezone);
        }

        return $schedule;
    }
}
