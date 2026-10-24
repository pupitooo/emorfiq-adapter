<?php

declare(strict_types=1);

namespace Tests\Integration\Task;

use App\Task\Execution\PingTask;
use App\Task\Infrastructure\Symfony\SymfonyTaskResolver;
use Exception;
use LogicException;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException;
use Symfony\Component\DependencyInjection\Reference;
use Tests\Fixtures\Task\LoggingTask;
use Tests\Support\TaskServices;

final readonly class SymfonyTaskResolverTest
{
    /**
     * @throws Exception
     */
    public function run(): void
    {
        $this->createsFreshTasksWithSharedDependencies();
        $this->rejectsUnregisteredTask();
        $this->rejectsNonTaskService();
        $this->preservesContainerFailure();
    }

    /**
     * @throws Exception
     */
    private function createsFreshTasksWithSharedDependencies(): void
    {
        $container = TaskServices::container('php://memory');
        $resolver = new SymfonyTaskResolver($container);
        $ping = $resolver->resolve(PingTask::class);
        $first = $resolver->resolve(LoggingTask::class);
        $second = $resolver->resolve(LoggingTask::class);

        if (
            ! $ping instanceof PingTask || $ping === $resolver->resolve(PingTask::class)
            || ! $first instanceof LoggingTask || ! $second instanceof LoggingTask || $first === $second
            || $first->logger !== $second->logger
            || $first->logger !== $container->get(LoggerInterface::class)
        ) {
            throw new LogicException('Tasks must receive fresh instances and shared constructor dependencies.');
        }
    }

    private function rejectsUnregisteredTask(): void
    {
        $resolver = new SymfonyTaskResolver(new ContainerBuilder());
        $failure = $this->resolutionFailure($resolver, LoggingTask::class);
        if ($failure->getMessage() !== 'Task is not registered: ' . LoggingTask::class) {
            throw new LogicException('The resolver must identify unregistered tasks.', previous: $failure);
        }
    }

    private function rejectsNonTaskService(): void
    {
        $container = new ContainerBuilder();
        $container->register(stdClass::class, stdClass::class)->setPublic(true);
        $container->compile();
        $failure = $this->resolutionFailure(new SymfonyTaskResolver($container), stdClass::class);
        if ($failure->getMessage() !== 'Registered service must implement Task: ' . stdClass::class) {
            throw new LogicException('Services must implement the task contract.', previous: $failure);
        }
    }

    private function preservesContainerFailure(): void
    {
        $container = new ContainerBuilder();
        $container->register(LoggingTask::class, LoggingTask::class)
            ->setArguments([new Reference(LoggingTask::class)])
            ->setPublic(true);
        $failure = $this->resolutionFailure(new SymfonyTaskResolver($container), LoggingTask::class);
        if (
            $failure->getMessage() !== 'Unable to resolve task: ' . LoggingTask::class
            || ! $failure->getPrevious() instanceof ServiceCircularReferenceException
        ) {
            throw new LogicException('The resolver must preserve the container failure as its cause.');
        }
    }

    private function resolutionFailure(SymfonyTaskResolver $resolver, string $class): LogicException
    {
        try {
            $resolver->resolve($class);
        } catch (LogicException $exception) {
            return $exception;
        }

        throw new LogicException('Invalid task resolution must fail.');
    }
}
