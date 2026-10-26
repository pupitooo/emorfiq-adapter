# Coordination contract

[Layers and lifecycle](../mutex/layers.md) explains who calls each operation;
[Design choices](../mutex/design.md) explains why coordination works this way.
This page defines what each `ExecutionMutex` result guarantees and what its
caller must do next:

- **Permission to execute:** when a task may start or must be skipped, and how
  callers handle storage failures and uncertain commits.
- **Atomic changes:** which history and lock changes commit together, including
  cleanup after a conflict.
- **Completion and recovery:** which state changes are allowed and how they
  preserve a replacement run's protection.

Use this contract when implementing an adapter or storage backend, reviewing
lifecycle changes, or writing tests for their expected behavior. Implementations
must preserve these guarantees. See [PostgreSQL storage](storage.md) for the SQL
mechanism that currently provides them.

## Terms and identity

| Term | Meaning |
| --- | --- |
| Schedule | A configured task entry identified by `schedule_id`. Different IDs remain independent even when they resolve the same task class. |
| Occurrence | A schedule at one intended execution time: `(schedule_id, scheduled_for)`. All scheduler processes use the same intended UTC minute, rather than their individual start times. |
| Run | One accepted execution attempt, recorded before task resolution. Its history does not prove that task code actually started. |
| Claim | A confirmed reservation returned as `RunClaim`, containing a fresh run ID and an unpredictable owner token. Completion must identify this original run and owner. |
| Occurrence lock | Protection against another run of the same occurrence, requested by `onOneServer`. Completion and recovery retain it. |
| Overlap lock | Protection against another run of the same schedule, requested by `withoutOverlapping`. Completion or expiry recovery releases it. |

All participating scheduler processes must use consistent schedule IDs and
concurrency options and share authoritative coordination state. See
[Concurrency](../configuration/concurrency.md) for choosing and configuring the
protections, and [History and locks](history.md) for persistence and retention.

## State lookups

| Method | What `true` means |
| --- | --- |
| `isOverlapLocked()` | The schedule has an overlap lock whose deadline has not passed. Expired locks return `false`. |
| `isOccurrenceReserved()` | The occurrence still has a reservation, including after its run has completed. |

Lookups describe current state; another process can acquire a lock immediately
afterwards. **Only a confirmed `RunClaim` from `claim()` authorizes task
resolution and invocation.** A lookup failure raises `MutexStorageException`;
it must not be treated as an unlocked state.

## Reservation: claim()

Reservation performs three steps in one short transaction:

1. Recover expired overlap locks for the schedule.
2. Record a new run with its configuration snapshot.
3. Acquire all requested protections.

With both protections enabled, one claim must acquire both locks. With both
disabled, reservation must still record a fresh run, without lock rows.

| Result | Guarantee | Required caller behavior |
| --- | --- | --- |
| `RunClaim` | The reservation is committed and its transaction is closed. | Task resolution and `Task::run()` may proceed. Use this claim when reporting completion. |
| `null` | A lock conflict prevented reservation. No new run or partial locks remain; recovery of older expired locks still commits. | Skip this invocation without resolving the task. No work is queued and no failed run is created. |
| `MutexStorageException` | A storage operation failed; no confirmed claim is available. | Propagate the infrastructure failure without resolving or invoking the task. |
| `ReservationUnconfirmedException` | Commit could not be confirmed; the reservation may already exist. | Propagate the uncertainty without resolving or invoking the task, and do not blindly retry the claim. |

A rollback leaves no new run or locks. An unconfirmed commit may have persisted
both: a lost acknowledgement does not prove rollback. The caller must stop
without invoking the task or blindly repeating the claim, accepting that this
execution may be missed.

A confirmed reservation grants permission to execute. A process crash before
invocation can still prevent the task from starting.

No coordination transaction may remain open while `Task::run()` executes.

## Completion: finish()

Completion receives the original claim and the task's reported outcome. It must:

- Match both the run ID and owner token.
- Record success or failure and the completion time for that run.
- Remove only that run's overlap lock in the same transaction.
- Retain its occurrence lock.

An expired lock or `abandoned` status does not by itself reject completion.
A late result may update the original run. It must preserve recovery details,
leave a replacement run and its lock unchanged, and never restore the old lock.

| Result | Meaning |
| --- | --- |
| `true` | The outcome and overlap-lock release are committed. |
| `false` | Completion was rejected because the run is missing, the owner does not match, or a final result is already recorded. No state changes are made. |
| `MutexStorageException` | Completion could not be confirmed. The caller must expose the infrastructure failure separately from the task's reported outcome. |

Repeated completion is rejected even when it repeats the same outcome. A
confirmed result and its completion time must not be overwritten.

## Recovery: recover()

Recovery handles expired overlap locks for one schedule in one transaction:

- Remove the expired locks and mark linked `in_progress` runs `abandoned`.
- Record detection time and the recovering server's
  [identity](../development/server-identity.md), preserving any existing recovery details.
- Leave completion time empty, occurrence locks intact, and other schedules unchanged.

| Result | Meaning |
| --- | --- |
| Positive integer | Number of overlap locks removed, including locks whose history was deleted. |
| `0` | No overlap lock was removed. |
| `MutexStorageException` | Recovery could not be confirmed. Do not treat this as zero recovered locks. |

`claim()` includes recovery in its reservation transaction. Calling `recover()`
separately does not replace that requirement.

The overlap deadline is fixed at reservation and is not renewed:

- Protection is live while `now < expires_at`; equality means expired.
- Expiry must use an authoritative shared clock and be checked after any relevant
  wait. A time captured before waiting could miss a lock that expired during it.
- Time passing alone does not delete a lock or change history. Recovery performs
  those changes when called directly or during reservation.

`abandoned` means recovery found expired protection without a reported result.
It does not establish success, failure, or process termination. Recovery neither
stops nor retries the task. See
[Recovery scenarios](../failure-and-recovery/recovery.md) for crash windows and
[External effects](../failure-and-recovery/external-effects.md) for the limits of protection.

## Allowed state transitions

The following rules apply to completion with a matching run ID and owner token.
`in_progress` means no completion or abandonment has been recorded;
`succeeded` and `failed` are final reported outcomes.

| Current state | Event | Required result |
| --- | --- | --- |
| `in_progress` | Original task reports success or failure, before or after expiry | Set `succeeded` or `failed` and `finished_at`; retain a failure diagnostic when applicable. |
| `in_progress` with an expired overlap lock | Recovery | Set `abandoned`, `abandoned_at`, and `abandoned_by`; leave `finished_at` empty. |
| `abandoned` | Original task reports success or failure | Set the reported outcome and `finished_at`; preserve both abandonment fields. |
| `succeeded` or `failed` | Any later completion or recovery | Preserve the confirmed outcome and timestamps; reject repeated completion. |

`finished_at` records reported completion; `abandoned_at` and `abandoned_by`
record recovery. Preserve both sets of fields when a late result arrives.
No transition may restore `in_progress`.

Concurrent completion and recovery must apply these transitions one at a time:

- **Completion first:** the overlap lock is removed, so recovery cannot abandon that run.
- **Recovery first:** late completion records the result and preserves the recovery details.

Neither order may affect a replacement run or its protection.
