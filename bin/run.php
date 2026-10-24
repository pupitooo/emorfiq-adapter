<?php

declare(strict_types=1);

use App\Scheduler\Application\TaskExecutor;
use App\Scheduler\Exception\SchedulerRunException;
use App\Scheduler\Infrastructure\Configuration\InMemorySchedulerConfiguration;
use App\Scheduler\Infrastructure\Laravel\LaravelAdapter;
use App\Scheduler\Infrastructure\Laravel\Runtime\SchedulerApplication;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

$projectDir = dirname(__DIR__);

require $projectDir . '/vendor/autoload.php';

$container = new ContainerBuilder();
$loader = new PhpFileLoader($container, new FileLocator($projectDir . '/config'));
$loader->load('services.php');
$container->compile();

$configuration = new InMemorySchedulerConfiguration($projectDir . '/config/scheduler.json');
$application = $container->get(SchedulerApplication::class);
if (! $application instanceof SchedulerApplication) {
    throw new LogicException('Scheduler application service must implement ' . SchedulerApplication::class);
}

$executor = $container->get(TaskExecutor::class);
if (! $executor instanceof TaskExecutor) {
    throw new LogicException('Task executor service must implement ' . TaskExecutor::class);
}

$adapter = new LaravelAdapter($executor, $configuration, $application);
try {
    $adapter->run();
} catch (SchedulerRunException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
