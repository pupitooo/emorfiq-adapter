<?php

declare(strict_types=1);

namespace App\Scheduler\Contract;

use Exception;

interface Adapter
{
    /**
     * @throws Exception When execution fails.
     */
    public function run(): void;
}
