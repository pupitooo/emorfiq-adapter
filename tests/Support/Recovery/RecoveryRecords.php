<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use LogicException;
use Tests\Support\Storage\StorageDatabase;

final readonly class RecoveryRecords
{
    public function __construct(
        private StorageDatabase $database
    ) {}

    /**
     * @throws \PDOException
     */
    public function record(int $id): RunRecord
    {
        $row = $this->database->row($id);
        return new RunRecord(
            new Claim($this->integer($row, 'id'), $this->string($row, 'owner_token')),
            $this->string($row, 'status'),
            $this->seconds($row, 'lease_expires_at'),
            $this->seconds($row, 'finished_at'),
            $this->nullableString($row, 'error_message'),
            $this->seconds($row, 'abandoned_at'),
            $this->nullableString($row, 'abandoned_by'),
        );
    }

    /**
     * @return list<RunRecord>
     * @throws \PDOException
     */
    public function runs(string $schedule): array
    {
        $statement = $this->database->prepare('SELECT id FROM task_runs WHERE schedule_id = ? ORDER BY id');
        $statement->execute([$schedule]);
        $records = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            if (! is_int($id)) {
                throw new LogicException('Invalid stored run ID.');
            }
            $records[] = $this->record($id);
        }
        return $records;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        return is_int($value) ? $value : throw new LogicException('Invalid run integer: ' . $key);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function string(array $row, string $key): string
    {
        return $this->nullableString($row, $key) ?? throw new LogicException('Missing run field: ' . $key);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null || is_string($value)) {
            return $value;
        }
        throw new LogicException('Invalid run string: ' . $key);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function seconds(array $row, string $key): ?int
    {
        $value = $this->nullableString($row, $key);
        try {
            return $value === null ? null : new \DateTimeImmutable($value)->getTimestamp()
                - RecoveryDatabase::instant(0)->getTimestamp();
        } catch (\DateMalformedStringException $failure) {
            throw new LogicException('Invalid stored run time.', previous: $failure);
        }
    }
}
