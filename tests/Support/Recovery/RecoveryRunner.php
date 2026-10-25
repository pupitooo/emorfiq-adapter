<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use App\Scheduler\Application\TaskExecutor;
use App\Scheduler\Exception\MutexStorageException;
use App\Scheduler\Exception\ReservationUnconfirmedException;
use Illuminate\Support\Facades\Date;
use PDO;
use Psr\Log\NullLogger;
use Tests\Support\SchedulerFixtures;

final readonly class RecoveryRunner
{
    /**
     * @throws \Exception
     */
    public static function run(RecoveryInput $input, PDO $owner, RecoveryProbe $probe): string
    {
        $fault = $input->fault();
        $schedule = $input->string('schedule');
        $adapter = SchedulerFixtures::adapter(
            new TaskExecutor(
                new RecoveryTaskResolver($owner, $probe, $schedule, $fault, $input->bool('long')),
                new NullLogger()
            ),
            SchedulerFixtures::configuration([RecoveryDatabase::definition($schedule)]),
            RecoveryDatabase::coordinator($owner),
        );
        return self::invoke($adapter, $input, $fault);
    }

    /**
     * @throws \Exception
     */
    private static function invoke(
        \App\Scheduler\Infrastructure\Laravel\LaravelAdapter $adapter,
        RecoveryInput $input,
        ExecutionFault $fault,
    ): string {
        Date::setTestNow(RecoveryDatabase::instant($input->int('minute')));
        try {
            $adapter->run();
            return 'completed';
        } catch (ReservationUnconfirmedException $failure) {
            RecoveryAssertions::same(ExecutionFault::ReservationAcknowledgementLost, $fault);
            return $failure::class;
        } catch (MutexStorageException $failure) {
            RecoveryAssertions::same(true, in_array($fault, [
                ExecutionFault::ReservationRollback, ExecutionFault::CompletionUnavailable,
            ], true));
            return $failure::class;
        } finally {
            Date::setTestNow();
        }
    }
}
