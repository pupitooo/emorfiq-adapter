<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Laravel;

use Exception;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
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
        $this->instance(EventMutex::class, new NotImplementedEventMutex());
        $this->instance(SchedulingMutex::class, new NotImplementedSchedulingMutex());
    }

    public function environment(): string
    {
        return $this->environmentName;
    }

    /**
     * @throws Exception
     */
    public function createSchedule(): Schedule
    {
        $previous = Container::getInstance();
        Container::setInstance($this);
        try {
            return new Schedule();
        } finally {
            Container::setInstance($previous);
        }
    }

    public function isDownForMaintenance(): bool
    {
        // This standalone application has no Laravel maintenance lifecycle.
        return false;
    }
}
