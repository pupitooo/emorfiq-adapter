<?php

declare(strict_types=1);

namespace App\Task\Contract;

use LogicException;

interface TaskResolver
{
    /**
     * @throws LogicException When a task cannot be resolved from registered services.
     */
    public function resolve(string $taskClass): Task;
}
