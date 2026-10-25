<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use App\Scheduler\Domain\RunOutcome;
use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Infrastructure\Laravel\Mutex\CoordinatedEventMutex;
use App\Task\Execution\PingTask;
use Exception;
use LogicException;
use Tests\Fixtures\Scheduler\RecordingExecutionMutex;
use Tests\Support\MutexAssertions as A;
use Tests\Support\MutexScenario;

final readonly class LaravelLockingTest
{
    /**
     * @throws Exception
     */
    public function run(): void
    {
        $this->mapsOptionsWithoutClaiming();
        $this->sharesClaimBetweenBothNativeMutexesAndCallback();
        $this->supportsIndependentProtectionFlags();
        $this->skipsLiveOverlapBeforeClaiming();
        $this->skipsAtomicConflictBeforeResolving();
        $this->recordsUnprotectedRunsAndSkipsNotDueTasks();
    }

    /**
     * @throws Exception
     */
    private function mapsOptionsWithoutClaiming(): void
    {
        $definitions = $this->lockingDefinitions();
        $scenario = new MutexScenario($definitions);
        foreach (array_values($scenario->adapter->createSchedule()->events()) as $index => $event) {
            $definition = $definitions[$index] ?? throw new LogicException('Unexpected scheduler event.');
            A::same($definition->withoutOverlapping, $event->withoutOverlapping);
            A::same($definition->onOneServer, $event->onOneServer);
            $expiration = $definition->withoutOverlapping ? $definition->overlapExpiresAfterMinutes : 1440;
            A::same($expiration, $event->expiresAt);
            A::same(true, $event->mutex instanceof CoordinatedEventMutex);
        }
        A::same([], $scenario->mutex->claims);
        A::same([], $scenario->resolver->resolved);
    }

    /**
     * @return list<ScheduleDefinition>
     */
    private function lockingDefinitions(): array
    {
        return [
            new ScheduleDefinition('default', 'missing', '* * * * *'),
            new ScheduleDefinition('overlap', 'missing', '* * * * *', withoutOverlapping: true),
            new ScheduleDefinition('server', 'missing', '* * * * *', onOneServer: true),
            new ScheduleDefinition(
                'both',
                'missing',
                '* * * * *',
                withoutOverlapping: true,
                overlapExpiresAfterMinutes: 30,
                onOneServer: true
            ),
        ];
    }

    /**
     * @throws Exception
     */
    private function sharesClaimBetweenBothNativeMutexesAndCallback(): void
    {
        $definition = new ScheduleDefinition(
            'both',
            PingTask::class,
            '* * * * *',
            withoutOverlapping: true,
            overlapExpiresAfterMinutes: 30,
            onOneServer: true
        );
        $scenario = new MutexScenario([$definition]);
        $scenario->resolver->onResolve(static function (string $taskClass) use ($scenario): void {
            A::same(PingTask::class, $taskClass);
            A::same(1, count($scenario->mutex->issuedClaims));
            A::same([], $scenario->mutex->finishes);
        });
        $scenario->run();
        $this->assertSuccessfulRun($scenario, $definition);
    }

    private function assertSuccessfulRun(MutexScenario $scenario, ScheduleDefinition $definition): void
    {
        A::same(1, count($scenario->mutex->claims));
        A::same($definition, $scenario->mutex->claims[0]['definition'] ?? null);
        A::same([PingTask::class], $scenario->resolver->resolved);
        A::same(1, count($scenario->mutex->finishes));
        A::same($scenario->mutex->issuedClaims[0] ?? null, $scenario->mutex->finishes[0]['claim'] ?? null);
        A::same(RunOutcome::Succeeded, $scenario->mutex->finishes[0]['outcome'] ?? null);
        A::same(null, $scenario->mutex->finishes[0]['error'] ?? null);
    }

    /**
     * @throws Exception
     */
    private function supportsIndependentProtectionFlags(): void
    {
        foreach ([[true, false], [false, true]] as [$overlap, $oneServer]) {
            $scenario = new MutexScenario([
                new ScheduleDefinition(
                    'sync',
                    PingTask::class,
                    '* * * * *',
                    withoutOverlapping: $overlap,
                    onOneServer: $oneServer
                ),
            ]);
            $scenario->run();
            A::same(1, count($scenario->mutex->claims));
            A::same([PingTask::class], $scenario->resolver->resolved);
            A::same(RunOutcome::Succeeded, $scenario->mutex->finishes[0]['outcome'] ?? null);
        }
    }

    /**
     * @throws Exception
     */
    private function skipsLiveOverlapBeforeClaiming(): void
    {
        $scenario = new MutexScenario([
            new ScheduleDefinition('blocked', 'missing', '* * * * *', withoutOverlapping: true, onOneServer: true),
        ], new RecordingExecutionMutex(lockedSchedules: ['blocked']));
        $scenario->run();
        A::same(['blocked'], $scenario->mutex->overlapChecks);
        A::same([], $scenario->mutex->claims);
        A::same([], $scenario->resolver->resolved);
        A::same([], $scenario->mutex->finishes);
    }

    /**
     * @throws Exception
     */
    private function skipsAtomicConflictBeforeResolving(): void
    {
        foreach ([[true, false], [false, true], [true, true]] as [$overlap, $oneServer]) {
            $scenario = new MutexScenario([
                new ScheduleDefinition(
                    'racing',
                    'missing',
                    '* * * * *',
                    withoutOverlapping: $overlap,
                    onOneServer: $oneServer
                ),
            ], new RecordingExecutionMutex(deniedSchedules: ['racing']));
            $scenario->run();
            A::same(1, count($scenario->mutex->claims));
            A::same([], $scenario->resolver->resolved);
            A::same([], $scenario->mutex->finishes);
        }
    }

    /**
     * @throws Exception
     */
    private function recordsUnprotectedRunsAndSkipsNotDueTasks(): void
    {
        $scenario = new MutexScenario([
            new ScheduleDefinition('ordinary', PingTask::class, '* * * * *'),
            new ScheduleDefinition('later', 'missing', '1 8 * * *', withoutOverlapping: true, onOneServer: true),
        ]);
        $scenario->run();
        A::same([PingTask::class], $scenario->resolver->resolved);
        A::same(1, count($scenario->mutex->claims));
        A::same('ordinary', $scenario->mutex->claims[0]['definition']->id ?? null);
        A::same([], $scenario->mutex->overlapChecks);
        A::same(1, count($scenario->mutex->finishes));
        A::same(RunOutcome::Succeeded, $scenario->mutex->finishes[0]['outcome'] ?? null);
    }
}
