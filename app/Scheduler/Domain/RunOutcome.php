<?php

declare(strict_types=1);

namespace App\Scheduler\Domain;

/**
 * Actual result reported by the original process. Recovery records RunStatus::Abandoned separately.
 */
enum RunOutcome: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
