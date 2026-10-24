<?php

declare(strict_types=1);

namespace Tests\Fixtures\Scheduler;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use LogicException;
use Override;

final readonly class UnusedEventMutex implements EventMutex
{
    #[Override]
    public function create(Event $event): bool
    {
        throw new LogicException('Not Implemented: ' . $event->getSummaryForDisplay());
    }

    #[Override]
    public function exists(Event $event): bool
    {
        throw new LogicException('Not Implemented: ' . $event->getSummaryForDisplay());
    }

    #[Override]
    public function forget(Event $event): void
    {
        throw new LogicException('Not Implemented: ' . $event->getSummaryForDisplay());
    }
}
