<?php

declare(strict_types=1);

namespace Tests\Unit\Scheduler;

use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Exception\ConfigurationException;
use App\Scheduler\Infrastructure\Configuration\JsonConfigurationParser;
use App\Task\Execution\PingTask;
use JsonException;
use LogicException;
use Nette\Schema\ValidationException;
use stdClass;
use Tests\Support\SchedulerFixtures;

final readonly class LockingConfigurationParserTest
{
    private const array EXPECTED_FIXTURE_OPTIONS = [
        'defaults' => [false, 60, false],
        'overlap-default-expiry' => [true, 60, false],
        'single-server' => [false, 60, true],
        'combined-custom-expiry' => [true, 30, true],
        'explicit-disabled' => [false, 60, false],
        'expiry-only' => [false, 120, false],
        'minimum-expiry' => [true, 1, false],
    ];

    /**
     * @throws ConfigurationException
     * @throws JsonException
     */
    public function run(): void
    {
        $this->parsesLockingFixture();
        $this->defaultsToUnrestrictedExecution();
        $this->acceptsIndependentLockOptions();
        $this->rejectsInvalidLockOptions();
    }

    /**
     * @throws ConfigurationException
     */
    private function parsesLockingFixture(): void
    {
        $configuration = new JsonConfigurationParser()->parse(SchedulerFixtures::read('locking.json'));
        $ids = array_map(static fn(ScheduleDefinition $definition): string => $definition->id, $configuration->tasks);
        if ($configuration->version !== 1 || $ids !== array_keys(self::EXPECTED_FIXTURE_OPTIONS)) {
            throw new LogicException('Locking fixtures must retain the format version and all schedules in order.');
        }

        foreach ($configuration->tasks as $definition) {
            $this->assertFixtureDefinition($definition);
        }
    }

    private function assertFixtureDefinition(ScheduleDefinition $definition): void
    {
        $actual = [$definition->withoutOverlapping, $definition->overlapExpiresAfterMinutes, $definition->onOneServer];
        $expected = self::EXPECTED_FIXTURE_OPTIONS[$definition->id] ?? null;
        if (
            $actual !== $expected || $definition->task !== PingTask::class
            || $definition->cron !== '* * * * *' || $definition->timezone !== 'UTC'
        ) {
            throw new LogicException('Each JSON schedule must preserve its options and defaults: ' . $definition->id);
        }
    }

    /**
     * @throws ConfigurationException
     * @throws JsonException
     */
    private function defaultsToUnrestrictedExecution(): void
    {
        $definition = new JsonConfigurationParser()->parse($this->json([]))->tasks[0]
            ?? throw new LogicException('Parsed configuration must contain its task.');
        if (
            $definition->withoutOverlapping || $definition->onOneServer
            || $definition->overlapExpiresAfterMinutes !== 60
        ) {
            throw new LogicException('Existing JSON must retain unrestricted execution and a 60-minute lock expiry.');
        }
    }

    /**
     * @throws ConfigurationException
     * @throws JsonException
     */
    private function acceptsIndependentLockOptions(): void
    {
        foreach ([false, true] as $withoutOverlapping) {
            foreach ([false, true] as $onOneServer) {
                $this->assertLockOptions($withoutOverlapping, $onOneServer);
            }
        }
    }

    /**
     * @throws ConfigurationException
     * @throws JsonException
     */
    private function assertLockOptions(bool $withoutOverlapping, bool $onOneServer): void
    {
        foreach ([1, 30, 60, 1440] as $minutes) {
            $json = $this->json([
                'withoutOverlapping' => $withoutOverlapping,
                'onOneServer' => $onOneServer,
                'overlapExpiresAfterMinutes' => $minutes,
            ]);
            $definition = new JsonConfigurationParser()->parse($json)->tasks[0]
                ?? throw new LogicException('Parsed configuration must contain its task.');
            if (
                $definition->withoutOverlapping !== $withoutOverlapping || $definition->onOneServer !== $onOneServer
                || $definition->overlapExpiresAfterMinutes !== $minutes
            ) {
                throw new LogicException('Lock flags and expiration must be preserved independently.');
            }
        }
    }

    /**
     * @throws JsonException
     */
    private function rejectsInvalidLockOptions(): void
    {
        foreach (['withoutOverlapping', 'onOneServer'] as $field) {
            foreach ([null, 0, 1, 'true', 'false', [], new stdClass()] as $invalid) {
                $this->assertInvalidOption($field, $invalid);
            }
        }

        foreach ([null, 0, -1, 1.0, 1.5, '30', true, false, [], new stdClass()] as $invalid) {
            $this->assertInvalidOption('overlapExpiresAfterMinutes', $invalid);
        }
    }

    /**
     * @throws JsonException
     */
    private function assertInvalidOption(string $field, mixed $invalid): void
    {
        try {
            new JsonConfigurationParser()->parse($this->json([
                $field => $invalid,
            ]));
        } catch (ConfigurationException $exception) {
            $this->assertFailurePath($exception, $field);

            return;
        }

        throw new LogicException('Invalid lock option must fail configuration loading: ' . $field);
    }

    private function assertFailurePath(ConfigurationException $exception, string $field): void
    {
        $cause = $exception->getPrevious();
        if (! $cause instanceof ValidationException) {
            throw new LogicException('Lock options must fail schema validation.', previous: $exception);
        }

        foreach ($cause->getMessageObjects() as $message) {
            if ($message->path === ['tasks', 0, $field]) {
                return;
            }
        }

        throw new LogicException('Lock option failures must identify their field.', previous: $exception);
    }

    /**
     * @param array<string, mixed> $options
     * @throws JsonException
     */
    private function json(array $options): string
    {
        return json_encode([
            'version' => 1,
            'tasks' => [array_merge([
                'id' => 'ping',
                'task' => PingTask::class,
                'cron' => '* * * * *',
            ], $options)],
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
