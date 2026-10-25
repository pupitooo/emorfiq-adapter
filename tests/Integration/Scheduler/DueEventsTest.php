<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Infrastructure\Laravel\LaravelAdapter;
use App\Scheduler\Infrastructure\Laravel\Runtime\SchedulerApplication;
use App\Task\Execution\PingTask;
use DateTimeImmutable;
use Exception;
use Illuminate\Support\Facades\Date;
use LogicException;
use stdClass;
use Tests\Fixtures\Task\LoggingTask;
use Tests\Support\LogRecords;
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
            $executor = TaskServices::executor(TaskServices::container($path));
            $configuration = SchedulerFixtures::configuration($definitions);
            new LaravelAdapter(new SchedulerApplication(), $executor, $configuration)->run();

            return LogRecords::taskMessages($path);
        } finally {
            Date::setTestNow($original);
            if (! unlink($path)) {
                throw new LogicException('Unable to remove the task execution log.');
            }
        }
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
