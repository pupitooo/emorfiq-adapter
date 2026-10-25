<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Database;

use App\Scheduler\Contract\TaskRunStorage;
use App\Scheduler\Domain\RunClaim;
use App\Scheduler\Domain\RunOutcome;
use App\Scheduler\Domain\ScheduleDefinition;
use App\Scheduler\Exception\MutexStorageException;
use DateTimeImmutable;
use Override;

/**
 * PostgreSQL storage placeholder; SQL, driver, and connection setup remain unimplemented.
 * Method pseudocode describes the intended implementation; all operations currently fail explicitly.
 */
final readonly class PostgresTaskRunStorage implements TaskRunStorage
{
    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter
     */
    #[Override]
    public function hasLiveOverlap(string $scheduleId): bool
    {
        // PSEUDOCODE: SELECT EXISTS for schedule_id, without_overlapping, status = in_progress,
        // and lease_expires_at > databaseClock(). Expired rows remain recoverable by reserve().
        throw new MutexStorageException('PostgreSQL overlap lookup is not implemented.');
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter
     */
    #[Override]
    public function hasOccurrence(string $scheduleId, DateTimeImmutable $scheduledFor): bool
    {
        // PSEUDOCODE: SELECT EXISTS for schedule_id, scheduled_for, and on_one_server, in any status.
        throw new MutexStorageException('PostgreSQL occurrence lookup is not implemented.');
    }

    /**
     * @throws MutexStorageException
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter
     */
    #[Override]
    public function reserve(
        ScheduleDefinition $definition,
        DateTimeImmutable $scheduledFor,
        string $claimedBy,
    ): ?RunClaim {
        // PSEUDOCODE; database helpers below do not exist yet:
        // BEGIN
        // abandonExpiredWithinTransaction(definition.id, claimedBy)
        //   lock candidate rows, then recheck their current state and database time
        // now = databaseClockAfterLockWaits()
        // deadline = definition.withoutOverlapping ? now + configuredMinutes : NULL
        // token = cryptographicallyRandomUuid()
        // row = insertRunSnapshotOnConflictDoNothing(definition, scheduledFor, token, now, deadline)
        //   indexes protect occurrences and active overlap; disabling both flags permits duplicates
        // COMMIT; return row == null ? null : RunClaim(row.id, token)
        //   return success or conflict only after commit acknowledgement
        // Failure before commit: roll back and throw MutexStorageException.
        // Unknown commit outcome: throw ReservationUnconfirmedException without automatic retry.
        throw new MutexStorageException('PostgreSQL task run reservation is not implemented.');
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter
     */
    #[Override]
    public function complete(RunClaim $claim, RunOutcome $outcome, ?string $error): bool
    {
        // PSEUDOCODE:
        // UPDATE task_runs SET status = outcome, finished_at = databaseClock(), error_message = error
        // WHERE id = claim.id AND owner_token = claim.ownerToken
        //   AND status IN ('in_progress', 'abandoned')
        // Preserve abandoned_at/by and the original deadline; expiry does not reject completion.
        // return confirmedAffectedRows == 1; throw MutexStorageException on database failure
        // Never update a replacement run or delete the historical occurrence reservation.
        throw new MutexStorageException('PostgreSQL task run completion is not implemented.');
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter
     */
    #[Override]
    public function abandonExpired(string $scheduleId, string $recoveredBy): int
    {
        // PSEUDOCODE:
        // BEGIN
        // count = abandonExpiredWithinTransaction(scheduleId, recoveredBy)
        //   lock candidate protected rows belonging to this schedule
        //   after waiting, recheck status == in_progress and deadline <= databaseClock()
        //   SET status = abandoned, abandoned_at = databaseClock(), abandoned_by = recoveredBy
        //   leave finished_at NULL and preserve any existing abandonment history
        // COMMIT; return count; roll back and throw MutexStorageException on failure
        // reserve() uses the same internal operation within its own transaction.
        throw new MutexStorageException('PostgreSQL task run recovery is not implemented.');
    }
}
