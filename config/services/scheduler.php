<?php

declare(strict_types=1);

use App\Scheduler\Application\TaskExecutor;
use App\Scheduler\Infrastructure\Laravel\Runtime\SchedulerApplication;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()->set(SchedulerApplication::class)->public();
    $container->services()->set(TaskExecutor::class)->autowire()->public();
};
