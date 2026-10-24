<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Laravel;

use App\Scheduler\Contract\Adapter;
use App\Scheduler\Contract\SchedulerConfiguration;
use App\Scheduler\Infrastructure\Laravel\Runtime\SchedulerApplication;
use App\Task\Contract\TaskResolver;
use Exception;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
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
        $schedule = $this->createSchedule();
        /** @var Collection<int, Event> $events */
        // @phpstan-ignore argument.type (The runtime provides the application methods used by Scheduler.)
        $events = $schedule->dueEvents($this->application);
        foreach ($events as $event) {
            // @phpstan-ignore argument.type (Event filters require only the Illuminate container.)
            if (! $event->filtersPass($this->application)) {
                continue;
            }

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
