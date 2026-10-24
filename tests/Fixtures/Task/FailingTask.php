<?php

declare(strict_types=1);

namespace Tests\Fixtures\Task;

use App\Task\Contract\Task;
use Override;
use RuntimeException;

final readonly class FailingTask implements Task
{
    /**
     * @throws RuntimeException
     */
    #[Override]
    public function run(): void
    {
        throw new RuntimeException('Task execution failed.');
    }
}
