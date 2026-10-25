# PostgreSQL storage

`PostgresTaskRunStorage` implements `TaskRunStorage` using PDO and the versioned
`task_runs` schema. `ExecutionCoordinator` delegates storage operations to it.
See [Coordination](coordination.md) for the lifecycle contract and
[Service wiring](../configuration/services.md) for connection configuration.

## Transactions and conflicts

Every operation uses a short, owned `READ COMMITTED` transaction. The dedicated
connection must not already have a transaction; storage rejects that situation
without committing or rolling back the caller's work. Transactions close before
returning a claim, lookup result, completion result, or recovery count.

Reservation first recovers expired runs belonging to the schedule, then inserts
the configuration snapshot with a database-generated random UUID. Both steps
commit together. `ON CONFLICT DO NOTHING` lets the partial unique indexes choose
a winner without aborting the transaction. A conflict returns `null`, creates no
run row, and still commits any recovery performed in that transaction.

`PostgresRunTransaction` rolls back operation failures and translates driver
errors to `MutexStorageException`. If reservation commit acknowledgement is lost,
it throws `ReservationUnconfirmedException`: the row may already exist, so the
caller must not execute or retry the task. Server-reported constraint violations
and transaction rollback errors at commit are definitive storage failures.
No operation automatically retries a transaction.

## Database time and ownership

Recovery locks the schedule's protected active rows before checking expiry.
It includes currently live rows in that lock acquisition because their deadline
may pass during a lock wait. The subsequent update rechecks state and
`clock_timestamp()`; reservation then takes a fresh database time for both
`started_at` and its fixed deadline. Unprotected runs store no deadline.

Completion first obtains the original row's lock, then updates only matching
run ID, owner UUID, and unfinished state. Its completion timestamp is read after
the wait. Expiry does not reject completion. Late results retain abandonment
history and do not affect replacement runs. Completed occurrence reservations
remain in history. See [Recovery](recovery.md) for the accepted race outcomes.

## Storage tests

Run `make test-storage` to build PHP with `pdo_pgsql` and exercise the production
storage against PostgreSQL. Each scenario creates a random isolated schema,
applies both real migrations, and removes that schema afterwards. Application
tables and migration history are untouched.

The suite covers snapshots, both independent protections, owner checks, expiry,
late completion, rollback, commit failure classification, and concurrent claims.
Race tests use separate PHP workers and PostgreSQL lock/commit barriers. An
isolated SQL clock supplies shared controlled time through the test schema's
`search_path`; production uses PostgreSQL's built-in `clock_timestamp()`.
Lost acknowledgement is injected after a real commit. The separate worker-crash
acceptance harness remains pending; see [Verification](verification.md).
