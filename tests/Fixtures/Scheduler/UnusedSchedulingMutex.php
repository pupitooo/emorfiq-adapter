<?php

declare(strict_types=1);

namespace Tests\Fixtures\Scheduler;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\SchedulingMutex;
use LogicException;
use Override;

final readonly class UnusedSchedulingMutex implements SchedulingMutex
{
    #[Override]
    public function create(Event $event, DateTimeInterface $time): bool
    {
        throw new LogicException('Unexpected scheduling claim: ' . $event->mutexName() . $time->format('c'));
    }

    #[Override]
    public function exists(Event $event, DateTimeInterface $time): bool
    {
        throw new LogicException('Unexpected scheduling lookup: ' . $event->mutexName() . $time->format('c'));
    }
}
