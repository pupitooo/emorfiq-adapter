<?php

declare(strict_types=1);

namespace DevTools\AdapterUi;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Builds SQL predicates exclusively from the tooling's fixed column allowlist.
 */
final readonly class HistoryFilters
{
    /**
     * @param array<array-key, mixed> $input
     */
    public function __construct(
        private array $input
    ) {}

    /**
     * @return list<list<string|bool>>
     */
    public function conditions(): array
    {
        $conditions = [];
        foreach ($this->predicates() as $key => $sql) {
            $value = $this->value($key);
            if ($value === '') {
                continue;
            }

            $conditions[] = $this->condition($key, $sql, $value);
        }
        $this->validateBounds();

        return $conditions;
    }

    private function validateBounds(): void
    {
        if ($this->value('scheduled_from') !== '' && $this->value('scheduled_to') !== ''
            && $this->value('scheduled_from') > $this->value('scheduled_to')) {
            throw new InvalidArgumentException('Začátek intervalu musí být před jeho koncem.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function predicates(): array
    {
        return [
            'id' => 'id = %i',
            'schedule_id' => 'schedule_id ILIKE %~like~',
            'task_class' => 'task_class ILIKE %~like~',
            'status' => 'status = %s',
            'scheduled_from' => 'scheduled_for >= %s::timestamptz',
            'scheduled_to' => 'scheduled_for <= %s::timestamptz',
            'started_on' => "started_at >= %s::timestamp AT TIME ZONE 'UTC' AND "
                . "started_at < (%s::timestamp + interval '1 day') AT TIME ZONE 'UTC'",
            'finished_on' => "finished_at >= %s::timestamp AT TIME ZONE 'UTC' AND "
                . "finished_at < (%s::timestamp + interval '1 day') AT TIME ZONE 'UTC'",
            'without_overlapping' => 'without_overlapping = %b',
            'on_one_server' => 'on_one_server = %b',
        ];
    }

    /**
     * @return list<string|bool>
     */
    private function condition(string $key, string $sql, string $value): array
    {
        $parameter = $this->parameter($key, $value);

        return in_array($key, ['started_on', 'finished_on'], true)
            ? [$sql, $parameter, $parameter]
            : [$sql, $parameter];
    }

    private function parameter(string $key, string $value): string|bool
    {
        return match ($key) {
            'id' => $this->runId($value),
            'schedule_id', 'task_class' => $value,
            'status' => $this->choice($value, ['in_progress', 'succeeded', 'failed', 'abandoned']),
            'scheduled_from', 'scheduled_to' => $this->date($value, 'Y-m-d\\TH:i') . ':00+00:00',
            'started_on', 'finished_on' => $this->date($value, 'Y-m-d'),
            'without_overlapping', 'on_one_server' => $this->choice($value, ['true', 'false']) === 'true',
            default => throw new InvalidArgumentException('Neznámý filtr.'),
        };
    }

    private function runId(string $value): string
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1
            || (strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0)) {
            throw new InvalidArgumentException('ID běhu musí být kladné celé číslo v rozsahu bigint.');
        }

        return $value;
    }

    /**
     * @param list<string> $options
     */
    private function choice(string $value, array $options): string
    {
        if (! in_array($value, $options, true)) {
            throw new InvalidArgumentException('Filtr obsahuje nepodporovanou hodnotu.');
        }

        return $value;
    }

    private function date(string $value, string $format): string
    {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format($format) !== $value) {
            throw new InvalidArgumentException('Filtr obsahuje neplatné datum nebo čas.');
        }

        return $value;
    }

    public function value(string $key): string
    {
        $value = $this->input[$key] ?? '';
        if (! is_string($value) || strlen($value) > 500) {
            throw new InvalidArgumentException('Filtr má neplatný formát nebo je příliš dlouhý.');
        }

        return trim($value);
    }
}
