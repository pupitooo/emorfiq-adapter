<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Configuration;

use App\Scheduler\Contract\SchedulerConfiguration;
use App\Scheduler\Domain\Configuration;
use App\Scheduler\Exception\ConfigurationException;
use Override;

/**
 * Loads a JSON configuration once and retains its typed representation in memory.
 */
final readonly class InMemorySchedulerConfiguration implements SchedulerConfiguration
{
    private Configuration $configuration;

    /**
     * @throws ConfigurationException
     */
    public function __construct(string $path)
    {
        $json = $this->readFile($path);
        $this->configuration = new JsonConfigurationParser()->parse($json);
    }

    /**
     * @throws void
     */
    #[Override]
    public function getConfiguration(): Configuration
    {
        return $this->configuration;
    }

    /**
     * @throws ConfigurationException
     */
    private function readFile(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new ConfigurationException('Scheduler configuration file is not readable: ' . $path);
        }

        $json = @file_get_contents($path);
        if ($json === false) {
            throw new ConfigurationException('Unable to read scheduler configuration file: ' . $path);
        }

        return $json;
    }
}
