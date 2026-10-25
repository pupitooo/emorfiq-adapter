<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Laravel\Mutex;

use App\Scheduler\Application\ScheduledExecution;
use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use LogicException;
use WeakMap;

final readonly class EventExecutions
{
    /**
     * @var WeakMap<Event, ScheduledExecution>
     */
    private WeakMap $executions;

    public function __construct()
    {
        $this->executions = new WeakMap();
    }

    public function register(Event $event, ScheduledExecution $execution): void
    {
        $this->executions->offsetSet($event, $execution);
    }

    public function forEvent(Event $event): ScheduledExecution
    {
        return $this->executions[$event] ?? throw new LogicException('Scheduled event has no execution context.');
    }

    public function forOccurrence(Event $event, DateTimeInterface $instant): ScheduledExecution
    {
        $execution = $this->forEvent($event);
        if (! $execution->isScheduledFor($instant)) {
            throw new LogicException('Scheduling time does not match the registered occurrence.');
        }

        return $execution;
    }
}
