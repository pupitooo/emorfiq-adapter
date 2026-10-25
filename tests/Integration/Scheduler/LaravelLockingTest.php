<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use App\Scheduler\Application\TaskExecutor;
use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Infrastructure\Configuration\JsonConfigurationParser;
use App\Scheduler\Infrastructure\Laravel\LaravelAdapter;
use App\Scheduler\Infrastructure\Laravel\Runtime\SchedulerApplication;
use App\Task\Execution\PingTask;
use App\Task\Infrastructure\Symfony\SymfonyTaskResolver;
use Exception;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Support\Facades\Date;
use LogicException;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tests\Fixtures\Scheduler\RecordingEventMutex;
use Tests\Fixtures\Scheduler\RecordingSchedulingMutex;
use Tests\Fixtures\Task\FailingTask;
use Tests\Support\LogRecords;
use Tests\Support\SchedulerFixtures;
use Tests\Support\TaskServices;

final readonly class LaravelLockingTest
{
    /**
     * @throws Exception
     */
    public function run(): void
    {
        $this->mapsJsonLockOptions();
        $this->rejectsUnimplementedLocks();
        $path = tempnam(sys_get_temp_dir(), 'emorfiq-locking-');
        if ($path === false) {
            throw new LogicException('Unable to create locking test log.');
        }

        try {
            $this->coordinatesSingleServerExecution($path);
            $this->guardsOverlappingExecution($path);
        } finally {
            unlink($path);
        }
    }

    /**
     * @throws Exception
     */
    private function mapsJsonLockOptions(): void
    {
        $json = '{"version":1,"tasks":[
            {"id":"default","task":"missing","cron":"* * * * *"},
            {"id":"overlap","task":"missing","cron":"* * * * *","withoutOverlapping":true},
            {"id":"server","task":"missing","cron":"* * * * *","onOneServer":true},
            {"id":"both","task":"missing","cron":"* * * * *","withoutOverlapping":true,
                "overlapExpiresAfterMinutes":30,"onOneServer":true}
        ]}';
        $definitions = new JsonConfigurationParser()->parse($json)->tasks;
        $executor = new TaskExecutor(new SymfonyTaskResolver(new ContainerBuilder()), new NullLogger());
        $adapter = new LaravelAdapter(
            new SchedulerApplication(),
            $executor,
            SchedulerFixtures::configuration($definitions),
        );
        foreach (array_values($adapter->createSchedule()->events()) as $index => $event) {
            $this->assertMappedOptions($index, $event->withoutOverlapping, $event->expiresAt, $event->onOneServer);
        }
    }

    private function assertMappedOptions(int $index, bool $overlap, int $expiry, bool $oneServer): void
    {
        $expected = [
            [false, 1440, false],
            [true, 60, false],
            [false, 1440, true],
            [true, 30, true],
        ];
        if ([$overlap, $expiry, $oneServer] !== ($expected[$index] ?? null)) {
            throw new LogicException('Each event must map JSON lock flags and expiration independently.');
        }
    }

    /**
     * @throws Exception
     */
    private function rejectsUnimplementedLocks(): void
    {
        foreach ([
            new ScheduleDefinition('overlap', 'missing', '* * * * *', withoutOverlapping: true),
            new ScheduleDefinition('server', 'missing', '* * * * *', onOneServer: true),
        ] as $definition) {
            $executor = new TaskExecutor(new SymfonyTaskResolver(new ContainerBuilder()), new NullLogger());
            $adapter = new LaravelAdapter(
                new SchedulerApplication(),
                $executor,
                SchedulerFixtures::configuration([$definition]),
            );
            $this->assertLockFailure($adapter);
        }
    }

    /**
     * @throws Exception
     */
    private function assertLockFailure(LaravelAdapter $adapter): void
    {
        try {
            $adapter->run();
        } catch (LogicException $exception) {
            if ($exception->getMessage() !== 'Not Implemented') {
                throw new LogicException('Lock failures must propagate before resolving tasks.', previous: $exception);
            }

            return;
        }

        throw new LogicException('Enabled locking must not silently run without an implementation.');
    }

    /**
     * @throws Exception
     */
    private function coordinatesSingleServerExecution(string $path): void
    {
        $definitions = [
            new ScheduleDefinition('blocked', 'missing', '* * * * *', onOneServer: true),
            new ScheduleDefinition('allowed', PingTask::class, '* * * * *', onOneServer: true),
            new ScheduleDefinition('ordinary', PingTask::class, '* * * * *'),
            new ScheduleDefinition('later', 'missing', '1 8 * * *', onOneServer: true),
        ];
        $application = new SchedulerApplication();
        $mutex = new RecordingSchedulingMutex();
        $application->instance(SchedulingMutex::class, $mutex);
        $this->runAtFixedTime($path, $definitions, $application);
        $this->assertSingleServerExecution($mutex, $path);
    }

    private function assertSingleServerExecution(RecordingSchedulingMutex $mutex, string $path): void
    {
        if ($mutex->attempts !== [
            [
                'name' => 'blocked',
                'time' => '2026-10-26 08:00:00',
            ],
            [
                'name' => 'allowed',
                'time' => '2026-10-26 08:00:00',
            ],
        ] || LogRecords::taskMessages($path) !== ['ping', 'ping']) {
            throw new LogicException('Only due single-server events must acquire a slot; denied events must skip.');
        }
    }

    /**
     * @throws Exception
     */
    private function guardsOverlappingExecution(string $path): void
    {
        $definitions = [
            new ScheduleDefinition('overlapping', 'missing', '* * * * *', withoutOverlapping: true, onOneServer: true),
            new ScheduleDefinition('racing', 'missing', '* * * * *', withoutOverlapping: true),
            new ScheduleDefinition('success', PingTask::class, '* * * * *', withoutOverlapping: true),
            new ScheduleDefinition('failure', FailingTask::class, '* * * * *', withoutOverlapping: true),
            new ScheduleDefinition('ordinary', PingTask::class, '* * * * *'),
        ];
        $application = new SchedulerApplication();
        $mutex = new RecordingEventMutex();
        $application->instance(EventMutex::class, $mutex);
        $this->runAtFixedTime($path, $definitions, $application);
        if (
            $mutex->created !== ['racing', 'success', 'failure'] || $mutex->released !== ['success', 'failure']
            || LogRecords::taskMessages($path) !== ['ping', 'ping']
        ) {
            throw new LogicException('Overlap locks must guard execution and release after success and task failure.');
        }
    }

    /**
     * @param list<ScheduleDefinition> $definitions
     * @throws Exception
     */
    private function runAtFixedTime(string $path, array $definitions, SchedulerApplication $application): void
    {
        file_put_contents($path, '');
        $previousTime = Date::getTestNow();
        Date::setTestNow('2026-10-26 08:00:00 UTC');
        try {
            $executor = TaskServices::executor(TaskServices::container($path));
            new LaravelAdapter($application, $executor, SchedulerFixtures::configuration($definitions))->run();
        } finally {
            Date::setTestNow($previousTime);
        }
    }
}
