<?php

declare(strict_types=1);

namespace App\Scheduler\Exception;

use RuntimeException;

/**
 * Storage failure that must remain distinguishable from an ordinary reservation conflict.
 */
final class MutexStorageException extends RuntimeException {}
