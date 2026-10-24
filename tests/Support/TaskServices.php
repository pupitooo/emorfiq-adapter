<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Scheduler\Application\TaskExecutor;
use Exception;
use LogicException;
use Monolog\Handler\StreamHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Tests\Fixtures\Task\ErrorTask;
use Tests\Fixtures\Task\FailingTask;
use Tests\Fixtures\Task\LoggingTask;

final readonly class TaskServices
{
    /**
     * @throws Exception
     */
    public static function executor(ContainerBuilder $container): TaskExecutor
    {
        $executor = $container->get(TaskExecutor::class);
        if (! $executor instanceof TaskExecutor) {
            throw new LogicException('Task executor service is missing.');
        }

        return $executor;
    }

    /**
     * @throws Exception
     */
    public static function container(string $logPath): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');
        $container->getDefinition(StreamHandler::class)->setArgument('$stream', $logPath);
        $container->getAlias(LoggerInterface::class)->setPublic(true);
        $container->register(LoggingTask::class, LoggingTask::class)
            ->setAutowired(true)
            ->setShared(false)
            ->setPublic(true);
        $container->register(FailingTask::class, FailingTask::class)->setShared(false)->setPublic(true);
        $container->register(ErrorTask::class, ErrorTask::class)->setShared(false)->setPublic(true);
        $container->compile();

        return $container;
    }
}
