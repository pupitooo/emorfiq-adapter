<?php

declare(strict_types=1);

namespace Tests\Fixtures\Scheduler;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Override;

final class RecordingEventMutex implements EventMutex
{
    /**
     * @var list<string|null>
     */
    public private(set) array $created = [];

    /**
     * @var list<string|null>
     */
    public private(set) array $released = [];

    #[Override]
    public function create(Event $event): bool
    {
        $this->created[] = $event->description;

        return $event->description !== 'racing';
    }

    #[Override]
    public function exists(Event $event): bool
    {
        return $event->description === 'overlapping';
    }

    #[Override]
    public function forget(Event $event): void
    {
        $this->released[] = $event->description;
    }
}
