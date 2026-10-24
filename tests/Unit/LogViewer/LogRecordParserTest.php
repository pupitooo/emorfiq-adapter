<?php

declare(strict_types=1);

namespace Tests\Unit\LogViewer;

use DevTools\LogViewer\LogRecordParser;
use JsonException;
use LogicException;

final readonly class LogRecordParserTest
{
    /**
     * @throws JsonException
     */
    public function run(): void
    {
        $this->preservesLifecycleContext();
        $this->acceptsExistingTaskRecords();
        $this->rejectsMalformedRecords();
    }

    /**
     * @throws JsonException
     */
    private function preservesLifecycleContext(): void
    {
        $context = [
            'task' => 'App\\Task\\Execution\\FailingTask',
            'schedule_id' => 'failing',
            'event' => 'scheduler.task.failed',
            'duration_ms' => 1.25,
            'exception' => [
                'class' => 'RuntimeException',
                'message' => '<failure>',
                'trace' => ['example.php:42'],
            ],
        ];
        $record = [
            'datetime' => '2026-10-24T12:00:00+00:00',
            'level_name' => 'ERROR',
            'message' => 'Scheduled task failed.',
            'context' => $context,
        ];
        if (new LogRecordParser()->parse(json_encode($record, JSON_THROW_ON_ERROR)) !== $record) {
            throw new LogicException('Viewer must preserve complete lifecycle and exception context.');
        }
    }

    private function acceptsExistingTaskRecords(): void
    {
        $line = '{"datetime":"2026-10-24T12:00:00+00:00","level_name":"INFO",'
            . '"message":"ping","context":{"task":"ping"}}';
        $record = new LogRecordParser()->parse($line);
        if ($record === null || $record['context']['task'] !== 'ping' || $record['message'] !== 'ping') {
            throw new LogicException('Viewer must still accept existing task messages.');
        }
    }

    private function rejectsMalformedRecords(): void
    {
        foreach (['{', 'null', '{}', '{"datetime":"now","level_name":"INFO","message":"ping","context":{}}'] as $line) {
            if (new LogRecordParser()->parse($line) !== null) {
                throw new LogicException('Viewer must reject malformed records and missing task metadata.');
            }
        }
    }
}
