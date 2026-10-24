<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Laravel;

use App\Scheduler\Application\TaskExecutor;
use App\Scheduler\Contract\Adapter;
use App\Scheduler\Contract\SchedulerConfiguration;
use App\Scheduler\Exception\SchedulerRunException;
use App\Scheduler\Exception\TaskExecutionException;
use App\Scheduler\Infrastructure\Laravel\Runtime\SchedulerApplication;
use Exception;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Override;

final readonly class LaravelAdapter implements Adapter
{
    public function __construct(
        private TaskExecutor $executor,
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
        $this->runEvents($events);
    }

    /**
     * @param Collection<int, Event> $events
     * @throws Exception
     */
    private function runEvents(Collection $events): void
    {
        $failures = [];
        foreach ($events as $event) {
            try {
                $this->runEvent($event);
            } catch (TaskExecutionException $failure) {
                $failures[] = $failure;
            }
        }

        if ($failures !== []) {
            throw new SchedulerRunException($failures);
        }
    }

    /**
     * @throws Exception
     */
    private function runEvent(Event $event): void
    {
        // @phpstan-ignore argument.type (Event filters require only the Illuminate container.)
        if (! $event->filtersPass($this->application)) {
            return;
        }

        $event->run($this->application);
    }

    /**
     * @throws Exception
     */
    public function createSchedule(): Schedule
    {
        $schedule = $this->application->createSchedule();
        foreach ($this->configuration->getConfiguration()->tasks as $definition) {
            $schedule->call(function () use ($definition): void {
                $this->executor->execute($definition);
            })
                ->name($definition->id)
                ->cron($definition->cron)
                ->timezone($definition->timezone);
        }

        return $schedule;
    }
}
