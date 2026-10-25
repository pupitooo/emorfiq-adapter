<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use LogicException;
use Tests\Support\Storage\StorageDatabase;

final class RecoveryProcess
{
    /**
     * @var resource|null
     */
    private mixed $process;

    /**
     * @var list<resource>
     */
    private array $pipes;

    public readonly int $pid;

    /**
     * @param array<string, int|string|bool> $operation
     * @throws \JsonException
     */
    public function __construct(string $schema, array $operation)
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/recovery-worker.php'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        if ($process === false || count($pipes) !== 3) {
            throw new LogicException('Unable to start recovery process.');
        }
        $this->process = $process;
        $this->pipes = array_values($pipes);
        try {
            $this->initialize($schema, $operation);
            $this->pid = (int) $this->await('ready');
        } catch (\Throwable $failure) {
            $this->close();
            throw $failure;
        }
    }

    /**
     * @param array<string, int|string|bool> $operation
     * @throws \JsonException
     */
    private function initialize(string $schema, array $operation): void
    {
        stream_set_timeout($this->pipe(1), 15);
        stream_set_blocking($this->pipe(2), false);
        fwrite($this->pipe(0), json_encode([
            'schema' => $schema,
        ] + $operation, JSON_THROW_ON_ERROR) . "\n");
    }

    /**
     * @return resource
     */
    private function pipe(int $index): mixed
    {
        return $this->pipes[$index] ?? throw new LogicException('Missing worker pipe.');
    }

    /**
     * @throws \JsonException
     */
    public function await(string $event): int|string|bool|null
    {
        $line = fgets($this->pipe(1));
        if ($line === false) {
            throw new LogicException('Recovery worker timed out or exited: ' . stream_get_contents($this->pipe(2)));
        }
        $message = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($message) || ($message['event'] ?? null) !== $event) {
            throw new LogicException('Expected worker event ' . $event . ', got ' . $line);
        }
        return $this->scalar($message['value'] ?? null);
    }

    private function scalar(mixed $value): int|string|bool|null
    {
        if ($value === null || is_int($value) || is_string($value) || is_bool($value)) {
            return $value;
        }
        throw new LogicException('Invalid worker response.');
    }

    /**
     * @param array<string, int|string|bool> $command
     * @throws \JsonException
     */
    public function command(array $command): void
    {
        fwrite($this->pipe(0), json_encode($command, JSON_THROW_ON_ERROR) . "\n");
    }

    public function release(): void
    {
        fwrite($this->pipe(0), "continue\n");
    }

    /**
     * @throws \PDOException
     */
    public function awaitLock(StorageDatabase $database): void
    {
        $statement = $database->prepare("SELECT 1 FROM pg_stat_activity WHERE pid = ? AND wait_event_type = 'Lock'");
        $deadline = microtime(true) + 10;
        do {
            $statement->execute([$this->pid]);
            if ($statement->fetchColumn() !== false) {
                return;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);
        throw new LogicException('Recovery process did not reach database lock barrier.');
    }

    /**
     * @throws \JsonException
     */
    public function result(): int|string|bool|null
    {
        $result = $this->await('result');
        $this->waitForExit();
        return $result;
    }

    private function waitForExit(): void
    {
        $process = $this->process ?? throw new LogicException('Worker already collected.');
        $error = stream_get_contents($this->pipe(2));
        $code = proc_close($process);
        $this->process = null;
        if ($code !== 0) {
            throw new LogicException('Recovery worker failed: ' . $error);
        }
    }

    public function kill(): void
    {
        $process = $this->process ?? throw new LogicException('Worker already collected.');
        RecoveryAssertions::same(true, proc_get_status($process)['running']);
        RecoveryAssertions::same(true, proc_terminate($process, 9));
        $this->awaitTermination($process);
        proc_close($process);
        $this->process = null;
    }

    /**
     * @param resource $process
     */
    private function awaitTermination(mixed $process): void
    {
        $deadline = microtime(true) + 10;
        do {
            $status = proc_get_status($process);
            if (! $status['running']) {
                RecoveryAssertions::same(true, $status['signaled']);
                RecoveryAssertions::same(9, $status['termsig']);
                return;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);
        throw new LogicException('Crash worker did not terminate.');
    }

    public function close(): void
    {
        if ($this->process !== null) {
            proc_terminate($this->process, 9);
            proc_close($this->process);
            $this->process = null;
        }
        foreach ($this->pipes as $pipe) {
            if (! is_resource($pipe)) {
                continue;
            }

            fclose($pipe);
        }
        $this->pipes = [];
    }
}
