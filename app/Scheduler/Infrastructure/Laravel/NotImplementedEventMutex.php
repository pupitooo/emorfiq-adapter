<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Laravel;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use LogicException;
use Override;

/**
 * Rejects lock operations until event locking is implemented.
 */
final readonly class NotImplementedEventMutex implements EventMutex
{
    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter
     */
    #[Override]
    public function create(Event $event): bool
    {
        throw new LogicException('Not Implemented');
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter
     */
    #[Override]
    public function exists(Event $event): bool
    {
        throw new LogicException('Not Implemented');
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter
     */
    #[Override]
    public function forget(Event $event): void
    {
        throw new LogicException('Not Implemented');
    }
}
