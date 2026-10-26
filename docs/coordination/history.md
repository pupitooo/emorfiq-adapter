# Run history and locks

[Coordination contract](contract.md) defines what reservation, completion, and
recovery guarantee. This page explains how to read and manage the records behind
those operations:

- **History and protection:** what each table stores and how long its rows remain.
- **Retention:** what happens to protection when history is deleted.
- **Ownership:** why a late completion cannot release a replacement run's lock.

Use this page when interpreting coordination data, defining history retention,
or reviewing changes to the schema and lock cleanup. See
[PostgreSQL storage](storage.md) for the queries and transaction mechanics.

## Separate responsibilities

| Table | Responsibility | What it contains |
| --- | --- | --- |
| `task_runs` | Record each accepted execution attempt. | Schedule snapshot, intended occurrence, run identity, timestamps, outcome, diagnostic, and recovery details. |
| `task_locks` | Enforce concurrency through unique indexes. | The protected schedule or occurrence, owner, linked run, and overlap deadline. |

Reservation creates a fresh history row before task resolution, even with both
protections disabled. Skipped events create no history. A committed row confirms
permission to execute; a crash before invocation can still prevent the task from
starting. Consequently, `started_at` records reservation time, not proof that
task code started.

The enabled options determine which lock rows are created:

| Option | `lock_type` | Protected key | Lifetime |
| --- | --- | --- | --- |
| `withoutOverlapping` | `overlap` | `schedule_id` | Removed on confirmed completion or explicit expiry recovery. |
| `onOneServer` | `occurrence` | `schedule_id`, `scheduled_for` | Retained after completion and recovery to prevent repeating that occurrence. |

A run therefore has zero, one, or two lock rows. With both options disabled,
multiple executions in the same minute create separate history rows with
distinct IDs and tokens. The protection flags and `lease_expires_at` in history
describe the run; only `task_locks` enforces protection.

## Relationship and retention

`task_locks.run_id` links a lock to the history row that acquired it. This lets
operators find its configuration and lets recovery mark that run `abandoned`.
The foreign key uses `ON DELETE SET NULL`, so deleting history leaves the lock
in place with `run_id = NULL`.

| Action | Effect |
| --- | --- |
| Complete a run | Record its outcome and remove its overlap lock. Keep history and occurrence protection. |
| Recover an expired overlap lock | Remove the lock and mark linked unfinished history `abandoned`, recording when and by which server recovery occurred. |
| Delete history | Detach its locks. Their owner, protected key, and deadline remain; overlap locks can still be recovered. |
| Delete an occurrence lock | Allow that occurrence to be claimed again. |

Retain unfinished history until completion or recovery: completion is rejected
once its history row is deleted. If late results must remain recordable, retain
abandoned history too. No automatic lock retention policy is implemented.

History is an execution record, not a complete audit trail of every lock that
has ever existed. Separate lock-event auditing would be an additional feature.

## Owner tokens

An owner token identifies one acquisition. Each accepted run receives a fresh
random UUID, even when successive runs execute on the same server. The configured
server ID identifies the server responsible for recovery; it does not prove lock
ownership. Treat the token as a credential and do not log it.

Consider a late completion:

1. Run A acquires an overlap lock with token `alpha`.
2. A's lock expires, recovery removes it, and run B acquires a replacement with
   token `beta`.
3. A finishes late. Completion uses A's run ID and `alpha`, so it can record A's
   result without removing B's lock.

`alpha` and `beta` are illustrative names; production tokens are UUIDs.

The production completion query has this shape:

```sql
DELETE FROM task_locks
WHERE run_id = :original_run_id
  AND owner_token = :original_owner_token
  AND lock_type = 'overlap';
```

The owner check is part of the deletion itself. Checking first and later deleting
by schedule ID would allow the owner to change between those steps. Outcome
recording and deletion also share one transaction; see the
[completion contract](contract.md#completion-finish).

This protects coordination records. It cannot stop A from continuing business
writes, and a random UUID provides no ordering between owners. See
[Fencing stale owners](../failure-and-recovery/external-effects.md#fencing-stale-owners)
for that limit, and [the Laravel comparison](../mutex/design.md#compared-with-laravels-default-mutexes)
for the difference in scheduler cleanup.

## Transaction boundary

Changes to history and locks commit together: a reservation cannot leave a new
run with only some of its requested locks, and completion cannot record an
outcome without releasing that run's overlap lock. These are short transactions;
none remains open while `Task::run()` executes. See
[PostgreSQL storage](storage.md) for the sequence and conflict handling.

Runs without overlap protection have no recovery deadline. If their process
crashes without recording completion, their history can remain `in_progress`,
even after another execution of the same schedule. Recovery has no overlap lock
through which to detect that run; `onOneServer` alone does not provide crash detection.
