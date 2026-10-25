<?php

declare(strict_types=1);

namespace App\Task\Infrastructure\Demo;

use Psr\Log\LoggerInterface;

/**
 * Simulates long-running e-commerce work for manual scheduler testing.
 */
final readonly class TaskSimulation
{
    private const int STEPS = 7;

    private const int STEP_SECONDS = 30;

    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function run(string $scheduleId, string $operation): void
    {
        for ($step = 1; $step <= self::STEPS; $step++) {
            $this->logger->info('Demo: ' . $operation, [
                'event' => 'demo.task.progress',
                'schedule_id' => $scheduleId,
                'step' => $step,
                'total_steps' => self::STEPS,
                'duration_seconds' => self::STEPS * self::STEP_SECONDS,
            ]);
            sleep(self::STEP_SECONDS);
        }
    }
}
