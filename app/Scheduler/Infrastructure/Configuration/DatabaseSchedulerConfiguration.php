<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Configuration;

use App\Scheduler\Contract\SchedulerConfiguration;
use App\Scheduler\Domain\Configuration;
use App\Scheduler\Exception\ConfigurationException;
use Override;

/**
 * Placeholder for scheduler configuration loaded from a database.
 *
 * Database dependencies and loading will be designed later.
 */
final readonly class DatabaseSchedulerConfiguration implements SchedulerConfiguration
{
    /**
     * @throws ConfigurationException Until database configuration loading is implemented.
     */
    #[Override]
    public function getConfiguration(): Configuration
    {
        throw new ConfigurationException('Not Implemented');
    }
}
