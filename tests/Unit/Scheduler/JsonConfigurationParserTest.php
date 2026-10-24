<?php

declare(strict_types=1);

namespace Tests\Unit\Scheduler;

use App\Scheduler\Domain\Configuration;
use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Exception\ConfigurationException;
use App\Scheduler\Infrastructure\Configuration\JsonConfigurationParser;
use App\Task\PingTask;
use LogicException;
use Tests\Support\SchedulerFixtures;

final readonly class JsonConfigurationParserTest
{
    /**
     * @throws ConfigurationException
     */
    public function run(): void
    {
        $configuration = new JsonConfigurationParser()->parse(SchedulerFixtures::read('schedules.json'));

        $this->assertVersionAndTaskCount($configuration);
        $this->everyMinuteDefaultsToUtc($configuration->tasks[0] ?? null);
        $this->weekdayMorningsUsePragueTime($configuration->tasks[1] ?? null);
        $this->quarterHoursCanBeRestrictedToWeekdayHours($configuration->tasks[2] ?? null);
        $this->selectedMonthDaysCanUseNewYorkTime($configuration->tasks[3] ?? null);
        $this->emptyTaskListIsAllowed();
        $this->defaultTimezoneIsIndependentOfPhpSettings();
        $this->preservesSurroundingWhitespace();
    }

    private function assertVersionAndTaskCount(Configuration $configuration): void
    {
        if ($configuration->version !== 1 || count($configuration->tasks) !== 4) {
            throw new LogicException('The example must load version 1 and all four schedules in their defined order.');
        }
    }

    private function everyMinuteDefaultsToUtc(?ScheduleDefinition $actual): void
    {
        $expected = new ScheduleDefinition(
            id: 'ping-every-minute',
            task: PingTask::class,
            cron: '* * * * *',
            timezone: 'UTC',
        );

        $this->assertSchedule($actual, $expected);
    }

    private function weekdayMorningsUsePragueTime(?ScheduleDefinition $actual): void
    {
        $expected = new ScheduleDefinition(
            id: 'ping-weekday-morning',
            task: PingTask::class,
            cron: '0 9 * * 1-5',
            timezone: 'Europe/Prague',
        );

        $this->assertSchedule($actual, $expected);
    }

    private function quarterHoursCanBeRestrictedToWeekdayHours(?ScheduleDefinition $actual): void
    {
        $expected = new ScheduleDefinition(
            id: 'ping-quarter-hour-weekdays',
            task: PingTask::class,
            cron: '*/15 9-17 * * 1-5',
            timezone: 'Europe/Prague',
        );

        $this->assertSchedule($actual, $expected);
    }

    private function selectedMonthDaysCanUseNewYorkTime(?ScheduleDefinition $actual): void
    {
        $expected = new ScheduleDefinition(
            id: 'ping-twice-monthly',
            task: PingTask::class,
            cron: '30 18 1,15 * *',
            timezone: 'America/New_York',
        );

        $this->assertSchedule($actual, $expected);
    }

    /**
     * @throws ConfigurationException
     */
    private function emptyTaskListIsAllowed(): void
    {
        $configuration = new JsonConfigurationParser()->parse(SchedulerFixtures::read('no-tasks.json'));

        if ($configuration->version !== 1 || $configuration->tasks !== []) {
            throw new LogicException('An empty task list must load as a valid configuration with no schedules.');
        }
    }

    /**
     * @throws ConfigurationException
     */
    private function defaultTimezoneIsIndependentOfPhpSettings(): void
    {
        $originalTimezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Auckland');
            $configuration = new JsonConfigurationParser()->parse(SchedulerFixtures::read('schedules.json'));
            $this->everyMinuteDefaultsToUtc($configuration->tasks[0] ?? null);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    /**
     * @throws ConfigurationException
     */
    private function preservesSurroundingWhitespace(): void
    {
        $configuration = new JsonConfigurationParser()->parse(SchedulerFixtures::read('surrounding-whitespace.json'));
        $definition = $configuration->tasks[0] ?? null;
        if (
            $definition === null
            || $definition->id !== ' ping '
            || $definition->task !== ' ' . PingTask::class . ' '
            || $definition->cron !== "\t* * * * *\n"
        ) {
            throw new LogicException('Validation must preserve surrounding whitespace in non-empty values.');
        }
    }

    private function assertSchedule(?ScheduleDefinition $actual, ScheduleDefinition $expected): void
    {
        if ($actual === null) {
            throw new LogicException('Schedule "' . $expected->id . '" must be present in the parsed configuration.');
        }

        if (
            $actual->id !== $expected->id
            || $actual->task !== $expected->task
            || $actual->cron !== $expected->cron
            || $actual->timezone !== $expected->timezone
        ) {
            throw new LogicException('Schedule "' . $expected->id . '" must preserve all documented field values.');
        }
    }
}
