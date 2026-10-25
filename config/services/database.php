<?php

declare(strict_types=1);

use App\Database\PostgresConnectionFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(PostgresConnectionFactory::class)
        ->arg('$dsn', '%env(DATABASE_DSN)%')
        ->arg('$username', '%env(DATABASE_USER)%')
        ->arg('$password', '%env(DATABASE_PASSWORD)%');

    // Each consumer owns its connection and transaction boundaries.
    $services->set(PDO::class, PDO::class)
        ->factory([service(PostgresConnectionFactory::class), 'connect'])
        ->share(false);
};
