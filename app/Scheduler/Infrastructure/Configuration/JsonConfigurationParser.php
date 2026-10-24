<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Configuration;

use App\Scheduler\Domain\Configuration;
use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Exception\ConfigurationException;
use DateInvalidTimeZoneException;
use DateTimeZone;
use JsonException;
use LogicException;
use Nette\Schema\Expect;
use Nette\Schema\Processor;
use Nette\Schema\Schema;
use Nette\Schema\ValidationException;
use Nette\Utils\Strings;
use stdClass;

final readonly class JsonConfigurationParser
{
    private const string CRON_FORMAT_PATTERN = '/^\S+(?:\s+\S+){4}$/D';

    private const int REGEX_MATCH_FOUND = 1;

    /**
     * @throws ConfigurationException
     */
    public function parse(string $json): Configuration
    {
        $data = $this->decode($json);
        $this->validateJsonContainers($data);
        $configuration = $this->mapConfiguration($data);
        $this->validateConfiguration($configuration);

        return $configuration;
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
     * @throws ConfigurationException
     */
    private function validateJsonContainers(stdClass $data): void
    {
        // Nette normalizes objects and arrays, and may treat null as an empty structure or list.
        if (! property_exists($data, 'tasks')) {
            return;
        }

        $tasks = $data->tasks;
        if (! is_array($tasks)) {
            throw new ConfigurationException('Configuration tasks must be a JSON array.');
        }

        foreach ($tasks as $index => $task) {
            if (! $task instanceof stdClass) {
                throw new ConfigurationException('tasks[' . $index . '] must be a JSON object.');
            }
        }
    }

    /**
     * @throws ConfigurationException
     */
    private function mapConfiguration(stdClass $data): Configuration
    {
        try {
            $configuration = new Processor()->process($this->configurationSchema(), $data);
        } catch (ValidationException $exception) {
            throw new ConfigurationException(
                'Invalid scheduler configuration: ' . implode(' ', $exception->getMessages()),
                previous: $exception,
            );
        }

        if (! $configuration instanceof Configuration) {
            throw new LogicException('The scheduler schema must map to Configuration.');
        }

        return $configuration;
    }

    private function configurationSchema(): Schema
    {
        return Expect::structure([
            'version' => Expect::anyOf(Configuration::FORMAT_VERSION)->required(),
            'tasks' => Expect::listOf($this->taskSchema())->required(),
        ])->castTo(Configuration::class);
    }

    private function taskSchema(): Schema
    {
        return Expect::structure([
            'id' => Expect::string()->required(),
            'task' => Expect::string()->required(),
            'cron' => Expect::string()->required(),
            'timezone' => Expect::string()->default(ScheduleDefinition::DEFAULT_TIMEZONE),
        ])->castTo(ScheduleDefinition::class);
    }

    /**
     * @throws ConfigurationException
     */
    private function validateConfiguration(Configuration $configuration): void
    {
        $identifiers = [];
        foreach ($configuration->tasks as $index => $definition) {
            $path = 'tasks[' . $index . ']';
            $this->validateNonEmptyFields($definition, $path);
            $this->validateCronFields($definition->cron, $path);
            $this->validateTimezone($definition->timezone, $path);
            if (isset($identifiers[$definition->id])) {
                throw new ConfigurationException('Duplicate schedule identifier: ' . $definition->id);
            }

            $identifiers[$definition->id] = true;
        }
    }

    /**
     * @throws ConfigurationException
     */
    private function validateNonEmptyFields(ScheduleDefinition $definition, string $path): void
    {
        $fields = [
            'id' => $definition->id,
            'task' => $definition->task,
            'cron' => $definition->cron,
            'timezone' => $definition->timezone,
        ];
        foreach ($fields as $field => $value) {
            if (Strings::trim($value) === '') {
                throw new ConfigurationException($path . '.' . $field . ' must contain a non-whitespace character.');
            }
        }
    }

    /**
     * @throws ConfigurationException
     */
    private function validateCronFields(string $cron, string $path): void
    {
        if (preg_match(self::CRON_FORMAT_PATTERN, trim($cron)) !== self::REGEX_MATCH_FOUND) {
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
