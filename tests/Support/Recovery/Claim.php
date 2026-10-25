<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

final readonly class Claim
{
    public function __construct(
        public int $id,
        public string $ownerToken,
    ) {}
}
