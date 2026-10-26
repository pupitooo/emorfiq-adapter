# PostgreSQL storage

[Coordination contract](contract.md) defines the required behavior;
[History and locks](history.md) explains the stored records. This page shows how
the PostgreSQL implementation provides those guarantees:

- **Transactions and conflicts:** how reservation commits history and locks
  together, and what happens when another run already holds a lock.
- **Time and concurrent updates:** when expiry is checked and how recovery and
  completion wait for each other safely.
- **Storage failures:** how a rejected transaction differs from an unconfirmed
  commit, and how the implementation is tested.

Use this page when reviewing SQL changes or investigating storage failures.
`PostgresTaskRunStorage` implements `TaskRunStorage` using Dibi over PDO
PostgreSQL; `ExecutionCoordinator` delegates storage operations to it. See
[Service wiring](../development/service-wiring.md) for connection configuration.

## Transactions and conflicts

Every storage call, including a lookup, opens and closes its own short
`READ COMMITTED` transaction. The dedicated connection must have no transaction
already open. Otherwise, storage rejects the call without committing or rolling
back the caller's work.

`reserve()` performs these steps in one transaction:

1. Recover expired overlap locks for the requested schedule and mark linked
   unfinished runs `abandoned`.
2. Insert the new `task_runs` snapshot with a generated run ID and random owner UUID.
3. Insert the requested `task_locks` rows: occurrence first, then overlap, when enabled.
4. Commit before returning the `RunClaim`.

Unique indexes prevent competing claims from acquiring the same protection.
`ON CONFLICT DO NOTHING` reports a conflict without aborting the transaction.
On conflict, storage removes this attempt's new history and any locks it already
acquired, commits recovery of older locks, and returns `null`.

With both protections disabled, reservation still creates history but inserts
no lock rows. All transactions close before control returns to the caller;
task resolution and `Task::run()` run outside them. Database row locks therefore
last only for the storage operation. The persisted `task_locks` records provide
protection while the task runs.

## Storage failures

`PostgresRunTransaction` handles rollback and translates failures into storage
exceptions. No operation automatically retries a transaction.

| Failure | Reported result | Consequence |
| --- | --- | --- |
| An operation fails before commit | Rollback is attempted; `MutexStorageException` is raised. | No confirmed result is available to the caller. |
| PostgreSQL rejects commit with a constraint violation or transaction rollback SQLSTATE | `MutexStorageException` | The server rejected the transaction; this is a definite failure. |
| Reservation commit cannot be confirmed, for example after connection loss | `ReservationUnconfirmedException` | The reservation may already be committed. Do not invoke the task or blindly retry the claim. |
| Completion or recovery commit cannot be confirmed | `MutexStorageException` | Expose the infrastructure failure; do not report it as rejected completion or zero recovered locks. |

A lost acknowledgement does not prove rollback. This distinction prevents the
caller from treating an uncertain reservation as permission to execute again.

## Database time and ownership

### Recovery and expiry

Recovery first locks the schedule's overlap rows using `FOR UPDATE`, including
rows that are still live. A lock may expire while this query waits for another
transaction, so the subsequent deletion checks expiry against a fresh
`clock_timestamp()` value. It removes expired rows and marks linked
`in_progress` history as `abandoned` in the same transaction.

Reservation reads a fresh database time after recovery for `started_at` and the
fixed overlap deadline. Runs without overlap protection store no deadline.

### Completion and ownership

`complete()` follows this order:

1. Lock the original run's lock rows, ordered by lock type, then its history row.
   Taking lock rows before history follows recovery's order.
2. Update history only if the run ID and owner UUID match and its state is
   `in_progress` or `abandoned`. Read the completion time after any lock wait.
3. Remove only that owner's overlap lock and commit both changes together.

Expiry does not reject completion. A late result can update its original run
while preserving abandonment details and leaving a replacement run's lock
untouched. Occurrence locks remain after completion and recovery. See
[Recovery](../failure-and-recovery/recovery.md) for examples of competing operations.

## Storage tests

Run `make test-storage` to build the PHP test image with `pdo_pgsql` and test the
production storage against PostgreSQL. Each scenario creates an isolated schema,
applies the migration, and removes the schema afterwards. Application tables and
migration history remain untouched.

The suite covers:

- Snapshots, both protections independently and together, owner checks, history
  deletion, expiry, and late completion.
- Atomic rollback, commit failure classification, and lost acknowledgement
  injected after a real commit.
- Concurrent claims, recovery, and completion using separate PHP workers and
  database barriers that control when operations may proceed.
- Special characters in parameters and precise timestamps with timezone offsets.

Race tests share a controlled SQL clock through the test schema's `search_path`.
Production uses PostgreSQL's built-in `clock_timestamp()`. The separate recovery
acceptance suite covers the Laravel runner, worker crashes, and receiver probes.
