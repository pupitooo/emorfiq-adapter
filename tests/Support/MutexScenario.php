<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Scheduler\Application\TaskExecutor;
use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Infrastructure\Laravel\LaravelAdapter;
use App\Task\Infrastructure\Symfony\SymfonyTaskResolver;
use Exception;
use Illuminate\Support\Facades\Date;
use Psr\Log\NullLogger;
use Tests\Fixtures\Scheduler\RecordingExecutionMutex;
use Tests\Fixtures\Task\RecordingTaskResolver;

final readonly class MutexScenario
{
    public RecordingTaskResolver $resolver;

    public LaravelAdapter $adapter;

    /**
     * @param list<ScheduleDefinition> $definitions
     * @throws Exception
     */
    public function __construct(
        array $definitions,
        public RecordingExecutionMutex $mutex = new RecordingExecutionMutex(),
    ) {
        $this->resolver = new RecordingTaskResolver(new SymfonyTaskResolver(TaskServices::container('/dev/null')));
        $this->adapter = SchedulerFixtures::adapter(
            new TaskExecutor($this->resolver, new NullLogger()),
            SchedulerFixtures::configuration($definitions),
            $this->mutex,
        );
    }

    /**
     * @throws Exception
     */
    public function run(string $instant = '2026-10-26 08:00:00 UTC'): void
    {
        $previousTime = Date::getTestNow();
        Date::setTestNow($instant);
        try {
            $this->adapter->run();
        } finally {
            Date::setTestNow($previousTime);
        }
    }
}
