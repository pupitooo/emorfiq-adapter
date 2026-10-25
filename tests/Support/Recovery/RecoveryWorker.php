<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use App\Scheduler\Domain\RunClaim;
use App\Scheduler\Domain\RunOutcome;
use LogicException;
use PDO;

final readonly class RecoveryWorker
{
    /**
     * @throws \Exception
     */
    public static function run(RecoveryInput $input): void
    {
        $schema = $input->string('schema');
        $connection = RecoveryDatabase::connect($schema, self::driver($input));
        $statement = $connection->query('SELECT pg_backend_pid()');
        if ($statement === false) {
            throw new LogicException('Unable to read worker backend PID.');
        }
        RecoveryChannel::send('ready', (int) $statement->fetchColumn());
        $probe = new RecoveryProbe(RecoveryDatabase::connect($schema));
        $result = self::operate($input, $connection, $probe);
        RecoveryChannel::send('result', $result);
    }

    /**
     * @return class-string<PDO>
     */
    private static function driver(RecoveryInput $input): string
    {
        if ($input->bool('barrier')) {
            return RecoveryCommitConnection::class;
        }
        return $input->fault() === ExecutionFault::ReservationAcknowledgementLost
            ? RecoveryFaultConnection::class : PDO::class;
    }

    /**
     * @throws \Exception
     */
    private static function operate(RecoveryInput $input, PDO $connection, RecoveryProbe $probe): int|string|bool|null
    {
        $coordinator = RecoveryDatabase::coordinator($connection);
        return match ($input->string('operation')) {
            'claim' => $coordinator->claim(
                RecoveryDatabase::definition($input->string('schedule')),
                RecoveryDatabase::instant($input->int('minute')),
                'claim-worker'
            )?->id,
            'recover' => $coordinator->recover($input->string('schedule'), 'recovery-worker'),
            'finish' => $coordinator->finish(
                new RunClaim($input->int('id'), $input->string('token')),
                RunOutcome::Succeeded
            ),
            'execute' => RecoveryRunner::run($input, $connection, $probe),
            'owner' => self::owner($input, $connection, $probe),
            'request' => self::request($input->int('id'), $probe),
            default => throw new LogicException('Unknown recovery worker operation.'),
        };
    }

    /**
     * @throws \Exception
     */
    private static function owner(RecoveryInput $input, PDO $connection, RecoveryProbe $probe): null
    {
        RecoveryOwnerWorker::run($input, $connection, $probe);
        return null;
    }

    /**
     * @throws \JsonException
     * @throws \PDOException
     */
    private static function request(int $id, RecoveryProbe $probe): bool
    {
        $probe->change('INSERT INTO receiver_requests (run_id) VALUES (?)', [$id]);
        RecoveryChannel::pause('submitted');
        return $probe->completeRequest($id);
    }
}
