<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use App\Task\Contract\Task;
use App\Task\Contract\TaskResolver;
use LogicException;
use Override;

final readonly class RecoveryTaskResolver implements TaskResolver
{
    public function __construct(
        private \PDO $owner,
        private RecoveryProbe $probe,
        private string $schedule,
        private ExecutionFault $fault,
        private bool $longTask,
    ) {}

    #[Override]
    public function resolve(string $taskClass): Task
    {
        try {
            $this->observe($taskClass);
            if ($this->fault === ExecutionFault::CrashBeforeTask) {
                RecoveryChannel::pause('crash');
            }
        } catch (\PDOException|\JsonException $failure) {
            throw new LogicException('Recovery resolver probe failed.', previous: $failure);
        }
        return new RecoveryTask($this->owner, $this->probe, $this->fault, $this->longTask);
    }

    /**
     * @throws \PDOException
     */
    private function observe(string $taskClass): void
    {
        RecoveryAssertions::same(RecoveryTask::class, $taskClass);
        $this->probe->increment('resolutions');
        // Independent autocommit session must see the reservation before task construction.
        if ($this->probe->change(<<<'SQL'
            INSERT INTO recovery_probes (name, value)
            SELECT 'committed', 1 WHERE EXISTS (
                SELECT 1 FROM task_runs WHERE schedule_id = ? AND status = 'in_progress'
            ) ON CONFLICT (name) DO UPDATE SET value = recovery_probes.value + 1
            SQL
            , [$this->schedule])) {
            return;
        }
        throw new LogicException('Task resolution entered without a committed reservation.');
    }
}
