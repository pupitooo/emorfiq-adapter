<?php

declare(strict_types=1);

use App\Scheduler\Infrastructure\Laravel\SchedulerApplication;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()->set(SchedulerApplication::class)->public();
};
