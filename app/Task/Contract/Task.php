<?php

declare(strict_types=1);

namespace App\Task\Contract;

use Throwable;

interface Task
{
    /**
     * @throws Throwable When task execution fails.
     */
    public function run(): void;
}
