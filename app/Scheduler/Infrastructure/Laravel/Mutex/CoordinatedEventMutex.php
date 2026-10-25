<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Laravel\Mutex;

use App\Scheduler\Exception\MutexStorageException;
use App\Scheduler\Exception\ReservationUnconfirmedException;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Override;

final readonly class CoordinatedEventMutex implements EventMutex
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
    public function create(Event $event): bool
    {
        return $this->executions->forEvent($event)->claim() !== null;
    }

    /**
     * @throws MutexStorageException
     */
    // @phpstan-ignore throws.notCovariantWithImplicitVoid (Illuminate omits storage exceptions.)
    #[Override]
    public function exists(Event $event): bool
    {
        return $this->executions->forEvent($event)->isOverlapLocked();
    }

    #[Override]
    public function forget(Event $event): void
    {
        // Completion is recorded by ScheduledExecution; this hook clears only local state.
        $this->executions->forEvent($event)->forget();
    }
}
