<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

final class RecoveryProcesses
{
    /**
     * @var list<RecoveryProcess>
     */
    private array $workers = [];

    public function __construct(
        private readonly string $schema
    ) {}

    /**
     * @param array<string, int|string|bool> $operation
     * @throws \JsonException
     */
    public function start(array $operation): RecoveryProcess
    {
        $worker = new RecoveryProcess($this->schema, $operation);
        $this->workers[] = $worker;
        return $worker;
    }

    public function close(): void
    {
        foreach ($this->workers as $worker) {
            $worker->close();
        }
        $this->workers = [];
    }
}
