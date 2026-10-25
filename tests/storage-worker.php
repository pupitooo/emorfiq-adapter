<?php

declare(strict_types=1);

use App\Database\PostgresConnectionFactory;
use App\Scheduler\Domain\RunClaim;
use App\Scheduler\Domain\RunOutcome;
use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Infrastructure\Database\PostgresTaskRunStorage;
use Tests\Support\Storage\BarrierConnection;
use Tests\Support\Storage\StorageDatabase;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $line = fgets(STDIN);
    if ($line === false) {
        throw new LogicException('Missing worker input.');
    }
    $input = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($input) || ! is_string($input['schema'] ?? null) || preg_match(
        '/^storage_test_[a-f0-9]+$/D',
        $input['schema']
    ) !== 1) {
        throw new LogicException('Invalid worker schema.');
    }
    $connection = StorageDatabase::connect(($input['barrier'] ?? 0) === 1 ? BarrierConnection::class : PDO::class);
    $connection->exec('SET search_path TO ' . $input['schema'] . ', pg_catalog');
    $connection->exec("SET statement_timeout = '10s'");
    $statement = $connection->query('SELECT pg_backend_pid()');
    if ($statement === false) {
        throw new LogicException('Missing backend PID.');
    }
    $pid = $statement->fetchColumn();
    if (! is_int($pid)) {
        throw new LogicException('Invalid backend PID.');
    }
    echo $pid . PHP_EOL;
    flush();
    $storage = new PostgresTaskRunStorage(PostgresConnectionFactory::wrap($connection));
    $id = $input['id'] ?? 1;
    $token = $input['token'] ?? '11111111-1111-4111-8111-111111111111';
    if (! is_int($id) || ! is_string($token)) {
        throw new LogicException('Invalid worker claim.');
    }
    $result = match ($input['operation'] ?? null) {
        'claim' => $storage->reserve(
            new ScheduleDefinition(
                'sync',
                'Task',
                '* * * * *',
                withoutOverlapping: true,
                overlapExpiresAfterMinutes: 5,
                onOneServer: true
            ),
            new DateTimeImmutable('2026-10-26 10:01:00 UTC'),
            'claim-worker'
        )?->id,
        'recover' => $storage->abandonExpired('sync', 'recovery-worker'),
        'complete' => $storage->complete(
            new RunClaim($id, $token),
            RunOutcome::Succeeded,
            null
        ),
        default => throw new LogicException('Unknown worker operation.'),
    };
    echo json_encode([
        'result' => $result,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $failure) {
    fwrite(STDERR, $failure::class . ': ' . $failure->getMessage());
    exit(1);
}
