<?php

declare(strict_types=1);

namespace App\Scheduler\Domain;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Identifies a committed reservation and its owner. Never log the owner token.
 * Completion addresses this run, not every run belonging to its schedule.
 */
final readonly class RunClaim
{
    public function __construct(
        public int $id,
        #[SensitiveParameter]
        public string $ownerToken,
    ) {
        if ($id <= 0) {
            throw new InvalidArgumentException('Run ID must be positive.');
        }
        if (
            preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $ownerToken) !== 1
            || $ownerToken === '00000000-0000-0000-0000-000000000000'
        ) {
            throw new InvalidArgumentException('Owner token must be a nonzero UUID in canonical format.');
        }
    }
}
