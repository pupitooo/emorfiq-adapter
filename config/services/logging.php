<?php

declare(strict_types=1);

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(JsonFormatter::class)->arg('$includeStacktraces', true);

    $services->set(StreamHandler::class)
        ->arg('$stream', __DIR__ . '/../../storage/logs/adapter.log')
        ->arg('$level', Level::Info)
        ->arg('$useLocking', true)
        ->call('setFormatter', [service(JsonFormatter::class)]);

    $services->set(Logger::class)
        ->arg('$name', 'tasks')
        ->arg('$handlers', [service(StreamHandler::class)]);

    $services->alias(LoggerInterface::class, Logger::class);
};
