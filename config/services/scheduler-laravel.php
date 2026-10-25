<?php

declare(strict_types=1);

use App\Scheduler\Infrastructure\Laravel\Mutex\CoordinatedEventMutex;
use App\Scheduler\Infrastructure\Laravel\Mutex\CoordinatedSchedulingMutex;
use App\Scheduler\Infrastructure\Laravel\Mutex\EventExecutions;
use App\Scheduler\Infrastructure\Laravel\Runtime\SchedulerApplication;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()->autowire();

    $services->set(EventExecutions::class)->public();
    $services->set(CoordinatedEventMutex::class);
    $services->set(CoordinatedSchedulingMutex::class);
    $services->alias(EventMutex::class, CoordinatedEventMutex::class);
    $services->alias(SchedulingMutex::class, CoordinatedSchedulingMutex::class);
    $services->set(SchedulerApplication::class)->public();
};
