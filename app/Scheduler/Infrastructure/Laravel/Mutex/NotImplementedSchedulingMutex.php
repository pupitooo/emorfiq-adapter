<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Laravel\Mutex;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\SchedulingMutex;
use LogicException;
use Override;

/**
 * Rejects lock operations until single-server coordination is implemented.
 */
final readonly class NotImplementedSchedulingMutex implements SchedulingMutex
{
    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter
     */
    #[Override]
    public function create(Event $event, DateTimeInterface $time): bool
    {
        throw new LogicException('Not Implemented');
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter
     */
    #[Override]
    public function exists(Event $event, DateTimeInterface $time): bool
    {
        throw new LogicException('Not Implemented');
    }
}
