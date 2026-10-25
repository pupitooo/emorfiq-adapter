<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use App\Scheduler\Infrastructure\Laravel\Mutex\CoordinatedEventMutex;
use App\Scheduler\Infrastructure\Laravel\Mutex\CoordinatedSchedulingMutex;
use App\Scheduler\Infrastructure\Laravel\Runtime\SchedulerApplication;
use App\Task\Infrastructure\Symfony\SymfonyTaskResolver;
use Exception;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use LogicException;
use Psr\Log\LoggerInterface;
use Tests\Fixtures\Scheduler\UnusedEventMutex;
use Tests\Fixtures\Task\LoggingTask;
use Tests\Support\SchedulerFixtures;
use Tests\Support\TaskServices;
use Throwable;

final readonly class SchedulerApplicationTest
{
    /**
     * @throws Throwable
     */
    public function run(): void
    {
        $this->compiledServicesProvideSchedulerContext();
        $this->callbacksUseCurrentContainer();
        $this->callbacksResolveTasksThroughSymfony();
        $this->containersKeepSeparateBindings();
        $this->creationPreservesGlobalContainer();
        $this->scheduleCreationKeepsGlobalContainer();
        $this->scheduleFailureKeepsGlobalContainer();
        $this->scheduleBuildsKeepSeparateEvents();
    }

    /**
     * @throws Exception
     */
    private function compiledServicesProvideSchedulerContext(): void
    {
        $services = TaskServices::container('/dev/null');
        $application = $services->get(SchedulerApplication::class);
        if (! $application instanceof SchedulerApplication) {
            throw new LogicException('Compiled services must provide the scheduler application.');
        }

        if ($application->environment() !== 'production' || $application->isDownForMaintenance()) {
            throw new LogicException('The scheduler must default to production without maintenance mode.');
        }
        if (
            ! $application->make(EventMutex::class) instanceof CoordinatedEventMutex
            || ! $application->make(SchedulingMutex::class) instanceof CoordinatedSchedulingMutex
        ) {
            throw new LogicException('Compiled services must inject both coordinated Laravel mutexes.');
        }
    }

    /**
     * @throws Throwable
     */
    private function callbacksUseCurrentContainer(): void
    {
        $application = SchedulerFixtures::application('testing');
        $event = new CallbackEvent(
            new UnusedEventMutex(),
            static fn(ContainerContract $container): ContainerContract => $container,
        );

        if ($event->run($application) !== $application || $application->environment() !== 'testing') {
            throw new LogicException('Callbacks must receive the current application container and environment.');
        }
    }

    /**
     * @throws Throwable
     */
    private function callbacksResolveTasksThroughSymfony(): void
    {
        $services = TaskServices::container('/dev/null');
        $resolver = new SymfonyTaskResolver($services);
        $event = new CallbackEvent(new UnusedEventMutex(), static fn() => $resolver->resolve(LoggingTask::class));
        $task = $event->run(SchedulerFixtures::application());
        if (! $task instanceof LoggingTask || $task->logger !== $services->get(LoggerInterface::class)) {
            throw new LogicException('Scheduled callbacks must retain Symfony task construction and dependencies.');
        }
    }

    /**
     * @throws Exception
     */
    private function containersKeepSeparateBindings(): void
    {
        $first = SchedulerFixtures::application();
        $second = SchedulerFixtures::application();
        if ($first->make(Container::class) !== $first || $second->make(Container::class) !== $second) {
            throw new LogicException('Each scheduler application must resolve its own container.');
        }

        if ($first->make(SchedulerApplication::class) !== $first) {
            throw new LogicException('The scheduler application must resolve itself.');
        }

        $first->instance('first-only', $this);
        if ($second->bound('first-only')) {
            throw new LogicException('Independent scheduler applications must not share service bindings.');
        }
    }

    private function creationPreservesGlobalContainer(): void
    {
        $original = Container::getInstance();
        SchedulerFixtures::application();
        if (Container::getInstance() !== $original) {
            throw new LogicException('Creating the scheduler context must not replace the global container.');
        }
    }

    /**
     * @throws Exception
     */
    private function scheduleCreationKeepsGlobalContainer(): void
    {
        $original = Container::getInstance();
        $application = SchedulerFixtures::application();
        $mutex = new UnusedEventMutex();
        $application->bind(EventMutex::class, static function () use ($original, $mutex): EventMutex {
            if (Container::getInstance() !== $original) {
                throw new LogicException('Resolving schedule dependencies must preserve the global container.');
            }

            return $mutex;
        });
        $schedule = $application->createSchedule();
        if ($schedule->events() !== [] || Container::getInstance() !== $original) {
            throw new LogicException('Schedule creation must return an empty plan and preserve the global container.');
        }

        if ($schedule->call(static fn() => null)->mutex !== $mutex) {
            throw new LogicException('Schedule events must use the explicitly supplied event mutex.');
        }
    }

    /**
     * @throws Exception
     */
    private function scheduleFailureKeepsGlobalContainer(): void
    {
        $original = Container::getInstance();
        $application = SchedulerFixtures::application();
        $application->bind(SchedulingMutex::class, static function () use ($original): never {
            if (Container::getInstance() !== $original) {
                throw new LogicException('Failing dependency resolution must preserve the global container.');
            }

            throw new LogicException('Mutex resolution failed.');
        });
        try {
            $application->createSchedule();
        } catch (LogicException $exception) {
            if ($exception->getMessage() !== 'Mutex resolution failed.' || Container::getInstance() !== $original) {
                throw new LogicException('Schedule initialization must preserve the global container on failure.');
            }

            return;
        }

        throw new LogicException('Schedule initialization must propagate mutex resolution failures.');
    }

    /**
     * @throws Exception
     */
    private function scheduleBuildsKeepSeparateEvents(): void
    {
        $application = SchedulerFixtures::application();
        $first = $application->createSchedule();
        $first->call(static fn() => null);
        $second = $application->createSchedule();
        if ($first === $second || $second->events() !== []) {
            throw new LogicException('Each schedule build must start with a fresh event list.');
        }
    }
}
