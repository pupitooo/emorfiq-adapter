<?php

declare(strict_types=1);

namespace App\Task\Infrastructure\Symfony;

use App\Task\Contract\Task;
use App\Task\Contract\TaskResolver;
use LogicException;
use Override;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ExceptionInterface;

final readonly class SymfonyTaskResolver implements TaskResolver
{
    public function __construct(
        private ContainerInterface $container,
    ) {}

    #[Override]
    public function resolve(string $taskClass): Task
    {
        if (! $this->container->has($taskClass)) {
            throw new LogicException('Task is not registered: ' . $taskClass);
        }

        try {
            $task = $this->container->get($taskClass);
        } catch (ExceptionInterface $exception) {
            throw new LogicException('Unable to resolve task: ' . $taskClass, previous: $exception);
        }

        if (! $task instanceof Task) {
            throw new LogicException('Registered service must implement Task: ' . $taskClass);
        }

        return $task;
    }
}
