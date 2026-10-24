<?php

declare(strict_types=1);

namespace App\Task\Contract;

use Exception;

interface Task
{
    /**
     * @throws Exception When task execution fails.
     */
    public function run(): void;
}
