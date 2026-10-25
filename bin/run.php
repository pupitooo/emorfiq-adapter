<?php

declare(strict_types=1);

use App\Scheduler\Application\TaskExecutor;
use App\Scheduler\Contract\ExecutionMutex;
use App\Scheduler\Infrastructure\Configuration\InMemorySchedulerConfiguration;
use App\Scheduler\Infrastructure\Laravel\LaravelAdapter;
use App\Scheduler\Infrastructure\Laravel\Mutex\EventExecutions;
use App\Scheduler\Infrastructure\Laravel\Runtime\SchedulerApplication;
use App\Scheduler\Infrastructure\ServerId;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

$projectDir = dirname(__DIR__);

require $projectDir . '/vendor/autoload.php';

$serverId = ServerId::fromEnvironment();

// This standalone setup uses Symfony DependencyInjection to load service definitions
// from config/services.php and autowire their dependencies.
// A framework application can use its own service registration and autowiring instead,
// with a TaskResolver implementation that resolves tasks from its container.
$container = new ContainerBuilder();
$loader = new PhpFileLoader($container, new FileLocator($projectDir . '/config'));
$loader->load('services.php');
$container->compile(true);

// Resolve SchedulerApplication, which supplies Laravel's scheduler context.
// Tasks are instantiated later, when selected callbacks invoke TaskExecutor.
$scheduler = $container->get(SchedulerApplication::class);
if (! $scheduler instanceof SchedulerApplication) {
    throw new LogicException('Scheduler application service must implement ' . SchedulerApplication::class);
}

// Compilation wires TaskExecutor to SymfonyTaskResolver through TaskResolver.
$executor = $container->get(TaskExecutor::class);
if (! $executor instanceof TaskExecutor) {
    throw new LogicException('Task executor service must implement ' . TaskExecutor::class);
}

$configuration = new InMemorySchedulerConfiguration($projectDir . '/config/scheduler.json');
$mutex = $container->get(ExecutionMutex::class);
if (! $mutex instanceof ExecutionMutex) {
    throw new LogicException('Execution mutex service must implement ' . ExecutionMutex::class);
}
$executions = $container->get(EventExecutions::class);
if (! $executions instanceof EventExecutions) {
    throw new LogicException('Event executions service must implement ' . EventExecutions::class);
}

$adapter = new LaravelAdapter(
    scheduler: $scheduler,
    executor: $executor,
    configuration: $configuration,
    mutex: $mutex,
    serverId: $serverId,
    executions: $executions,
);
$adapter->run();
