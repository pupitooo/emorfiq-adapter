<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Crunz;

use App\Scheduler\Contract\Adapter;
use LogicException;
use Override;

final readonly class CrunzAdapter implements Adapter
{
    #[Override]
    public function run(): void
    {
        throw new LogicException('Not Implemented');
    }
}
