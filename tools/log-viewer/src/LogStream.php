<?php

declare(strict_types=1);

namespace DevTools\LogViewer;

use JsonException;
use RuntimeException;

/**
 * @phpstan-import-type LogBatch from LogReader
 * @phpstan-import-type LogEntry from LogReader
 */
final readonly class LogStream
{
    private const int DURATION_SECONDS = 25;

    private const int TIME_LIMIT_SECONDS = 35;

    private const int POLL_MICROSECONDS = 250000;

    public function __construct(
        private LogReader $reader,
    ) {}

    /**
     * @throws JsonException
     * @throws RuntimeException
     */
    public function run(?int $offset): void
    {
        $this->start();
        $cursor = $offset;
        $deadline = microtime(true) + self::DURATION_SECONDS;
        do {
            $batch = $this->reader->read($cursor);
            $cursor = $batch['cursor'];
            $this->writeBatch($batch);
            flush();
            usleep(self::POLL_MICROSECONDS);
        } while (connection_aborted() === 0 && microtime(true) < $deadline);
    }

    private function start(): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store');
        set_time_limit(self::TIME_LIMIT_SECONDS);
        echo "retry: 1000\nevent: ready\ndata: {}\n\n";
    }

    /**
     * @param LogBatch $batch
     * @throws JsonException
     */
    private function writeBatch(array $batch): void
    {
        foreach ($batch['entries'] as $entry) {
            $this->writeEntry($entry);
        }

        echo ": heartbeat\n\n";
    }

    /**
     * @param LogEntry $entry
     * @throws JsonException
     */
    private function writeEntry(array $entry): void
    {
        $json = json_encode($entry['record'], JSON_THROW_ON_ERROR);
        echo 'id: ' . $entry['id'] . "\nevent: log\ndata: " . $json . "\n\n";
    }
}
