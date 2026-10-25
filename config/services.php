<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->import('services/logging.php');
    $container->import('services/database.php');
    $container->import('services/scheduler.php');
    $container->import('services/scheduler-laravel.php');
    $container->import('services/tasks.php');
};
