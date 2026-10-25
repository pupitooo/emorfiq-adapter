<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

final readonly class RunRecord
{
    public function __construct(
        public Claim $claim,
        public string $status,
        public ?int $leaseExpiresAt,
        public ?int $finishedAt = null,
        public ?string $error = null,
        public ?int $abandonedAt = null,
        public ?string $abandonedBy = null,
    ) {}
}
