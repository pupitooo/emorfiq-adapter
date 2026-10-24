<?php

declare(strict_types=1);

namespace Tests\Fixtures\Scheduler;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\SchedulingMutex;
use LogicException;
use Override;

final class RecordingSchedulingMutex implements SchedulingMutex
{
    /**
     * @var list<array{name: string|null, time: string}>
     */
    public private(set) array $attempts = [];

    #[Override]
    public function create(Event $event, DateTimeInterface $time): bool
    {
        $this->attempts[] = [
            'name' => $event->description,
            'time' => $time->format('Y-m-d H:i:s'),
        ];

        return $event->description !== 'blocked';
    }

    #[Override]
    public function exists(Event $event, DateTimeInterface $time): bool
    {
        throw new LogicException('Unexpected existence check: ' . $event->mutexName() . $time->format('c'));
    }
}
