<?php

declare(strict_types=1);

namespace DevTools\AdapterUi;

use RuntimeException;

/**
 * @phpstan-import-type LogRecord from LogRecordParser
 * @phpstan-type LogEntry array{id: string, record: LogRecord}
 * @phpstan-type LogBatch array{entries: list<LogEntry>, cursor: int}
 */
final readonly class LogReader
{
    private const int TAIL_BYTES = 131072;

    private const int TAIL_ENTRIES = 200;

    private const array EMPTY_BATCH = [
        'entries' => [],
        'cursor' => 0,
    ];

    public function __construct(
        private string $path,
        private LogRecordParser $parser = new LogRecordParser(),
    ) {}

    /**
     * @return LogBatch
     * @throws RuntimeException
     */
    public function read(?int $offset = null): array
    {
        if (! is_file($this->path)) {
            return self::EMPTY_BATCH;
        }

        $handle = fopen($this->path, 'r');
        if ($handle === false) {
            return self::EMPTY_BATCH;
        }

        try {
            return $this->readLocked($handle, $offset);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @return LogBatch
     * @throws RuntimeException
     */
    private function readLocked($handle, ?int $offset): array
    {
        if (! flock($handle, LOCK_SH)) {
            throw new RuntimeException('Unable to lock the log for reading.');
        }

        $tail = $this->seek($handle, $offset);
        $batch = $this->readEntries($handle);

        return [
            'entries' => $tail ? array_slice($batch['entries'], -self::TAIL_ENTRIES) : $batch['entries'],
            'cursor' => $batch['cursor'],
        ];
    }

    /**
     * @param resource $handle
     * @throws RuntimeException
     */
    private function seek($handle, ?int $offset): bool
    {
        $size = $this->size($handle);
        $tail = $offset === null || $offset < 0 || $offset > $size;
        $this->seekTo($handle, $tail ? max(0, $size - self::TAIL_BYTES) : $offset);

        if ($tail && $this->position($handle) > 0) {
            fgets($handle);
        }

        return $tail;
    }

    /**
     * @param resource $handle
     * @return LogBatch
     * @throws RuntimeException
     */
    private function readEntries($handle): array
    {
        $entries = [];
        $position = $this->position($handle);
        while (($line = fgets($handle)) !== false) {
            if (! str_ends_with($line, "\n")) {
                break;
            }

            $position = $this->position($handle);
            $record = $this->parser->parse($line);
            if ($record === null) {
                continue;
            }

            $entries[] = [
                'id' => (string) $position,
                'record' => $record,
            ];
        }

        return [
            'entries' => $entries,
            'cursor' => $position,
        ];
    }

    /**
     * @param resource $handle
     * @throws RuntimeException
     */
    private function size($handle): int
    {
        $stat = fstat($handle);
        if ($stat === false) {
            throw new RuntimeException('Unable to determine the log size.');
        }

        return $stat['size'];
    }

    /**
     * @param resource $handle
     * @throws RuntimeException
     */
    private function position($handle): int
    {
        $position = ftell($handle);
        if ($position === false) {
            throw new RuntimeException('Unable to determine the log position.');
        }

        return $position;
    }

    /**
     * @param resource $handle
     * @throws RuntimeException
     */
    private function seekTo($handle, int $offset): void
    {
        if (fseek($handle, $offset) !== 0) {
            throw new RuntimeException('Unable to seek in the log.');
        }
    }
}
