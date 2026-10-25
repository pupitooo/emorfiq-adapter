# Run history and locks

Every due execution admitted by the scheduler receives a fresh `task_runs` row
before task resolution, including executions with both concurrency protections
disabled. Skipped events do not create history. A committed row means execution
was admitted; a crash between commit and invocation can still prevent the task
from starting. See [Coordination](coordination.md) for the lifecycle.

## Separate responsibilities

`task_runs` retains the schedule snapshot, intended occurrence, start time,
completion result, diagnostic, and recovery attribution. Its protection flags and
`lease_expires_at` describe that execution; they do not enforce locks.

`task_locks` enforces concurrency through unique indexes. Each run acquires zero,
one, or two rows:

| `lock_type` | Key | Lifetime |
| --- | --- | --- |
| `overlap` | `schedule_id` | Removed on confirmed completion or explicit expiry recovery. |
| `occurrence` | `schedule_id`, `scheduled_for` | Retained after completion and recovery to prevent repeating that occurrence. |

Both protections disabled creates no lock rows. Duplicate executions in the same
minute remain separate history rows with distinct IDs and owner tokens.

## Relationship and retention

The nullable foreign key `task_locks.run_id` references `task_runs.id` with
`ON DELETE SET NULL`. One history row can have two locks; each lock identifies the
run that acquired it. An index on `run_id` supports lookup in either direction.

This relationship lets operators find the execution and configuration behind a
lock. Recovery uses it to mark the linked run `abandoned` and retain detection
time and actor. Completion uses the original run ID and owner token to release
only its own overlap lock, so a late result cannot unlock a replacement run.
The history snapshot remains available after its overlap lock is removed.

Deleting history detaches its locks without releasing protection. Lock identity,
owner, occurrence, and deadline remain in `task_locks`; orphaned overlap locks
can still be recovered. Completion of a deleted history row is rejected, so
retain unfinished history until completion or recovery. Removing an occurrence
lock explicitly allows that occurrence to be claimed again; no automatic lock
retention policy is implemented.

History is an execution record, not a complete audit trail of every lock that
has ever existed. Separate lock-event auditing would be an additional feature.

## Transaction boundary

One short transaction recovers expired overlap locks, creates the run snapshot,
and acquires requested locks. A concurrency conflict removes any newly acquired
locks and the new run before commit; recovery of older locks still commits.
Task resolution starts only after commit is confirmed.

Completion records the outcome and removes the owner's overlap lock in one
transaction. Occurrence locks remain. Recovery removes expired overlap locks
and updates linked unfinished history atomically. No transaction remains open
while `Task::run()` executes. See [PostgreSQL storage](storage.md) for details.

Runs without overlap protection have no recovery deadline. If their process
crashes without recording completion, their history can remain `in_progress`;
`onOneServer` alone does not provide crash detection.
