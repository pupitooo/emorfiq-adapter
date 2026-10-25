<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Laravel;

use App\Scheduler\Application\ScheduledExecution;
use App\Scheduler\Application\TaskExecutor;
use App\Scheduler\Contract\Adapter;
use App\Scheduler\Contract\ExecutionMutex;
use App\Scheduler\Contract\SchedulerConfiguration;
use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Exception\TaskExecutionException;
use App\Scheduler\Infrastructure\Laravel\Mutex\EventExecutions;
use App\Scheduler\Infrastructure\Laravel\Runtime\SchedulerApplication as LaravelScheduler;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Override;

final readonly class LaravelAdapter implements Adapter
{
    public function __construct(
        private LaravelScheduler $scheduler,
        private TaskExecutor $executor,
        private SchedulerConfiguration $configuration,
        private ExecutionMutex $mutex,
        private string $processId,
        private EventExecutions $executions,
    ) {}

    /**
     * @throws Exception
     */
    #[Override]
    public function run(): void
    {
        $startedAt = Date::now();
        $schedule = $this->createSchedule($this->occurrence($startedAt));
        /** @var Collection<int, Event> $events */
        // @phpstan-ignore argument.type (The runtime provides the application methods used by Scheduler.)
        $events = $schedule->dueEvents($this->scheduler);
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
        if (! $event->filtersPass($this->scheduler)) {
            return;
        }

        if ($event->onOneServer && ! $schedule->serverShouldRun($event, $startedAt)) {
            return;
        }

        $event->run($this->scheduler);
    }

    /**
     * @throws Exception
     */
    public function createSchedule(?DateTimeImmutable $scheduledFor = null): Schedule
    {
        $scheduledFor = $this->occurrence($scheduledFor ?? Date::now());
        $schedule = $this->scheduler->createSchedule();
        foreach ($this->configuration->getConfiguration()->tasks as $definition) {
            $this->registerDefinition($schedule, $definition, $scheduledFor);
        }

        return $schedule;
    }

    private function registerDefinition(
        Schedule $schedule,
        ScheduleDefinition $definition,
        DateTimeImmutable $scheduledFor,
    ): void {
        $execution = new ScheduledExecution(
            definition: $definition,
            scheduledFor: $scheduledFor,
            processId: $this->processId,
            mutex: $this->mutex,
            executor: $this->executor,
        );

        $event = $schedule->call($execution->execute(...))
            ->name($definition->id)
            ->cron($definition->cron)
            ->timezone($definition->timezone);

        $this->configureConcurrency($event, $definition);

        $this->executions->register($event, $execution);
    }

    private function configureConcurrency(Event $event, ScheduleDefinition $definition): void
    {
        if ($definition->withoutOverlapping) {
            $event->withoutOverlapping($definition->overlapExpiresAfterMinutes, false);
        }

        if (! $definition->onOneServer) {
            return;
        }

        $event->onOneServer();
    }

    private function occurrence(DateTimeInterface $instant): DateTimeImmutable
    {
        $utc = DateTimeImmutable::createFromInterface($instant)
            ->setTimezone(new DateTimeZone('UTC'));

        return $utc->setTime(
            hour: (int) $utc->format('H'),
            minute: (int) $utc->format('i')
        );
    }
}
