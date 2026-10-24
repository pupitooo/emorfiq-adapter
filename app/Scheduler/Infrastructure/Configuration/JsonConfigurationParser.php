<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Configuration;

use App\Scheduler\Domain\Configuration;
use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Exception\ConfigurationException;
use DateInvalidTimeZoneException;
use DateTimeZone;
use JsonException;
use stdClass;

final readonly class JsonConfigurationParser
{
    private const string FIVE_FIELD_CRON_PATTERN = '/^\S+(?:\s+\S+){4}$/D';

    private const int REGEX_MATCH_FOUND = 1;

    /**
     * @throws ConfigurationException
     */
    public function parse(string $json): Configuration
    {
        $data = $this->decode($json);
        $version = $data->version ?? null;
        if ($version !== Configuration::FORMAT_VERSION_1) {
            throw new ConfigurationException(sprintf(
                'Configuration version must be the integer %d.',
                Configuration::FORMAT_VERSION_1,
            ));
        }

        $tasks = $data->tasks ?? null;
        if (! is_array($tasks) || ! array_is_list($tasks)) {
            throw new ConfigurationException('Configuration tasks must be a JSON array.');
        }

        return new Configuration($version, $this->parseTasks($tasks));
    }

    /**
     * @throws ConfigurationException
     */
    private function decode(string $json): stdClass
    {
        try {
            $data = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ConfigurationException(
                'Invalid scheduler JSON: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        if (! $data instanceof stdClass) {
            throw new ConfigurationException('Scheduler configuration must be a JSON object.');
        }

        return $data;
    }

    /**
     * @param list<mixed> $tasks
     * @return list<ScheduleDefinition>
     * @throws ConfigurationException
     */
    private function parseTasks(array $tasks): array
    {
        $definitions = [];
        $identifiers = [];
        foreach ($tasks as $index => $task) {
            $definition = $this->parseTask($task, 'tasks[' . $index . ']');
            if (isset($identifiers[$definition->id])) {
                throw new ConfigurationException('Duplicate schedule identifier: ' . $definition->id);
            }

            $identifiers[$definition->id] = true;
            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * @throws ConfigurationException
     */
    private function parseTask(mixed $task, string $path): ScheduleDefinition
    {
        if (! $task instanceof stdClass) {
            throw new ConfigurationException($path . ' must be a JSON object.');
        }

        $cron = $this->readString($task->cron ?? null, $path . '.cron');
        $this->validateCronFields($cron, $path);
        $timezone = property_exists($task, 'timezone')
            ? $this->readString($task->timezone, $path . '.timezone')
            : ScheduleDefinition::DEFAULT_TIMEZONE;
        $this->validateTimezone($timezone, $path);

        return new ScheduleDefinition(
            id: $this->readString($task->id ?? null, $path . '.id'),
            task: $this->readString($task->task ?? null, $path . '.task'),
            cron: $cron,
            timezone: $timezone,
        );
    }

    /**
     * @throws ConfigurationException
     */
    private function readString(mixed $value, string $path): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new ConfigurationException($path . ' must be a non-empty string.');
        }

        return $value;
    }

    /**
     * @throws ConfigurationException
     */
    private function validateCronFields(string $cron, string $path): void
    {
        if (preg_match(self::FIVE_FIELD_CRON_PATTERN, trim($cron)) !== self::REGEX_MATCH_FOUND) {
            throw new ConfigurationException($path . '.cron must contain exactly five fields.');
        }
    }

    /**
     * @throws ConfigurationException
     */
    private function validateTimezone(string $timezone, string $path): void
    {
        try {
            new DateTimeZone($timezone);
        } catch (DateInvalidTimeZoneException $exception) {
            throw new ConfigurationException($path . '.timezone is invalid: ' . $timezone, previous: $exception);
        }
    }
}
