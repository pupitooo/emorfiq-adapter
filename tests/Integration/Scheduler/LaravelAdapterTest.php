<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Infrastructure\Laravel\LaravelAdapter;
use App\Scheduler\Infrastructure\Laravel\Runtime\SchedulerApplication;
use App\Task\Execution\PingTask;
use App\Task\Infrastructure\Symfony\SymfonyTaskResolver;
use Exception;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use LogicException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tests\Fixtures\Task\LoggingTask;
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
        $adapter = new LaravelAdapter($resolver, $configuration, new SchedulerApplication());
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
        $resolver = new SymfonyTaskResolver(TaskServices::container($path));
        new LaravelAdapter($resolver, $configuration, new SchedulerApplication())->run();

        $this->assertLog($path);
    }

    private function assertLog(string $path): void
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false || count($lines) !== 3) {
            throw new LogicException('Each configured definition must produce one JSON log record.');
        }

        foreach ($lines as $index => $line) {
            $this->assertLogRecord($line, $index === 1 ? 'fixture task' : 'ping');
        }
    }

    private function assertLogRecord(string $line, string $message): void
    {
        $record = json_decode($line, true);
        if (
            ! is_array($record) || ($record['message'] ?? null) !== $message
            || ($record['level'] ?? null) !== 200
        ) {
            throw new LogicException('The configured logger must write task messages as INFO JSON records in order.');
        }
    }

    /**
     * @throws Exception
     */
    private function runsNothingForEmptyConfiguration(): void
    {
        $resolver = new SymfonyTaskResolver(new ContainerBuilder());
        new LaravelAdapter($resolver, SchedulerFixtures::configuration([]), new SchedulerApplication())->run();
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
            new LaravelAdapter($resolver, $configuration, new SchedulerApplication())->run();
        } catch (LogicException $exception) {
            if ($exception->getMessage() !== 'Task is not registered: ' . LoggingTask::class) {
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
