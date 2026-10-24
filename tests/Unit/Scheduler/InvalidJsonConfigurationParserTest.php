<?php

declare(strict_types=1);

namespace Tests\Unit\Scheduler;

use App\Scheduler\Exception\ConfigurationException;
use App\Scheduler\Infrastructure\Configuration\JsonConfigurationParser;
use DateInvalidTimeZoneException;
use JsonException;
use LogicException;
use Tests\Support\SchedulerFixtures;

final readonly class InvalidJsonConfigurationParserTest
{
    private const array EXPECTED_ERRORS = [
        'root-is-array.json' => 'Scheduler configuration must be a JSON object.',
        'missing-version.json' => 'Configuration version must be the integer 1.',
        'unsupported-version.json' => 'Configuration version must be the integer 1.',
        'version-is-string.json' => 'Configuration version must be the integer 1.',
        'missing-tasks.json' => 'Configuration tasks must be a JSON array.',
        'tasks-is-object.json' => 'Configuration tasks must be a JSON array.',
        'task-is-string.json' => 'tasks[0] must be a JSON object.',
        'missing-id.json' => 'tasks[0].id must be a non-empty string.',
        'missing-task.json' => 'tasks[0].task must be a non-empty string.',
        'missing-cron.json' => 'tasks[0].cron must be a non-empty string.',
        'empty-id.json' => 'tasks[0].id must be a non-empty string.',
        'task-is-number.json' => 'tasks[0].task must be a non-empty string.',
        'cron-has-seconds.json' => 'tasks[0].cron must contain exactly five fields.',
        'invalid-timezone.json' => 'tasks[0].timezone is invalid: Europe/Unknown',
        'null-timezone.json' => 'tasks[0].timezone must be a non-empty string.',
        'empty-timezone.json' => 'tasks[0].timezone must be a non-empty string.',
        'duplicate-ids.json' => 'Duplicate schedule identifier: ping',
    ];

    public function run(): void
    {
        foreach (self::EXPECTED_ERRORS as $fixture => $expectedMessage) {
            $failure = $this->parseFailure($fixture);
            if ($failure->getMessage() !== $expectedMessage) {
                throw new LogicException(sprintf(
                    'Fixture "%s": expected "%s", received "%s".',
                    $fixture,
                    $expectedMessage,
                    $failure->getMessage(),
                ), previous: $failure);
            }
        }

        $this->malformedJsonPreservesTheOriginalCause();
        $this->invalidTimezonePreservesTheOriginalCause();
    }

    private function parseFailure(string $fixture): ConfigurationException
    {
        $json = SchedulerFixtures::read('invalid/' . $fixture);
        try {
            new JsonConfigurationParser()->parse($json);
        } catch (ConfigurationException $exception) {
            return $exception;
        }

        throw new LogicException('Fixture "' . $fixture . '" must fail with ConfigurationException.');
    }

    private function malformedJsonPreservesTheOriginalCause(): void
    {
        $failure = $this->parseFailure('malformed-json.json');
        if (! str_starts_with($failure->getMessage(), 'Invalid scheduler JSON: ')) {
            throw new LogicException('Malformed JSON must be reported as a JSON parsing failure.', previous: $failure);
        }

        if (! $failure->getPrevious() instanceof JsonException) {
            throw new LogicException('Malformed JSON must preserve the original JsonException.', previous: $failure);
        }
    }

    private function invalidTimezonePreservesTheOriginalCause(): void
    {
        $failure = $this->parseFailure('invalid-timezone.json');
        if (! $failure->getPrevious() instanceof DateInvalidTimeZoneException) {
            throw new LogicException('An invalid time zone must preserve its original exception.', previous: $failure);
        }
    }
}
