<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use App\Scheduler\Application\ExecutionCoordinator;
use App\Scheduler\Domain\RunClaim;
use App\Scheduler\Domain\RunOutcome;
use App\Scheduler\Exception\MutexStorageException;
use LogicException;
use PDO;

/**
 * Keeps the claiming process alive, including when its scheduler backend is disconnected.
 */
final readonly class RecoveryOwnerWorker
{
    /**
     * @throws \Exception
     */
    public static function run(RecoveryInput $input, PDO $connection, RecoveryProbe $probe): void
    {
        $coordinator = RecoveryDatabase::coordinator($connection);
        $claim = $coordinator->claim(
            RecoveryDatabase::definition(
                $input->string('schedule'),
                $input->bool('single'),
                $input->bool('overlap')
            ),
            RecoveryDatabase::instant($input->int('minute')),
            'claim-worker'
        );
        RecoveryChannel::send('claimed', $claim?->id);
        if ($claim === null) {
            return;
        }
        RecoveryChannel::send('token', $claim->ownerToken);
        self::serve($coordinator, $claim->id, $probe);
    }

    /**
     * @throws \Exception
     */
    private static function serve(ExecutionCoordinator $coordinator, int $id, RecoveryProbe $probe): void
    {
        while (! feof(STDIN)) {
            $command = RecoveryInput::read();
            $result = match ($command->string('operation')) {
                'finish' => self::finish($coordinator, $id, $command),
                'effect' => self::effect($probe),
                default => throw new LogicException('Unknown owner command.'),
            };
            RecoveryChannel::send('response', $result);
        }
    }

    private static function finish(ExecutionCoordinator $coordinator, int $id, RecoveryInput $command): bool
    {
        $succeeded = $command->bool('succeeded');
        try {
            return $coordinator->finish(
                new RunClaim($id, $command->string('token')),
                $succeeded ? RunOutcome::Succeeded : RunOutcome::Failed,
                $succeeded ? null : 'Injected task failure.',
            );
        } catch (MutexStorageException) {
            return false;
        }
    }

    /**
     * @throws \PDOException
     */
    private static function effect(RecoveryProbe $probe): bool
    {
        $probe->effect();
        return true;
    }
}
