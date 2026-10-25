<?php

declare(strict_types=1);

namespace Tests\Support\Storage;

use LogicException;

final class StorageWorker
{
    /**
     * @var resource|null
     */
    private mixed $process;

    /**
     * @var array{resource, resource, resource}
     */
    private array $pipes;

    public readonly int $pid;

    /**
     * @param array<string, int|string> $operation
     * @throws \JsonException
     */
    public function __construct(StorageDatabase $database, array $operation)
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/storage-worker.php'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        if ($process === false || ! isset($pipes[0], $pipes[1], $pipes[2])) {
            throw new LogicException('Unable to start storage worker.');
        }
        $this->process = $process;
        $this->pipes = [$pipes[0], $pipes[1], $pipes[2]];
        stream_set_timeout($pipes[1], 15);
        fwrite($pipes[0], json_encode([
            'schema' => $database->schema,
        ] + $operation, JSON_THROW_ON_ERROR) . "\n");
        $this->pid = (int) fgets($pipes[1]);
    }

    /**
     * @throws \PDOException
     */
    public function awaitLock(StorageDatabase $database): void
    {
        $statement = $database->prepare(<<<'SQL'
            SELECT 1 FROM pg_stat_activity WHERE pid = ? AND wait_event_type = 'Lock'
            SQL);
        $deadline = microtime(true) + 10;
        do {
            $statement->execute([$this->pid]);
            if ($statement->fetchColumn() !== false) {
                return;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);
        throw new LogicException('Worker did not reach the database lock barrier.');
    }

    public function awaitCommit(): void
    {
        if (fgets($this->pipes[1]) !== "commit\n") {
            throw new LogicException('Worker did not reach the commit barrier.');
        }
    }

    public function releaseCommit(): void
    {
        fwrite($this->pipes[0], "commit\n");
    }

    /**
     * @throws \JsonException
     */
    public function result(): int|bool|null
    {
        $output = stream_get_contents($this->pipes[1]);
        $error = stream_get_contents($this->pipes[2]);
        $process = $this->process ?? throw new LogicException('Worker already collected.');
        $status = proc_close($process);
        $this->process = null;
        if ($status !== 0 || $output === false) {
            throw new LogicException('Storage worker failed: ' . $error . $output);
        }
        return $this->decode($output);
    }

    /**
     * @throws \JsonException
     */
    private function decode(string $output): int|bool|null
    {
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($result) || ! array_key_exists('result', $result)) {
            throw new LogicException('Invalid storage worker response.');
        }
        $value = $result['result'];
        if (! is_int($value) && ! is_bool($value) && $value !== null) {
            throw new LogicException('Invalid storage worker result.');
        }

        return $value;
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
    }
}
