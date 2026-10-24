<?php

declare(strict_types=1);

namespace Tests\Fixtures\Task;

use App\Task\Contract\Task;
use Error;
use Override;

final readonly class ErrorTask implements Task
{
    /**
     * @throws Error
     */
    #[Override]
    public function run(): void
    {
        throw new Error('Task error.');
    }
}
