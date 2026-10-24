<?php

declare(strict_types=1);

namespace DevTools\LogViewer;

/**
 * @phpstan-type LogContext array{task: string}
 * @phpstan-type LogRecord array{
 *     datetime: string,
 *     level_name: string,
 *     message: string,
 *     context: LogContext,
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
            'context' => [
                'task' => $record['context']['task'],
            ],
        ];
    }

    /**
     * @phpstan-assert-if-true LogRecord $record
     */
    private function isRecord(mixed $record): bool
    {
        return is_array($record)
            && is_string($record['datetime'] ?? null)
            && is_string($record['level_name'] ?? null)
            && is_string($record['message'] ?? null)
            && $this->isContext($record['context'] ?? null);
    }

    /**
     * @phpstan-assert-if-true LogContext $context
     */
    private function isContext(mixed $context): bool
    {
        return is_array($context) && is_string($context['task'] ?? null);
    }
}
