# PostgreSQL storage

`PostgresTaskRunStorage` implements `TaskRunStorage` using Dibi over PDO PostgreSQL and the versioned
`task_runs` history and `task_locks` schema. `ExecutionCoordinator` delegates storage operations to it.
See [Coordination](coordination.md) for the lifecycle contract and
[Service wiring](../development/service-wiring.md) for connection configuration.

## Transactions and conflicts

Every operation uses a short, owned `READ COMMITTED` transaction. The dedicated
connection must not already have a transaction; storage rejects that situation
without committing or rolling back the caller's work. Transactions close before
returning a claim, lookup result, completion result, or recovery count.

Reservation first recovers expired runs belonging to the schedule, then inserts
the configuration snapshot with a database-generated random UUID and acquires
requested `task_locks` rows. These steps commit together. `ON CONFLICT DO NOTHING`
on lock acquisition lets unique indexes choose a winner without aborting the
transaction. A conflict removes partially acquired locks and the new history
row, returns `null`, and still commits recovery. Unprotected runs create history
without lock rows. See [History and locks](history.md) for table ownership.

`PostgresRunTransaction` rolls back operation failures and translates driver
errors to `MutexStorageException`. If reservation commit acknowledgement is lost,
it throws `ReservationUnconfirmedException`: the row may already exist, so the
caller must not execute or retry the task. Server-reported constraint violations
and transaction rollback errors at commit are definitive storage failures.
No operation automatically retries a transaction.

## Database time and ownership

Recovery locks the schedule's overlap rows in `task_locks` before checking expiry.
It includes currently live rows in that lock acquisition because their deadline
may pass during a lock wait. The subsequent deletion rechecks expiry against
`clock_timestamp()`; reservation then takes a fresh database time for both
`started_at` and its fixed deadline. Unprotected runs store no deadline.

Completion locks the original run's lock rows before its history row, then
updates only matching run ID, owner UUID, and unfinished state. The same
transaction removes only the owner's overlap lock. Its completion timestamp is read after
the wait. Expiry does not reject completion. Late results retain abandonment
history and do not affect replacement runs. Completed occurrence reservations
remain in `task_locks` independently of history retention. See
[Recovery](recovery.md) for the accepted race outcomes.

## Storage tests

Test adaptation to the split schema is deferred and these tests have not been
run for this change. Once adapted, `make test-storage` builds PHP with
`pdo_pgsql` and exercises the production storage against PostgreSQL. Each scenario creates a random isolated schema,
applies the schema migration, and removes that schema afterwards. Application
tables and migration history are untouched.

The suite covers snapshots, both independent protections, owner checks, expiry,
late completion, rollback, commit failure classification, and concurrent claims.
It also checks special characters in stored parameters and precise instants with
timezone offsets.
Race tests use separate PHP workers and PostgreSQL lock/commit barriers. An
isolated SQL clock supplies shared controlled time through the test schema's
`search_path`; production uses PostgreSQL's built-in `clock_timestamp()`.
Lost acknowledgement is injected after a real commit. The separate recovery
acceptance suite covers the Laravel runner, worker crashes, and receiver probes.
