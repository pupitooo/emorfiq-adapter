<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;

/**
 * Standalone container and application context for scheduler callbacks.
 */
final class SchedulerApplication extends Container
{
    private const string DEFAULT_ENVIRONMENT = 'production';

    public function __construct(
        private readonly string $environmentName = self::DEFAULT_ENVIRONMENT,
    ) {
        $this->instance(self::class, $this);
        $this->instance(Container::class, $this);
        $this->instance(ContainerContract::class, $this);
    }

    public function environment(): string
    {
        return $this->environmentName;
    }

    public function isDownForMaintenance(): bool
    {
        // This standalone application has no Laravel maintenance lifecycle.
        return false;
    }
}
