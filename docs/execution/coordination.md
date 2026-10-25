# Execution coordination

This is the agreed contract for PostgreSQL execution coordination.
The schema, application coordination layer, Laravel runner integration, and
acceptance scenarios exist. PostgreSQL reservation, completion, and recovery
remain unimplemented. See [Mutex layers](mutex-layers.md) to follow the current
classes and [verification](verification.md) for database acceptance requirements.

## Execution lifecycle

The runner reserves an occurrence in a short transaction before resolving the
service or calling `Task::run()`. A confirmed reservation gets a fresh row ID and
unpredictable `owner_token`. The transaction must commit before task execution;
it must not remain open while the task runs.

The coordinator contract is independent of Laravel:

| Operation | Responsibility |
| --- | --- |
| Claim | Recover expired overlap locks for this schedule, then attempt a committed reservation. Distinguish a conflict from an infrastructure error or unknown commit outcome. |
| Finish | Record the original run's success or failure using its row ID and owner token, even after expiry or abandonment. |
| Recover | Mark expired protected `in_progress` rows as `abandoned`, recording detection time and the recovering process identity. |

Renewal, worker supervision, ownership-loss signals, and automatic task termination
are deferred extensions. They are not required by this first implementation.

```mermaid
sequenceDiagram
    participant R as Runner
    participant DB as PostgreSQL
    participant T as Task
    R->>DB: Recover expired overlap lock and insert reservation
    alt Commit confirmed
        DB-->>R: Run ID and owner token
        R->>T: Resolve and call Task::run()
        T-->>R: Return or throw
        R->>DB: Finish original run by ID and token
    else Conflict or unconfirmed reservation
        Note over R,T: Do not resolve or invoke task
    end
```

Conflicts skip the invocation; they do not queue work or create failed run rows.
A rollback leaves no reservation. A lost commit acknowledgement may leave a
committed row, so the runner must neither invoke the task nor blindly retry an
unconfirmed claim. This deliberately prefers a missed execution over a duplicate.

## Two independent protections

An occurrence is `(schedule_id, scheduled_for)`, where `scheduled_for` is the
intended UTC minute shared by scheduler processes, not each process's start time.
All instances must use consistent IDs and concurrency options. Different IDs
remain independent even if they resolve the same task class.

| Protection | Unique index | Lifetime |
| --- | --- | --- |
| `onOneServer` | `task_runs_occurrence`: `(schedule_id, scheduled_for)` where `on_one_server` | While the historical row exists, including after success, failure, or abandonment. |
| `withoutOverlapping` | `task_runs_active_schedule`: `schedule_id` where `without_overlapping AND status = 'in_progress'` | Until completion or explicit recovery changes the row's status. |

These partial indexes exclude rows without their respective option. Both flags
are disabled by default. Enabling both combines both guarantees.

- `onOneServer` selects one winner for an occurrence. A 10:01 run can start while
  10:00 is still running. It neither pins a schedule to one machine nor imposes
  an execution deadline. Its rows have `lease_expires_at = NULL` unless overlap
  protection is also enabled.
- `withoutOverlapping` skips subsequent invocations while the active reservation
  remains protected. Used alone, it permits the same occurrence again after
  completion. It therefore does not replace occurrence deduplication.
- Disabling both permits concurrent runs and duplicate occurrences.

The single-server reservation remains after quick completion, preventing a
second server from executing the same scheduled minute. This reserves an
occurrence; it does not guarantee exactly-once business effects.

## Fixed overlap expiration

`overlapExpiresAfterMinutes` is the fixed overlap-lock lifetime from reservation,
with the existing default of 60 minutes. It applies only with
`withoutOverlapping: true`; no second lease-duration option is introduced.
The deadline is stored in the existing `lease_expires_at` column despite that
column's historical name. It is never extended in this version.

Protection is valid while `now < lease_expires_at`; equality is expired.
Recovery before the next claim explicitly removes an expired row from the
active index by changing its status. Time passing alone does not change a row or
free the partial unique index.

Set expiration longer than the expected maximum execution time, with margin.
A long task can outlive its lock and overlap a new run. Expiration does not kill
the process, cancel requests, or determine whether the task succeeded.
See [long tasks](recovery.md#long-tasks-without-renewal).

Use an authoritative clock, preferably database time, for recovery decisions.
Evaluate time after any relevant row-lock wait, rather than relying on a stale
transaction-start timestamp. Contract tests use a controlled clock.

## Completion and retained recovery history

`status` represents the latest known execution outcome. Recovery is a provisional
observation, so `abandoned` can later become `succeeded` or `failed` when the
original process reports its result. It must never become `in_progress` again.

| Current state | Event | Result |
| --- | --- | --- |
| `in_progress`, with or without expired overlap lock | Original task returns | `succeeded`, set `finished_at` |
| `in_progress`, with or without expired overlap lock | Original task throws | `failed`, set `finished_at` and diagnostic |
| Protected `in_progress`, expired lock | Recovery | `abandoned`, set `abandoned_at` and `abandoned_by`; leave `finished_at` empty |
| `abandoned` | Original task returns or throws | Set actual result and `finished_at`; preserve both abandonment fields |
| `succeeded` or `failed` | Repeated or conflicting completion | Reject; preserve the confirmed result and its timestamp |
| Any state | Completion with wrong run ID or token | Reject without changing the row |

`finished_at` is exclusively the completion time reported by the original
process, not recovery detection time. `abandoned_by` identifies the recovering
process instance. Repeated recovery must not overwrite attribution or time.
No separate `succeeded_at`, `failed_at`, or combined overlap status is needed.

Completion checks row ID, token, and status in (`in_progress`, `abandoned`), not
unexpired ownership. It updates only that row. It must not delete a schedule-wide
lock, change the replacement run, or restore the old deadline. A zero-row update
means no result was recorded; infrastructure failures must remain distinguishable.

An abandonment timestamp alone is not proof of physical overlap: the original
worker may have crashed. Actual recorded run intervals can be compared where a
completion is known. Automatic overlap detection is not part of this change.
See [recovery](recovery.md) and [external effects](external-effects.md).
