<?php

declare(strict_types=1);

namespace Tests\Support;

use LogicException;

/**
 * @phpstan-type Record array{message: string, level: int, context: array<mixed>}
 */
final readonly class LogRecords
{
    /**
     * @return list<Record>
     */
    public static function read(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new LogicException('Unable to read task execution log.');
        }

        return array_map(self::parse(...), $lines);
    }

    /**
     * @return list<string>
     */
    public static function taskMessages(string $path): array
    {
        $messages = [];
        foreach (self::read($path) as $record) {
            if (isset($record['context']['event'])) {
                continue;
            }

            $messages[] = $record['message'];
        }

        return $messages;
    }

    /**
     * @return Record
     */
    private static function parse(string $line): array
    {
        $record = json_decode($line, true);
        if (
            ! is_array($record) || ! is_string($record['message'] ?? null)
            || ! is_int($record['level'] ?? null) || ! is_array($record['context'] ?? null)
        ) {
            throw new LogicException('Tasks must produce valid JSON log records.');
        }

        return [
            'message' => $record['message'],
            'level' => $record['level'],
            'context' => $record['context'],
        ];
    }
}
