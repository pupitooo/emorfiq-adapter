<?php

declare(strict_types=1);

namespace DevTools\AdapterUi;

use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Infrastructure\Configuration\JsonConfigurationParser;
use RuntimeException;

final readonly class ConfigurationView
{
    public function __construct(
        private string $path
    ) {}

    /**
     * @return array{source: string, version: int, tasks: list<ScheduleDefinition>, modifiedAt: string|null}
     * @throws RuntimeException
     */
    public function read(): array
    {
        $source = file_get_contents($this->path);
        if ($source === false) {
            throw new RuntimeException('Konfiguraci se nepodařilo načíst.');
        }
        $configuration = new JsonConfigurationParser()->parse($source);
        $modified = filemtime($this->path);

        return [
            'source' => $source,
            'version' => $configuration->version,
            'tasks' => $configuration->tasks,
            'modifiedAt' => $modified === false ? null : gmdate('c', $modified),
        ];
    }
}
