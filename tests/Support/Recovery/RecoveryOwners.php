<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use App\Scheduler\Domain\RunClaim;
use App\Scheduler\Domain\RunOutcome;
use LogicException;
use Tests\Support\Storage\StorageDatabase;

final class RecoveryOwners
{
    /**
     * @var array<int, RecoveryProcess>
     */
    private array $owners = [];

    /**
     * @var array<int, RecoveryProcess>
     */
    private array $requests = [];

    public function __construct(
        private readonly StorageDatabase $database,
        private readonly RecoveryProcesses $processes,
    ) {}

    /**
     * @throws \Exception
     */
    public function claim(string $schedule, int $minute, bool $onOneServer, bool $withoutOverlapping): ?Claim
    {
        $worker = $this->processes->start([
            'operation' => 'owner',
            'schedule' => $schedule,
            'minute' => $minute,
            'single' => $onOneServer,
            'overlap' => $withoutOverlapping,
        ]);
        $id = $worker->await('claimed');
        if ($id === null) {
            RecoveryAssertions::same(null, $worker->result());
            return null;
        }
        $token = $worker->await('token');
        if (! is_int($id) || ! is_string($token)) {
            throw new LogicException('Invalid owner claim.');
        }
        $this->owners[$id] = $worker;
        return new Claim($id, $token);
    }

    /**
     * @throws \Exception
     */
    public function finish(Claim $claim, bool $succeeded): bool
    {
        $worker = $this->owners[$claim->id] ?? null;
        if ($worker !== null) {
            $worker->command([
                'operation' => 'finish',
                'token' => $claim->ownerToken,
                'succeeded' => $succeeded,
            ]);
            return $worker->await('response') === true;
        }
        return RecoveryDatabase::coordinator($this->database->connection)->finish(
            new RunClaim($claim->id, $claim->ownerToken),
            $succeeded ? RunOutcome::Succeeded : RunOutcome::Failed,
            $succeeded ? null : 'Injected task failure.',
        );
    }

    /**
     * @throws \PDOException
     */
    public function disconnect(Claim $claim): void
    {
        $owner = $this->owners[$claim->id] ?? throw new LogicException('Missing claim owner.');
        $kill = $this->database->prepare('SELECT pg_terminate_backend(?)');
        $kill->execute([$owner->pid]);
        RecoveryAssertions::same(true, $kill->fetchColumn());
    }

    /**
     * @throws \JsonException
     */
    public function resumeEffect(Claim $claim): bool
    {
        $worker = $this->owners[$claim->id] ?? throw new LogicException('Missing paused effect worker.');
        $worker->command([
            'operation' => 'effect',
        ]);
        return $worker->await('response') === true;
    }

    /**
     * @throws \JsonException
     */
    public function submitEffect(Claim $claim): void
    {
        $worker = $this->processes->start([
            'operation' => 'request',
            'id' => $claim->id,
        ]);
        $worker->await('submitted');
        $this->requests[$claim->id] = $worker;
    }

    /**
     * @throws \JsonException
     */
    public function completeSubmittedEffect(Claim $claim): bool
    {
        $worker = $this->requests[$claim->id] ?? throw new LogicException('Missing submitted request.');
        $worker->release();
        unset($this->requests[$claim->id]);
        return $worker->result() === true;
    }

    public function close(): void
    {
        $this->owners = [];
        $this->requests = [];
    }
}
