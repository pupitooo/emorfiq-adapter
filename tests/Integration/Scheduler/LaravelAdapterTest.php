<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use App\Scheduler\Application\TaskExecutor;
use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Exception\SchedulerRunException;
use App\Scheduler\Infrastructure\Laravel\LaravelAdapter;
use App\Scheduler\Infrastructure\Laravel\Runtime\SchedulerApplication;
use App\Task\Execution\PingTask;
use App\Task\Infrastructure\Symfony\SymfonyTaskResolver;
use Exception;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use LogicException;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tests\Fixtures\Task\LoggingTask;
use Tests\Support\LogRecords;
use Tests\Support\SchedulerFixtures;
use Tests\Support\TaskServices;

final readonly class LaravelAdapterTest
{
    /**
     * @throws Exception
     */
    public function run(): void
    {
        $path = $this->temporaryFile();
        try {
            $this->mapsDefinitionsWithoutResolvingTasks();
            $this->runsEveryMinuteDefinitions($path);
            $this->runsNothingForEmptyConfiguration();
            $this->rejectsUnregisteredTask();
        } finally {
            if (! unlink($path)) {
                throw new LogicException('Unable to remove task execution log.');
            }
        }
    }

    /**
     * @throws Exception
     */
    private function mapsDefinitionsWithoutResolvingTasks(): void
    {
        $configuration = SchedulerFixtures::configuration([
            new ScheduleDefinition('weekday', LoggingTask::class, '0 9 * * 1-5', 'Europe/Prague'),
            new ScheduleDefinition('daily', PingTask::class, '@daily'),
        ]);
        $resolver = new SymfonyTaskResolver(new ContainerBuilder());
        $executor = new TaskExecutor($resolver, new NullLogger());
        $adapter = new LaravelAdapter($executor, $configuration, new SchedulerApplication());
        $this->assertMappedEvents($adapter->createSchedule());
        $this->assertMappedEvents($adapter->createSchedule());
    }

    private function assertMappedEvents(Schedule $schedule): void
    {
        $events = $schedule->events();
        $weekday = $events[0] ?? null;
        $daily = $events[1] ?? null;
        if (count($events) !== 2) {
            throw new LogicException('Each schedule build must contain one event per definition in order.');
        }

        if (
            ! $weekday instanceof CallbackEvent || $weekday->description !== 'weekday'
            || $weekday->expression !== '0 9 * * 1-5' || $weekday->timezone !== 'Europe/Prague'
        ) {
            throw new LogicException('Events must retain identifiers, cron expressions, and time zones.');
        }

        if (
            ! $daily instanceof CallbackEvent || $daily->description !== 'daily'
            || $daily->expression !== '@daily' || $daily->timezone !== 'UTC'
        ) {
            throw new LogicException('Events must support cron aliases and the default UTC time zone.');
        }
    }

    /**
     * @throws Exception
     */
    private function runsEveryMinuteDefinitions(string $path): void
    {
        $configuration = SchedulerFixtures::configuration([
            new ScheduleDefinition('ping-first', PingTask::class, '* * * * *'),
            new ScheduleDefinition('fixture', LoggingTask::class, '* * * * *'),
            new ScheduleDefinition('ping-second', PingTask::class, '* * * * *'),
        ]);
        $executor = TaskServices::executor(TaskServices::container($path));
        new LaravelAdapter($executor, $configuration, new SchedulerApplication())->run();

        $this->assertLog($path);
    }

    private function assertLog(string $path): void
    {
        if (LogRecords::taskMessages($path) !== ['ping', 'fixture task', 'ping']) {
            throw new LogicException('Each definition must produce its task message in order.');
        }
    }

    /**
     * @throws Exception
     */
    private function runsNothingForEmptyConfiguration(): void
    {
        $resolver = new SymfonyTaskResolver(new ContainerBuilder());
        $executor = new TaskExecutor($resolver, new NullLogger());
        new LaravelAdapter($executor, SchedulerFixtures::configuration([]), new SchedulerApplication())->run();
    }

    /**
     * @throws Exception
     */
    private function rejectsUnregisteredTask(): void
    {
        $resolver = new SymfonyTaskResolver(new ContainerBuilder());
        $configuration = SchedulerFixtures::configuration([
            new ScheduleDefinition('missing', LoggingTask::class, '* * * * *'),
        ]);
        try {
            $executor = new TaskExecutor($resolver, new NullLogger());
            new LaravelAdapter($executor, $configuration, new SchedulerApplication())->run();
        } catch (SchedulerRunException $exception) {
            if (
                $exception->failures[0]->getPrevious()?->getMessage()
                !== 'Task is not registered: ' . LoggingTask::class
            ) {
                throw new LogicException('The adapter must propagate task resolution failures.', previous: $exception);
            }

            return;
        }

        throw new LogicException('The adapter must reject tasks without registered services.');
    }

    private function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'emorfiq-tasks-');
        if ($path === false) {
            throw new LogicException('Unable to create task execution log.');
        }

        return $path;
    }
}
