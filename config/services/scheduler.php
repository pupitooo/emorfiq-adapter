<?php

declare(strict_types=1);

use App\Scheduler\Application\ExecutionCoordinator;
use App\Scheduler\Application\TaskExecutor;
use App\Scheduler\Contract\ExecutionMutex;
use App\Scheduler\Contract\TaskRunStorage;
use App\Scheduler\Infrastructure\Database\PostgresTaskRunStorage;
use App\Scheduler\Infrastructure\Laravel\Mutex\CoordinatedEventMutex;
use App\Scheduler\Infrastructure\Laravel\Mutex\CoordinatedSchedulingMutex;
use App\Scheduler\Infrastructure\Laravel\Mutex\EventExecutions;
use App\Scheduler\Infrastructure\Laravel\Runtime\SchedulerApplication;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    // Replace storage through this alias and its implementation registration.
    $container->services()->set(PostgresTaskRunStorage::class);
    $container->services()->alias(TaskRunStorage::class, PostgresTaskRunStorage::class);
    $container->services()->set(ExecutionCoordinator::class)->autowire();
    $container->services()->alias(ExecutionMutex::class, ExecutionCoordinator::class)->public();

    $container->services()->set(EventExecutions::class)->public();
    $container->services()->set(CoordinatedEventMutex::class)->autowire();
    $container->services()->set(CoordinatedSchedulingMutex::class)->autowire();
    $container->services()->set(SchedulerApplication::class)
        ->arg('$eventMutex', service(CoordinatedEventMutex::class))
        ->arg('$schedulingMutex', service(CoordinatedSchedulingMutex::class))
        ->public();
    $container->services()->set(TaskExecutor::class)->autowire()->public();
};
