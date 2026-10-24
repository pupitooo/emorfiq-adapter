<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Laravel\Runtime;

use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule as IlluminateSchedule;
use Illuminate\Console\Scheduling\SchedulingMutex;

/**
 * Initializes Illuminate's schedule with explicit dependencies instead of its global container.
 */
final class Schedule extends IlluminateSchedule
{
    /**
     * @throws void
     */
    // @phpstan-ignore constructor.missingParentCall (The parent resolves dependencies through the global container.)
    public function __construct(EventMutex $eventMutex, SchedulingMutex $schedulingMutex)
    {
        $this->eventMutex = $eventMutex;
        $this->schedulingMutex = $schedulingMutex;
    }
}
