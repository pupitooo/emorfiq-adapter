<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use App\Scheduler\Domain\RunOutcome;
use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Exception\CompletionRejectedException;
use App\Scheduler\Exception\MutexStorageException;
use App\Scheduler\Exception\ReservationUnconfirmedException;
use App\Task\Execution\PingTask;
use Exception;
use Illuminate\Support\Facades\Date;
use LogicException;
use RuntimeException;
use Tests\Fixtures\Scheduler\RecordingExecutionMutex;
use Tests\Fixtures\Task\ErrorTask;
use Tests\Fixtures\Task\FailingTask;
use Tests\Support\MutexAssertions as A;
use Tests\Support\MutexScenario;

final readonly class ExecutionLifecycleTest
{
    /**
     * @throws Exception
     */
    public function run(): void
    {
        $this->recordsTaskFailuresAndContinues();
        $this->neverResolvesTasksAfterClaimFailure();
        $this->stopsAfterRejectedCompletion();
        $this->stopsAfterCompletionFailureWithoutWritingAnotherResult();
        $this->keepsOccurrenceStableAcrossLongTasks();
    }

    /**
     * @throws Exception
     */
    private function recordsTaskFailuresAndContinues(): void
    {
        $scenario = new MutexScenario([
            $this->definition('exception', FailingTask::class),
            $this->definition('error', ErrorTask::class),
            $this->definition('success', PingTask::class),
        ]);
        $scenario->run();
        A::same([FailingTask::class, ErrorTask::class, PingTask::class], $scenario->resolver->resolved);
        A::same(
            [RunOutcome::Failed, RunOutcome::Failed, RunOutcome::Succeeded],
            array_column($scenario->mutex->finishes, 'outcome')
        );
        A::same('Task execution failed.', $scenario->mutex->finishes[0]['error'] ?? null);
        foreach ($scenario->mutex->finishes as $index => $finish) {
            A::same($scenario->mutex->issuedClaims[$index] ?? null, $finish['claim']);
        }
    }

    /**
     * @throws Exception
     */
    private function neverResolvesTasksAfterClaimFailure(): void
    {
        foreach ([new MutexStorageException('Database unavailable.'),
            new ReservationUnconfirmedException('Commit acknowledgement lost.')] as $failure) {
            $scenario = new MutexScenario(
                [$this->definition('sync', PingTask::class)],
                new RecordingExecutionMutex(claimFailure: $failure)
            );
            $this->assertFailure($scenario, $failure);
            A::same(1, count($scenario->mutex->claims));
            A::same([], $scenario->resolver->resolved);
            A::same([], $scenario->mutex->finishes);
        }
    }

    /**
     * @throws Exception
     */
    private function stopsAfterRejectedCompletion(): void
    {
        $scenario = new MutexScenario([
            $this->definition('first', PingTask::class), $this->definition('later', PingTask::class),
        ], new RecordingExecutionMutex(acceptCompletion: false));
        try {
            $scenario->run();
        } catch (CompletionRejectedException) {
            A::same([PingTask::class], $scenario->resolver->resolved);
            A::same(1, count($scenario->mutex->finishes));
            A::same(RunOutcome::Succeeded, $scenario->mutex->finishes[0]['outcome'] ?? null);

            return;
        }
        throw new LogicException('Rejected completion must remain visible.');
    }

    /**
     * @throws Exception
     */
    private function stopsAfterCompletionFailureWithoutWritingAnotherResult(): void
    {
        foreach ([PingTask::class, FailingTask::class] as $task) {
            $failure = new MutexStorageException('Completion unavailable.');
            $scenario = new MutexScenario([
                $this->definition('first', $task), $this->definition('later', PingTask::class),
            ], new RecordingExecutionMutex(completionFailure: $failure));
            $this->assertFailure($scenario, $failure);
            A::same([$task], $scenario->resolver->resolved);
            A::same(1, count($scenario->mutex->finishes));
            A::same(
                $task === PingTask::class ? RunOutcome::Succeeded : RunOutcome::Failed,
                $scenario->mutex->finishes[0]['outcome'] ?? null
            );
        }
    }

    /**
     * @throws Exception
     */
    private function keepsOccurrenceStableAcrossLongTasks(): void
    {
        $scenario = new MutexScenario([
            $this->definition('first', PingTask::class), $this->definition('second', PingTask::class),
        ]);
        $scenario->resolver->onResolve(static function (string $taskClass): void {
            A::same(PingTask::class, $taskClass);
            try {
                Date::setTestNow('2026-10-26 12:00:00 UTC');
            } catch (RuntimeException $failure) {
                throw new LogicException('Unable to advance the fixture clock.', previous: $failure);
            }
        });
        $scenario->run('2026-10-26 09:00:59.123456 Europe/Prague');
        A::same(2, count($scenario->mutex->claims));
        A::same(2, count($scenario->mutex->finishes));
        foreach ($scenario->mutex->claims as $attempt) {
            A::same('2026-10-26 08:00:00.000000 +00:00', $attempt['scheduledFor']->format('Y-m-d H:i:s.u P'));
            A::same('test-worker', $attempt['claimedBy']);
        }
    }

    private function definition(string $id, string $task): ScheduleDefinition
    {
        return new ScheduleDefinition($id, $task, '* * * * *', withoutOverlapping: true, onOneServer: true);
    }

    /**
     * @throws Exception
     */
    private function assertFailure(MutexScenario $scenario, Exception $expected): void
    {
        try {
            $scenario->run();
        } catch (Exception $actual) {
            A::same($expected, $actual);

            return;
        }
        throw new LogicException('Expected infrastructure failure.');
    }
}
