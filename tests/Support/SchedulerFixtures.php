<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Scheduler\Application\TaskExecutor;
use App\Scheduler\Contract\ExecutionMutex;
use App\Scheduler\Contract\SchedulerConfiguration;
use App\Scheduler\Domain\Configuration;
use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Infrastructure\Laravel\LaravelAdapter;
use App\Scheduler\Infrastructure\Laravel\Mutex\CoordinatedEventMutex;
use App\Scheduler\Infrastructure\Laravel\Mutex\CoordinatedSchedulingMutex;
use App\Scheduler\Infrastructure\Laravel\Mutex\EventExecutions;
use App\Scheduler\Infrastructure\Laravel\Runtime\SchedulerApplication;
use LogicException;
use Override;
use Tests\Fixtures\Scheduler\RecordingExecutionMutex;
use Tests\Fixtures\Scheduler\UnusedEventMutex;
use Tests\Fixtures\Scheduler\UnusedSchedulingMutex;

final readonly class SchedulerFixtures
{
    public static function application(string $environment = 'production'): SchedulerApplication
    {
        return new SchedulerApplication(new UnusedEventMutex(), new UnusedSchedulingMutex(), $environment);
    }

    public static function adapter(
        TaskExecutor $executor,
        SchedulerConfiguration $configuration,
        ?ExecutionMutex $mutex = null,
    ): LaravelAdapter {
        $executions = new EventExecutions();
        $scheduler = new SchedulerApplication(
            new CoordinatedEventMutex($executions),
            new CoordinatedSchedulingMutex($executions),
        );

        return new LaravelAdapter(
            $scheduler,
            $executor,
            $configuration,
            $mutex ?? new RecordingExecutionMutex(),
            'test-worker',
            $executions,
        );
    }

    /**
     * @param list<ScheduleDefinition> $definitions
     */
    public static function configuration(array $definitions): SchedulerConfiguration
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

    public static function read(string $fixture): string
    {
        $json = file_get_contents(__DIR__ . '/../Fixtures/Scheduler/' . $fixture);
        if ($json === false) {
            throw new LogicException('Unable to read scheduler test fixture: ' . $fixture);
        }

        return $json;
    }
}
