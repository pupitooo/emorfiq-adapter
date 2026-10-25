# Database migrations

## Apply and inspect

Start PostgreSQL with `make up`, then run:

```sh
make migrate
make migration-status
```

Migrations are explicit, including for existing volumes. Starting a container
does not apply them. The `migrations/` directory is mounted read-only into
PostgreSQL, so SQL changes do not require rebuilding PHP images.

`migrations/migrate.sql` serializes runners with a transaction-scoped advisory
lock, applies pending SQL files, and records
versions in `schema_migrations` in the same transaction. An error rolls back both
schema changes and version records. Repeated execution skips applied versions.

Add numbered SQL files and corresponding version checks to the runner. Keep
released migrations unchanged. Rollback migrations are not supplied.

## Task run structure

Migration `001_task_runs.sql` creates one row per claimed execution, with:

- The schedule ID and planned UTC minute, separate from actual start time.
- A snapshot of task class, cron expression, time zone, and concurrency options.
- Status, owner token, lease expiration, actual start/finish times, and error message.

The application is responsible for validating strings, expiration,
timestamps, and lifecycle state. The intended statuses are `in_progress`,
`succeeded`, `failed`, and `abandoned`. `abandoned` means ownership was lost or
execution could not be confirmed, not that its effects were undone. The migration
defines storage types, required columns, primary keys, and coordination indexes;
it does not add validation `CHECK` constraints.

| Option | Database rule |
| --- | --- |
| `onOneServer` | Unique `(schedule_id, scheduled_for)` among rows with the option enabled, including completed rows. |
| `withoutOverlapping` | Unique `schedule_id` among protected `in_progress` rows. |
| Both disabled | Multiple rows for the same schedule and minute are allowed. |

The columns store the options of that execution. All instances must use the
same schedule IDs and configuration. The minute is the intended occurrence
instant, not a per-process timestamp. See [Concurrency](../configuration/concurrency.md)
for skipping behavior and independent locks for different schedule IDs.

## Runtime boundary

The migration defines storage only. The future coordinator's reservation,
ownership, and completion rules are described in [Execution coordination](../execution/coordination.md).
See [Recovery scenarios](../execution/recovery.md) for expired leases and crash
windows, and [External effects](../execution/external-effects.md) for the limits
of cancellation, idempotence, and fencing. The [verification plan](../execution/verification.md)
separates schema checks from pending implementation contracts.
