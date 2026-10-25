<?php

declare(strict_types=1);

namespace App\Scheduler\Domain;

/**
 * Recorded run state; Abandoned may later become Succeeded or Failed when the original process reports its result.
 */
enum RunStatus: string
{
    case InProgress = 'in_progress';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
}
