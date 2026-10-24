<?php

declare(strict_types=1);

namespace Tests\Unit\Scheduler;

use App\Scheduler\Exception\ConfigurationException;
use App\Scheduler\Infrastructure\Configuration\JsonConfigurationParser;
use App\Task\Execution\PingTask;
use JsonException;
use LogicException;

final readonly class CronConfigurationParserTest
{
    private const array VALID_EXPRESSIONS = [
        '0 0 1 1 0',
        '59 23 31 12 7',
        '0 9 * JAN,MAR MON-FRI',
        " \t*/5  9-17\t* * 1-5\n",
    ];

    private const array INVALID_EXPRESSIONS = [
        '',
        "\u{00A0}\u{2003}",
        '* * * *',
        '* * * * * 2026',
        '* * * * * * *',
        '-1 * * * *',
        '60 * * * *',
        '* 24 * * *',
        '* * 0 * *',
        '* * 32 * *',
        '* * * 0 *',
        '* * * 13 *',
        '* * * * 8',
        'invalid * * * *',
        '*/0 * * * *',
        '0-60 * * * *',
        '0,60 * * * *',
        '@yearly',
        '@annually',
        '@monthly',
        '@weekly',
        '@daily',
        '@midnight',
        '@hourly',
        " \t@DAILY\n",
    ];

    /**
     * @throws ConfigurationException
     * @throws JsonException
     */
    public function run(): void
    {
        $this->acceptsValidCronExpressions();
        $this->rejectsInvalidCronExpressions();
    }

    /**
     * @throws ConfigurationException
     * @throws JsonException
     */
    private function acceptsValidCronExpressions(): void
    {
        foreach (self::VALID_EXPRESSIONS as $expression) {
            $configuration = new JsonConfigurationParser()->parse($this->configurationJson($expression));
            $definition = $configuration->tasks[0] ?? null;
            if ($definition?->cron !== $expression) {
                throw new LogicException('Valid cron expressions must retain their original values: ' . $expression);
            }
        }
    }

    /**
     * @throws JsonException
     */
    private function rejectsInvalidCronExpressions(): void
    {
        foreach (self::INVALID_EXPRESSIONS as $expression) {
            $this->assertInvalidCronExpression($expression);
        }
    }

    /**
     * @throws JsonException
     */
    private function assertInvalidCronExpression(string $expression): void
    {
        $json = $this->configurationJson($expression);
        try {
            new JsonConfigurationParser()->parse($json);
        } catch (ConfigurationException $exception) {
            if ($exception->getMessage() !== 'tasks[0].cron must be a valid five-field expression without aliases.') {
                throw new LogicException('Invalid cron expressions must identify their field.', previous: $exception);
            }

            return;
        }

        throw new LogicException('Invalid cron expression must fail configuration loading: ' . $expression);
    }

    /**
     * @throws JsonException
     */
    private function configurationJson(string $expression): string
    {
        return json_encode([
            'version' => 1,
            'tasks' => [[
                'id' => 'cron-validation',
                'task' => PingTask::class,
                'cron' => $expression,
            ]],
        ], JSON_THROW_ON_ERROR);
    }
}
