<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use App\Task\Contract\Task;
use LogicException;
use Override;
use PDO;

final readonly class RecoveryTask implements Task
{
    public function __construct(
        private PDO $owner,
        private RecoveryProbe $probe,
        private ExecutionFault $fault,
        private bool $longTask,
    ) {}

    /**
     * @throws \PDOException
     * @throws \JsonException
     */
    #[Override]
    public function run(): void
    {
        $this->probe->increment('starts');
        if (! $this->owner->inTransaction()) {
            $this->probe->increment('transaction_closed');
        }
        if ($this->longTask) {
            RecoveryChannel::pause('task');
        }
        if ($this->fault === ExecutionFault::TaskException) {
            throw new LogicException('Injected task failure.');
        }
        $this->probe->effect();
        $this->pauseForCrash(ExecutionFault::CrashDuringTask);
        $this->probe->effect();
        $this->pauseForCrash(ExecutionFault::CrashAfterEffect);
        if ($this->fault !== ExecutionFault::CompletionUnavailable) {
            return;
        }

        RecoveryChannel::pause('disconnect');
    }

    /**
     * @throws \JsonException
     */
    private function pauseForCrash(ExecutionFault $fault): void
    {
        if ($this->fault !== $fault) {
            return;
        }

        RecoveryChannel::pause('crash');
    }
}
