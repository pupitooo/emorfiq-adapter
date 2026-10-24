<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Laravel;

use App\Scheduler\Application\TaskExecutor;
use App\Scheduler\Contract\Adapter;
use App\Scheduler\Contract\SchedulerConfiguration;
use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Exception\TaskExecutionException;
use App\Scheduler\Infrastructure\Laravel\Runtime\SchedulerApplication;
use DateTimeInterface;
use Exception;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
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
        $startedAt = Date::now();
        $schedule = $this->createSchedule();
        /** @var Collection<int, Event> $events */
        // @phpstan-ignore argument.type (The runtime provides the application methods used by Scheduler.)
        $events = $schedule->dueEvents($this->application);
        $this->runEvents($schedule, $events, $startedAt);
    }

    /**
     * @param Collection<int, Event> $events
     * @throws Exception
     */
    private function runEvents(Schedule $schedule, Collection $events, DateTimeInterface $startedAt): void
    {
        foreach ($events as $event) {
            try {
                $this->runEvent($schedule, $event, $startedAt);
            } catch (TaskExecutionException) {
                // TaskExecutor has already reported this failure through the shared logger.
                continue;
            }
        }
    }

    /**
     * @throws Exception
     */
    private function runEvent(Schedule $schedule, Event $event, DateTimeInterface $startedAt): void
    {
        // @phpstan-ignore argument.type (Event filters require only the Illuminate container.)
        if (! $event->filtersPass($this->application)) {
            return;
        }

        if ($event->onOneServer && ! $schedule->serverShouldRun($event, $startedAt)) {
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
            $event = $schedule->call(function () use ($definition): void {
                $this->executor->execute($definition);
            })
                ->name($definition->id)
                ->cron($definition->cron)
                ->timezone($definition->timezone);
            $this->configureConcurrency($event, $definition);
        }

        return $schedule;
    }

    private function configureConcurrency(Event $event, ScheduleDefinition $definition): void
    {
        if ($definition->withoutOverlapping) {
            $event->withoutOverlapping($definition->overlapExpiresAfterMinutes);
        }

        if (! $definition->onOneServer) {
            return;
        }

        $event->onOneServer();
    }
}
