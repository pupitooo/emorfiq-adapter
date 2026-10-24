<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Infrastructure\Laravel\LaravelAdapter;
use App\Scheduler\Infrastructure\Laravel\Runtime\SchedulerApplication;
use App\Task\Execution\PingTask;
use App\Task\Infrastructure\Symfony\SymfonyTaskResolver;
use DateTimeImmutable;
use Exception;
use Illuminate\Support\Facades\Date;
use LogicException;
use RuntimeException;
use stdClass;
use Tests\Fixtures\Task\FailingTask;
use Tests\Fixtures\Task\LoggingTask;
use Tests\Support\SchedulerFixtures;
use Tests\Support\TaskServices;

final readonly class DueEventsTest
{
    /**
     * @throws Exception
     */
    public function run(): void
    {
        $this->runsOnlyDueDefinitionsInOrder();
        $this->respectsDefinitionTimeZones();
        $this->defaultsToUtcRegardlessOfPhpTimezone();
        $this->evaluatesCronAliases();
        $this->skipsUnregisteredTasksWhenNotDue();
        $this->propagatesTaskFailureBeforeResolvingFollowingTasks();
    }

    /**
     * @throws Exception
     */
    private function runsOnlyDueDefinitionsInOrder(): void
    {
        $definitions = [
            new ScheduleDefinition('later-first', LoggingTask::class, '1 8 * * *'),
            new ScheduleDefinition('due-first', PingTask::class, '0 8 * * *'),
            new ScheduleDefinition('due-second', LoggingTask::class, '0 8 * * *'),
            new ScheduleDefinition('later-second', PingTask::class, '1 8 * * *'),
        ];
        $this->assertMessages($this->runSchedulesAt('2026-10-26 08:00:00 UTC', $definitions), ['ping', 'fixture task']);
        $this->assertMessages($this->runSchedulesAt('2026-10-26 08:01:00 UTC', $definitions), ['fixture task', 'ping']);
    }

    /**
     * @throws Exception
     */
    private function respectsDefinitionTimeZones(): void
    {
        $definitions = [
            new ScheduleDefinition('prague', PingTask::class, '0 9 * * *', 'Europe/Prague'),
            new ScheduleDefinition('utc', LoggingTask::class, '0 9 * * *'),
            new ScheduleDefinition('new-york', LoggingTask::class, '0 3 * * *', 'America/New_York'),
        ];
        $this->assertMessages($this->runSchedulesAt('2026-07-15 07:00:00 UTC', $definitions), ['ping', 'fixture task']);
        $this->assertMessages($this->runSchedulesAt('2026-01-15 08:00:00 UTC', $definitions), ['ping', 'fixture task']);
    }

    /**
     * @throws Exception
     */
    private function defaultsToUtcRegardlessOfPhpTimezone(): void
    {
        $original = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Auckland');
            $definitions = [new ScheduleDefinition('utc', PingTask::class, '0 8 * * *')];
            $this->assertMessages($this->runSchedulesAt('2026-10-26 08:00:00 UTC', $definitions), ['ping']);
        } finally {
            date_default_timezone_set($original);
        }
    }

    /**
     * @throws Exception
     */
    private function evaluatesCronAliases(): void
    {
        $definitions = [
            new ScheduleDefinition('daily', PingTask::class, '@daily'),
            new ScheduleDefinition('hourly', LoggingTask::class, '@hourly'),
        ];
        $this->assertMessages($this->runSchedulesAt('2026-10-26 00:00:00 UTC', $definitions), ['ping', 'fixture task']);
        $this->assertMessages($this->runSchedulesAt('2026-10-26 12:00:00 UTC', $definitions), ['fixture task']);
        $this->assertMessages($this->runSchedulesAt('2026-10-26 12:30:00 UTC', $definitions), []);
    }

    /**
     * @throws Exception
     */
    private function skipsUnregisteredTasksWhenNotDue(): void
    {
        $definitions = [new ScheduleDefinition('unregistered', stdClass::class, '1 8 * * *')];
        $this->assertMessages($this->runSchedulesAt('2026-10-26 08:00:00 UTC', $definitions), []);
    }

    /**
     * @throws Exception
     */
    private function propagatesTaskFailureBeforeResolvingFollowingTasks(): void
    {
        $definitions = [
            new ScheduleDefinition('failing', FailingTask::class, '* * * * *'),
            new ScheduleDefinition('following', stdClass::class, '* * * * *'),
        ];
        try {
            $this->runSchedulesAt('2026-10-26 08:00:00 UTC', $definitions);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'Task execution failed.') {
                throw new LogicException('The adapter must propagate the original task failure.', previous: $exception);
            }

            return;
        }

        throw new LogicException('A failed task must stop execution before resolving following tasks.');
    }

    /**
     * @param list<ScheduleDefinition> $definitions
     * @return list<string>
     * @throws Exception
     */
    private function runSchedulesAt(string $instant, array $definitions): array
    {
        $original = Date::getTestNow();
        $path = $this->temporaryFile();
        try {
            Date::setTestNow(new DateTimeImmutable($instant));
            $resolver = new SymfonyTaskResolver(TaskServices::container($path));
            $configuration = SchedulerFixtures::configuration($definitions);
            new LaravelAdapter($resolver, $configuration, new SchedulerApplication())->run();

            return $this->loggedMessages($path);
        } finally {
            Date::setTestNow($original);
            if (! unlink($path)) {
                throw new LogicException('Unable to remove the task execution log.');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function loggedMessages(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new LogicException('Unable to read the task execution log.');
        }

        $messages = [];
        foreach ($lines as $line) {
            $messages[] = $this->messageFromRecord($line);
        }

        return $messages;
    }

    private function messageFromRecord(string $line): string
    {
        $record = json_decode($line, true);
        if (! is_array($record) || ! is_string($record['message'] ?? null)) {
            throw new LogicException('Task execution must produce JSON log records with string messages.');
        }

        return $record['message'];
    }

    /**
     * @param list<string> $actual
     * @param list<string> $expected
     */
    private function assertMessages(array $actual, array $expected): void
    {
        if ($actual !== $expected) {
            throw new LogicException('Executed tasks must match the expected sequence of due definitions.');
        }
    }

    private function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'emorfiq-due-events-');
        if ($path === false) {
            throw new LogicException('Unable to create the task execution log.');
        }

        return $path;
    }
}
