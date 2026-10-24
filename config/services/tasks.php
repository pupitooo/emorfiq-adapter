<?php

declare(strict_types=1);

use App\Task\Contract\Task;
use App\Task\Contract\TaskResolver;
use App\Task\Infrastructure\Symfony\SymfonyTaskResolver;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()->autowire();

    $services->instanceof(Task::class)
        ->public()
        ->share(false);

    $services->load('App\\Task\\Execution\\', '../../app/Task/Execution/');
    $services->set(SymfonyTaskResolver::class)->arg('$container', service('service_container'));
    $services->alias(TaskResolver::class, SymfonyTaskResolver::class);
};
