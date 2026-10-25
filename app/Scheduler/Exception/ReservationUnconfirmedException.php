<?php

declare(strict_types=1);

namespace App\Scheduler\Exception;

use RuntimeException;

/**
 * Commit may have succeeded without acknowledgement. Do not execute the task or blindly retry the claim.
 */
final class ReservationUnconfirmedException extends RuntimeException {}
