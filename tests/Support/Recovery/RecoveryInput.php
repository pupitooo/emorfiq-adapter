<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use LogicException;

final readonly class RecoveryInput
{
    /**
     * @param array<string, int|string|bool> $values
     */
    public function __construct(
        private array $values
    ) {}

    /**
     * @throws \JsonException
     */
    public static function read(): self
    {
        $line = fgets(STDIN);
        $values = json_decode($line === false ? '' : $line, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($values)) {
            throw new LogicException('Invalid recovery worker input.');
        }
        $validated = [];
        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                throw new LogicException('Invalid recovery worker key.');
            }
            $validated[$key] = self::scalar($value);
        }
        return new self($validated);
    }

    private static function scalar(mixed $value): int|string|bool
    {
        if (is_string($value) || is_int($value) || is_bool($value)) {
            return $value;
        }
        throw new LogicException('Invalid recovery worker value.');
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? $default;
        return is_string($value) ? $value : throw new LogicException('Expected string: ' . $key);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? $default;
        return is_int($value) ? $value : throw new LogicException('Expected integer: ' . $key);
    }

    public function fault(): ExecutionFault
    {
        $name = $this->string('fault', 'None');
        foreach (ExecutionFault::cases() as $fault) {
            if ($fault->name === $name) {
                return $fault;
            }
        }
        throw new LogicException('Unknown execution fault: ' . $name);
    }

    public function bool(string $key): bool
    {
        $value = $this->values[$key] ?? false;
        return is_bool($value) ? $value : throw new LogicException('Expected boolean: ' . $key);
    }
}
