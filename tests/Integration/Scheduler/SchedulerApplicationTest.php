<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use App\Scheduler\Infrastructure\Laravel\SchedulerApplication;
use App\Task\Infrastructure\Symfony\SymfonyTaskResolver;
use Exception;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use LogicException;
use Psr\Log\LoggerInterface;
use Tests\Fixtures\Scheduler\UnusedEventMutex;
use Tests\Fixtures\Task\LoggingTask;
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
    }

    /**
     * @throws Throwable
     */
    private function callbacksUseCurrentContainer(): void
    {
        $application = new SchedulerApplication('testing');
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
        $task = $event->run(new SchedulerApplication());
        if (! $task instanceof LoggingTask || $task->logger !== $services->get(LoggerInterface::class)) {
            throw new LogicException('Scheduled callbacks must retain Symfony task construction and dependencies.');
        }
    }

    /**
     * @throws Exception
     */
    private function containersKeepSeparateBindings(): void
    {
        $first = new SchedulerApplication();
        $second = new SchedulerApplication();
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
        new SchedulerApplication();
        if (Container::getInstance() !== $original) {
            throw new LogicException('Creating the scheduler context must not replace the global container.');
        }
    }
}
