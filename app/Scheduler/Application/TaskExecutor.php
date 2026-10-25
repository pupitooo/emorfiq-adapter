<?php

declare(strict_types=1);

namespace App\Scheduler\Application;

use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Exception\TaskExecutionException;
use App\Task\Contract\TaskResolver;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class TaskExecutor
{
    private const int NANOSECONDS_PER_MILLISECOND = 1_000_000;

    public function __construct(
        private TaskResolver $resolver,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws TaskExecutionException
     */
    public function execute(ScheduleDefinition $definition): void
    {
        $started = hrtime(true);
        $this->logger->info('Scheduled task started.', $this->context($definition) + [
            'event' => 'scheduler.task.started',
        ]);
        try {
            $this->resolver->resolve($definition->task)->run();
        } catch (Throwable $exception) {
            $failure = new TaskExecutionException(definition: $definition, previous: $exception);
            $this->logFailure($failure, $started);

            throw $failure;
        }

        $this->logCompletion($definition, $started);
    }

    private function logCompletion(ScheduleDefinition $definition, int|float $started): void
    {
        $this->logger->info('Scheduled task completed.', $this->context($definition) + [
            'event' => 'scheduler.task.completed',
            'duration_ms' => $this->elapsedMilliseconds($started),
        ]);
    }

    private function logFailure(TaskExecutionException $failure, int|float $started): void
    {
        $this->logger->error('Scheduled task failed.', $this->context($failure->definition) + [
            'event' => 'scheduler.task.failed',
            'duration_ms' => $this->elapsedMilliseconds($started),
            'exception' => $failure->getPrevious(),
        ]);
    }

    /**
     * @return array{schedule_id: string, task: string}
     */
    private function context(ScheduleDefinition $definition): array
    {
        return [
            'schedule_id' => $definition->id,
            'task' => $definition->task,
        ];
    }

    private function elapsedMilliseconds(int|float $started): float
    {
        return (hrtime(true) - $started) / self::NANOSECONDS_PER_MILLISECOND;
    }
}
