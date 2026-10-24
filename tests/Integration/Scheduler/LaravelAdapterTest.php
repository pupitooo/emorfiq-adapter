<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use App\Scheduler\Contract\SchedulerConfiguration;
use App\Scheduler\Domain\Configuration;
use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Infrastructure\Laravel\LaravelAdapter;
use App\Task\Execution\PingTask;
use App\Task\Infrastructure\Symfony\SymfonyTaskResolver;
use Exception;
use LogicException;
use Override;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tests\Fixtures\Task\LoggingTask;
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
            $this->runsEveryConfiguredDefinition($path);
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
    private function runsEveryConfiguredDefinition(string $path): void
    {
        $configuration = $this->configuration([
            new ScheduleDefinition('ping-first', PingTask::class, '* * * * *'),
            new ScheduleDefinition('fixture', LoggingTask::class, '* * * * *'),
            new ScheduleDefinition('ping-second', PingTask::class, '* * * * *'),
        ]);
        $resolver = new SymfonyTaskResolver(TaskServices::container($path));
        new LaravelAdapter($resolver, $configuration)->run();

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
        new LaravelAdapter($resolver, $this->configuration([]))->run();
    }

    /**
     * @throws Exception
     */
    private function rejectsUnregisteredTask(): void
    {
        $resolver = new SymfonyTaskResolver(new ContainerBuilder());
        $configuration = $this->configuration([new ScheduleDefinition('missing', LoggingTask::class, '* * * * *')]);
        try {
            new LaravelAdapter($resolver, $configuration)->run();
        } catch (LogicException $exception) {
            if ($exception->getMessage() !== 'Task is not registered: ' . LoggingTask::class) {
                throw new LogicException('The adapter must propagate task resolution failures.', previous: $exception);
            }

            return;
        }

        throw new LogicException('The adapter must reject tasks without registered services.');
    }

    /**
     * @param list<ScheduleDefinition> $definitions
     */
    private function configuration(array $definitions): SchedulerConfiguration
    {
        return new class(new Configuration(1, $definitions)) implements SchedulerConfiguration {
            public function __construct(
                private readonly Configuration $configuration,
            ) {}

            /**
             * @throws void
             */
            #[Override]
            public function getConfiguration(): Configuration
            {
                return $this->configuration;
            }
        };
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
