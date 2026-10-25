<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Laravel\Mutex;

use App\Scheduler\Exception\MutexStorageException;
use App\Scheduler\Exception\ReservationUnconfirmedException;
use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Override;

final readonly class CoordinatedSchedulingMutex implements SchedulingMutex
{
    public function __construct(
        private EventExecutions $executions,
    ) {}

    /**
     * @throws MutexStorageException
     * @throws ReservationUnconfirmedException
     */
    // @phpstan-ignore throws.notCovariantWithImplicitVoid (Illuminate omits storage exceptions.)
    #[Override]
    public function create(Event $event, DateTimeInterface $time): bool
    {
        return $this->executions->forOccurrence(event: $event, instant: $time)->claim() !== null;
    }

    /**
     * @throws MutexStorageException
     */
    // @phpstan-ignore throws.notCovariantWithImplicitVoid (Illuminate omits storage exceptions.)
    #[Override]
    public function exists(Event $event, DateTimeInterface $time): bool
    {
        return $this->executions->forOccurrence(event: $event, instant: $time)->isOccurrenceReserved();
    }
}
