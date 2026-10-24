<?php

declare(strict_types=1);

use App\Scheduler\Infrastructure\Configuration\InMemorySchedulerConfiguration;
use App\Scheduler\Infrastructure\Laravel\LaravelAdapter;
use App\Scheduler\Infrastructure\Laravel\Runtime\SchedulerApplication;
use App\Task\Infrastructure\Symfony\SymfonyTaskResolver;
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

$adapter = new LaravelAdapter(new SymfonyTaskResolver($container), $configuration, $application);

$adapter->run();
