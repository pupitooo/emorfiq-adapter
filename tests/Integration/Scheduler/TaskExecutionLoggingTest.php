<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Exception\SchedulerRunException;
use App\Scheduler\Exception\TaskExecutionException;
use App\Scheduler\Infrastructure\Laravel\LaravelAdapter;
use App\Scheduler\Infrastructure\Laravel\Runtime\SchedulerApplication;
use App\Task\Execution\PingTask;
use DateTimeImmutable;
use Error;
use Exception;
use Illuminate\Support\Facades\Date;
use LogicException;
use Tests\Fixtures\Task\ErrorTask;
use Tests\Fixtures\Task\FailingTask;
use Tests\Fixtures\Task\LoggingTask;
use Tests\Support\LogRecords;
use Tests\Support\SchedulerFixtures;
use Tests\Support\TaskServices;
use UnexpectedValueException;

/**
 * @phpstan-import-type Record from LogRecords
 */
final readonly class TaskExecutionLoggingTest
{
    /**
     * @throws Exception
     */
    public function run(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'emorfiq-lifecycle-');
        if ($path === false) {
            throw new LogicException('Unable to create lifecycle log.');
        }

        try {
            $this->logsSuccessfulExecution($path);
            file_put_contents($path, '');
            $this->continuesAfterFailures($path);
            file_put_contents($path, '');
            $this->logsNothingWhenNotDue($path);
            $this->propagatesLoggerFailure($path);
        } finally {
            unlink($path);
        }
    }

    /**
     * @throws Exception
     */
    private function logsNothingWhenNotDue(string $path): void
    {
        $original = Date::getTestNow();
        try {
            Date::setTestNow(new DateTimeImmutable('2026-10-26 08:00:00 UTC'));
            $this->adapter($path, [new ScheduleDefinition('later', self::class, '1 8 * * *')])->run();
            if (LogRecords::read($path) !== []) {
                throw new LogicException('Skipped tasks must not emit lifecycle logs or resolve services.');
            }
        } finally {
            Date::setTestNow($original);
        }
    }

    /**
     * @throws Exception
     */
    private function propagatesLoggerFailure(string $path): void
    {
        $adapter = $this->adapter($path . '/invalid.log', [
            new ScheduleDefinition('ping', PingTask::class, '* * * * *'),
        ]);
        try {
            $adapter->run();
        } catch (UnexpectedValueException) {
            return;
        }

        throw new LogicException('Lifecycle logger failures must abort without becoming task failure aggregates.');
    }

    /**
     * @throws Exception
     */
    private function logsSuccessfulExecution(string $path): void
    {
        $this->adapter($path, [new ScheduleDefinition('ping-first', PingTask::class, '* * * * *')])->run();
        $records = LogRecords::read($path);
        if (count($records) !== 3 || $records[1]['message'] !== 'ping') {
            throw new LogicException('Lifecycle records must surround task output in the shared logger.');
        }

        $this->assertLifecycle($records[0], 'started', 'ping-first', PingTask::class, 200);
        $this->assertLifecycle($records[2], 'completed', 'ping-first', PingTask::class, 200);
    }

    /**
     * @throws Exception
     */
    private function continuesAfterFailures(string $path): void
    {
        $definitions = [
            new ScheduleDefinition('throws-exception', FailingTask::class, '* * * * *'),
            new ScheduleDefinition('missing', self::class, '* * * * *'),
            new ScheduleDefinition('throws-error', ErrorTask::class, '* * * * *'),
            new ScheduleDefinition('following', LoggingTask::class, '* * * * *'),
        ];
        try {
            $this->adapter($path, $definitions)->run();
        } catch (SchedulerRunException $exception) {
            $this->assertFailures($exception);
            $this->assertFailureRecords($path);

            return;
        }

        throw new LogicException('Task failures must be reported after all due tasks have run.');
    }

    private function assertFailures(SchedulerRunException $exception): void
    {
        $failures = $exception->failures;
        if (count($failures) !== 3 || $exception->getPrevious() !== $failures[0]) {
            throw new LogicException('Run failure must retain every individual failure in order.');
        }

        if ($exception->getMessage() !== '3 scheduled task(s) failed: throws-exception, missing, throws-error.') {
            throw new LogicException('Run summary must identify every failed schedule in order.');
        }

        $this->assertOriginalCauses($failures);
    }

    /**
     * @param array{TaskExecutionException, TaskExecutionException, TaskExecutionException} $failures
     */
    private function assertOriginalCauses(array $failures): void
    {
        if (
            $failures[0]->getPrevious()?->getMessage() !== 'Task execution failed.'
            || ! $failures[1]->getPrevious() instanceof LogicException
            || ! $failures[2]->getPrevious() instanceof Error
        ) {
            throw new LogicException('Run failure must retain task identifiers and original causes.');
        }
    }

    private function assertFailureRecords(string $path): void
    {
        $records = LogRecords::read($path);
        if (count($records) !== 9 || LogRecords::taskMessages($path) !== ['fixture task']) {
            throw new LogicException('Every failed task must log its failure and allow subsequent execution.');
        }

        $classes = [FailingTask::class, self::class, ErrorTask::class];
        $ids = ['throws-exception', 'missing', 'throws-error'];
        foreach ($classes as $index => $class) {
            $this->assertLifecycle($records[$index * 2], 'started', $ids[$index], $class, 200);
            $this->assertLifecycle($records[$index * 2 + 1], 'failed', $ids[$index], $class, 400);
        }

        $this->assertLifecycle($records[6], 'started', 'following', LoggingTask::class, 200);
        $this->assertLifecycle($records[8], 'completed', 'following', LoggingTask::class, 200);
        $this->assertExceptionDetails($records[1]);
    }

    /**
     * @param Record $record
     */
    private function assertExceptionDetails(array $record): void
    {
        $exception = $record['context']['exception'] ?? null;
        if (
            ! is_array($exception) || ($exception['class'] ?? null) !== \RuntimeException::class
            || ($exception['message'] ?? null) !== 'Task execution failed.'
            || ! is_array($exception['trace'] ?? null) || $exception['trace'] === []
        ) {
            throw new LogicException('Failure logs must retain exception class, message, and stack trace.');
        }
    }

    /**
     * @param Record $record
     */
    private function assertLifecycle(array $record, string $event, string $id, string $task, int $level): void
    {
        $context = $record['context'];
        if (
            ($context['event'] ?? null) !== 'scheduler.task.' . $event || $record['level'] !== $level
            || ($context['schedule_id'] ?? null) !== $id || ($context['task'] ?? null) !== $task
        ) {
            throw new LogicException('Lifecycle logs must identify schedule, task, event, and severity.');
        }

        if ($event === 'started') {
            return;
        }

        $this->assertDuration($record);
    }

    /**
     * @param Record $record
     */
    private function assertDuration(array $record): void
    {
        $duration = $record['context']['duration_ms'] ?? null;
        if ((! is_float($duration) && ! is_int($duration)) || $duration < 0) {
            throw new LogicException('Finished tasks must log nonnegative elapsed milliseconds.');
        }
    }

    /**
     * @param list<ScheduleDefinition> $definitions
     * @throws Exception
     */
    private function adapter(string $path, array $definitions): LaravelAdapter
    {
        return new LaravelAdapter(
            TaskServices::executor(TaskServices::container($path)),
            SchedulerFixtures::configuration($definitions),
            new SchedulerApplication(),
        );
    }
}
