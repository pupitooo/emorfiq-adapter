<?php

declare(strict_types=1);

use App\Database\PostgresConnectionFactory;
use Dibi\Connection;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(PostgresConnectionFactory::class)
        ->factory([PostgresConnectionFactory::class, 'fromEnvironment']);

    // Each consumer owns its connection and transaction boundaries.
    $services->set(Connection::class)
        ->factory([service(PostgresConnectionFactory::class), 'connect'])
        ->share(false);
};
