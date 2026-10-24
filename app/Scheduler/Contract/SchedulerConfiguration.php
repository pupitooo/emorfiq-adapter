<?php

declare(strict_types=1);

namespace App\Scheduler\Contract;

use App\Scheduler\Domain\Configuration;
use App\Scheduler\Exception\ConfigurationException;

/**
 * Source-independent contract for providers of typed scheduler configuration.
 */
interface SchedulerConfiguration
{
    /**
     * @throws ConfigurationException When configuration cannot be loaded.
     */
    public function getConfiguration(): Configuration;
}
