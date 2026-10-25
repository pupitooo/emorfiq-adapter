<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

enum ExecutionFault
{
    case None;
    case TaskException;
    case ReservationRollback;
    case ReservationAcknowledgementLost;
    case CrashBeforeTask;
    case CrashDuringTask;
    case CrashAfterEffect;
    case CompletionUnavailable;
}
