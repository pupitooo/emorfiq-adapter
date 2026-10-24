<?php

declare(strict_types=1);

use App\Task\Contract\Task;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()->autowire();

    $services->instanceof(Task::class)
        ->public()
        ->share(false);

    $services->load('App\\Task\\Execution\\', '../../app/Task/Execution/');
};
