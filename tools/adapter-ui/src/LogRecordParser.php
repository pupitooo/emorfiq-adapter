<?php

declare(strict_types=1);

namespace DevTools\AdapterUi;

/**
 * @phpstan-type LogRecord array{
 *     datetime: string, level_name: string, message: string,
 *     channel: string|null, context: array<mixed>, extra: array<mixed>
 * }
 */
final readonly class LogRecordParser
{
    /**
     * @return LogRecord|null
     */
    public function parse(string $line): ?array
    {
        $record = json_decode($line, true);
        if (! $this->isRecord($record)) {
            return null;
        }

        return [
            'datetime' => $record['datetime'],
            'level_name' => $record['level_name'],
            'message' => $record['message'],
            'channel' => is_string($record['channel'] ?? null) ? $record['channel'] : null,
            'context' => is_array($record['context'] ?? null) ? $record['context'] : [],
            'extra' => is_array($record['extra'] ?? null) ? $record['extra'] : [],
        ];
    }

    /**
     * @phpstan-assert-if-true array{datetime: string, level_name: string, message: string, ...} $record
     */
    private function isRecord(mixed $record): bool
    {
        return is_array($record) && is_string($record['datetime'] ?? null)
            && is_string($record['level_name'] ?? null) && is_string($record['message'] ?? null);
    }
}
