<?php

declare(strict_types=1);

namespace Tests\Unit\Scheduler;

use App\Scheduler\Exception\ConfigurationException;
use App\Scheduler\Infrastructure\Configuration\JsonConfigurationParser;
use App\Task\Execution\PingTask;
use DateInvalidTimeZoneException;
use JsonException;
use LogicException;
use Nette\Schema\Message;
use Nette\Schema\ValidationException;
use Tests\Support\SchedulerFixtures;

final readonly class InvalidJsonConfigurationParserTest
{
    private const array VALID_TASK = [
        'id' => 'ping',
        'task' => PingTask::class,
        'cron' => '* * * * *',
        'timezone' => 'Europe/Prague',
    ];

    private const array EXPECTED_ERRORS = [
        'root-is-array.json' => 'Scheduler configuration must be a JSON object.',
        'tasks-is-object.json' => 'Configuration tasks must be a JSON array.',
        'tasks-is-null.json' => 'Configuration tasks must be a JSON array.',
        'task-is-string.json' => 'tasks[0] must be a JSON object.',
        'task-is-array.json' => 'tasks[0] must be a JSON object.',
        'task-is-null.json' => 'tasks[0] must be a JSON object.',
        'empty-id.json' => 'tasks[0].id must contain a non-whitespace character.',
        'unicode-whitespace-id.json' => 'tasks[0].id must contain a non-whitespace character.',
        'empty-task.json' => 'tasks[0].task must contain a non-whitespace character.',
        'empty-cron.json' => 'tasks[0].cron must contain a non-whitespace character.',
        'empty-timezone.json' => 'tasks[0].timezone must contain a non-whitespace character.',
        'surrounding-whitespace.json' => 'tasks[0].id must not contain surrounding whitespace.',
        'cron-has-seconds.json' => 'tasks[0].cron must be a valid five-field expression or supported cron alias.',
        'invalid-timezone.json' => 'tasks[0].timezone is invalid: Europe/Unknown',
        'duplicate-ids.json' => 'Duplicate schedule identifier: ping',
    ];

    private const array EXPECTED_SCHEMA_ERRORS = [
        'missing-version.json' => [
            'code' => Message::MissingItem,
            'path' => ['version'],
        ],
        'unsupported-version.json' => [
            'code' => Message::TypeMismatch,
            'path' => ['version'],
        ],
        'version-is-string.json' => [
            'code' => Message::TypeMismatch,
            'path' => ['version'],
        ],
        'version-is-float.json' => [
            'code' => Message::TypeMismatch,
            'path' => ['version'],
        ],
        'missing-tasks.json' => [
            'code' => Message::MissingItem,
            'path' => ['tasks'],
        ],
        'missing-id.json' => [
            'code' => Message::MissingItem,
            'path' => ['tasks', 0, 'id'],
        ],
        'missing-task.json' => [
            'code' => Message::MissingItem,
            'path' => ['tasks', 0, 'task'],
        ],
        'missing-cron.json' => [
            'code' => Message::MissingItem,
            'path' => ['tasks', 0, 'cron'],
        ],
        'task-is-number.json' => [
            'code' => Message::TypeMismatch,
            'path' => ['tasks', 0, 'task'],
        ],
        'null-timezone.json' => [
            'code' => Message::TypeMismatch,
            'path' => ['tasks', 0, 'timezone'],
        ],
        'unknown-root-field.json' => [
            'code' => Message::UnexpectedItem,
            'path' => ['enabled'],
        ],
        'unknown-task-field.json' => [
            'code' => Message::UnexpectedItem,
            'path' => ['tasks', 0, 'timeZone'],
        ],
    ];

    /**
     * @throws JsonException
     */
    public function run(): void
    {
        $this->rejectsInvalidJsonContainersAndRules();
        $this->rejectsInvalidSchemaValues();
        $this->rejectsSurroundingWhitespace();
        $this->reportsMultipleSchemaErrors();
        $this->malformedJsonPreservesOriginalCause();
        $this->invalidTimezonePreservesOriginalCause();
    }

    private function rejectsInvalidJsonContainersAndRules(): void
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
    }

    private function rejectsInvalidSchemaValues(): void
    {
        foreach (self::EXPECTED_SCHEMA_ERRORS as $fixture => $expected) {
            $this->assertSchemaError($this->schemaFailure($fixture), $expected);
        }
    }

    /**
     * @throws JsonException
     */
    private function rejectsSurroundingWhitespace(): void
    {
        foreach (self::VALID_TASK as $field => $value) {
            $this->assertSurroundingWhitespaceRejected($field, $value);
        }

        $this->assertSurroundingWhitespaceRejected('cron', '@daily');
    }

    /**
     * @throws JsonException
     */
    private function assertSurroundingWhitespaceRejected(string $field, string $value): void
    {
        foreach ([' ', "\t", "\r", "\n", "\u{00A0}", "\u{2003}"] as $whitespace) {
            foreach ([$whitespace . $value, $value . $whitespace] as $invalid) {
                $this->assertSurroundingWhitespaceFailure($field, $invalid);
            }
        }
    }

    /**
     * @throws JsonException
     */
    private function assertSurroundingWhitespaceFailure(string $field, string $value): void
    {
        $task = array_replace(self::VALID_TASK, [
            $field => $value,
        ]);
        $json = json_encode([
            'version' => 1,
            'tasks' => [$task],
        ], JSON_THROW_ON_ERROR);
        try {
            new JsonConfigurationParser()->parse($json);
        } catch (ConfigurationException $exception) {
            if ($exception->getMessage() !== 'tasks[0].' . $field . ' must not contain surrounding whitespace.') {
                throw new LogicException('Whitespace errors must identify their field.', previous: $exception);
            }

            return;
        }

        throw new LogicException('Surrounding whitespace must fail configuration loading: ' . $field);
    }

    private function reportsMultipleSchemaErrors(): void
    {
        $failure = $this->schemaFailure('multiple-invalid-fields.json');
        $this->assertSchemaError($failure, [
            'code' => Message::MissingItem,
            'path' => ['tasks', 0, 'id'],
        ]);
        $this->assertSchemaError($failure, [
            'code' => Message::TypeMismatch,
            'path' => ['tasks', 0, 'task'],
        ]);
    }

    private function schemaFailure(string $fixture): ValidationException
    {
        $failure = $this->parseFailure($fixture);
        $previous = $failure->getPrevious();
        if (! $previous instanceof ValidationException) {
            throw new LogicException(
                'Fixture "' . $fixture . '" must preserve its schema failure.',
                previous: $failure,
            );
        }

        if (! str_starts_with($failure->getMessage(), 'Invalid scheduler configuration: ')) {
            throw new LogicException('Schema errors must be reported as configuration failures.', previous: $failure);
        }

        return $previous;
    }

    /**
     * @param array{code: string, path: list<int|string>} $expected
     */
    private function assertSchemaError(ValidationException $failure, array $expected): void
    {
        foreach ($failure->getMessageObjects() as $message) {
            if ($message->code === $expected['code'] && $message->path === $expected['path']) {
                return;
            }
        }

        throw new LogicException(
            'The schema failure must identify the expected field and error code.',
            previous: $failure,
        );
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

    private function malformedJsonPreservesOriginalCause(): void
    {
        $failure = $this->parseFailure('malformed-json.json');
        if (! str_starts_with($failure->getMessage(), 'Invalid scheduler JSON: ')) {
            throw new LogicException('Malformed JSON must be reported as a JSON parsing failure.', previous: $failure);
        }

        if (! $failure->getPrevious() instanceof JsonException) {
            throw new LogicException('Malformed JSON must preserve the original JsonException.', previous: $failure);
        }
    }

    private function invalidTimezonePreservesOriginalCause(): void
    {
        $failure = $this->parseFailure('invalid-timezone.json');
        if (! $failure->getPrevious() instanceof DateInvalidTimeZoneException) {
            throw new LogicException('An invalid time zone must preserve its original exception.', previous: $failure);
        }
    }
}
