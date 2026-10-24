<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use App\Scheduler\Exception\ConfigurationException;
use App\Scheduler\Infrastructure\Configuration\InMemorySchedulerConfiguration;
use JsonException;
use LogicException;
use Tests\Support\SchedulerFixtures;

final readonly class InMemorySchedulerConfigurationTest
{
    /**
     * @throws ConfigurationException
     */
    public function run(): void
    {
        $this->loadsConfigurationFromAFile();
        $this->keepsTheLoadedSnapshotWhenTheFileChanges();
        $this->rejectsAMissingFile();
        $this->rejectsADirectory();
        $this->propagatesAParserFailure();
    }

    /**
     * @throws ConfigurationException
     */
    private function loadsConfigurationFromAFile(): void
    {
        $provider = new InMemorySchedulerConfiguration(__DIR__ . '/../../Fixtures/Scheduler/schedules.json');
        $configuration = $provider->getConfiguration();

        if (
            $configuration->version !== 1
            || count($configuration->tasks) !== 4
            || $configuration->tasks[0]->id !== 'ping-every-minute'
        ) {
            throw new LogicException('The provider must return the configuration loaded from the supplied file.');
        }
    }

    /**
     * @throws ConfigurationException
     */
    private function keepsTheLoadedSnapshotWhenTheFileChanges(): void
    {
        $path = $this->temporaryFile();
        try {
            $this->writeFile($path, SchedulerFixtures::read('no-tasks.json'));
            $provider = new InMemorySchedulerConfiguration($path);
            $this->writeFile($path, 'The source is no longer valid JSON.');
            $configuration = $provider->getConfiguration();

            if (
                $configuration->version !== 1
                || $configuration->tasks !== []
                || $provider->getConfiguration() !== $configuration
            ) {
                throw new LogicException('The provider must retain the snapshot loaded during construction.');
            }
        } finally {
            $this->removeFile($path);
        }
    }

    private function rejectsAMissingFile(): void
    {
        $path = $this->temporaryFile();
        $this->removeFile($path);
        $this->assertUnreadablePath($path);
    }

    private function rejectsADirectory(): void
    {
        $this->assertUnreadablePath(__DIR__ . '/../../Fixtures/Scheduler');
    }

    private function assertUnreadablePath(string $path): void
    {
        $failure = $this->loadFailure($path);
        if ($failure->getMessage() !== 'Scheduler configuration file is not readable: ' . $path) {
            throw new LogicException(
                'The provider must identify the unreadable configuration path.',
                previous: $failure,
            );
        }
    }

    private function propagatesAParserFailure(): void
    {
        $failure = $this->loadFailure(__DIR__ . '/../../Fixtures/Scheduler/invalid/malformed-json.json');
        if (! $failure->getPrevious() instanceof JsonException) {
            throw new LogicException('The provider must propagate parsing failures with their original cause.');
        }
    }

    private function loadFailure(string $path): ConfigurationException
    {
        try {
            new InMemorySchedulerConfiguration($path);
        } catch (ConfigurationException $exception) {
            return $exception;
        }

        throw new LogicException('The provider must reject this configuration path: ' . $path);
    }

    private function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'emorfiq-config-');
        if ($path === false) {
            throw new LogicException('Unable to create a temporary configuration file.');
        }

        return $path;
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) !== strlen($contents)) {
            throw new LogicException('Unable to write the temporary configuration file.');
        }
    }

    private function removeFile(string $path): void
    {
        if (! unlink($path)) {
            throw new LogicException('Unable to remove the temporary configuration file.');
        }
    }
}
