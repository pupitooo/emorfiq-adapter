<?php

declare(strict_types=1);

use App\Scheduler\Application\ExecutionCoordinator;
use App\Scheduler\Application\TaskExecutor;
use App\Scheduler\Contract\ExecutionMutex;
use App\Scheduler\Contract\TaskRunStorage;
use App\Scheduler\Infrastructure\Database\PostgresTaskRunStorage;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()->autowire();

    // Replace storage through this alias and its implementation registration.
    $services->set(PostgresTaskRunStorage::class)
        ->factory([PostgresTaskRunStorage::class, 'connect'])
        ->lazy(TaskRunStorage::class);
    $services->alias(TaskRunStorage::class, PostgresTaskRunStorage::class);
    $services->set(ExecutionCoordinator::class);
    $services->alias(ExecutionMutex::class, ExecutionCoordinator::class)->public();

    $services->set(TaskExecutor::class)->public();
};
