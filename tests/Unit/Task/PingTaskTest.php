<?php

declare(strict_types=1);

namespace Tests\Unit\Task;

use App\Task\Execution\PingTask;
use LogicException;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;

final readonly class PingTaskTest
{
    public function run(): void
    {
        $handler = new TestHandler();
        $task = new PingTask(new Logger('test', [$handler]));

        $task->run();

        $expected = [
            'message' => 'ping',
            'context' => [
                'task' => 'ping',
            ],
        ];
        if (count($handler->getRecords()) !== 1 || ! $handler->hasRecord($expected, Level::Info)) {
            throw new LogicException('PingTask must log exactly one INFO ping with its task name.');
        }
    }
}
