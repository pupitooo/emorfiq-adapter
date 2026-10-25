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

Migration `001_task_runs.sql` creates one row per claimed execution. Migration
`002_task_run_completion.sql` separates recovery history from completion and
allows an empty overlap deadline for runs without overlap protection. Together
they store:

- The schedule ID and planned UTC minute, separate from actual start time.
- A snapshot of task class, cron expression, time zone, and concurrency options.
- Status, owner token, fixed overlap expiration, actual start/finish times, and error message.
- Recovery detection time (`abandoned_at`) and recovering process identity (`abandoned_by`).

The application is responsible for validating strings, expiration,
timestamps, and lifecycle state. The intended statuses are `in_progress`,
`succeeded`, `failed`, and `abandoned`. `abandoned` means ownership was lost or
execution could not be confirmed, not that its effects were undone. A late
original completion can change it to `succeeded` or `failed`, setting
`finished_at` while preserving abandonment fields. Recovery itself leaves
`finished_at` empty. The migrations define storage types, required columns,
primary keys, and coordination indexes;
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

Migration 002 copies legacy abandoned rows' `finished_at` into `abandoned_at`
and clears `finished_at`. Their original recovering process was not recorded,
so `abandoned_by = 'legacy:unknown'` explicitly records that limitation.
Confirmed completion timestamps are unchanged. Deadlines on rows without
`without_overlapping` become null; occurrence reservations remain intact.

## Runtime boundary

The migrations define the schema; [PostgreSQL storage](../execution/storage.md)
implements reservation, ownership, completion, and recovery. Their rules are described in [Execution coordination](../execution/coordination.md).
See [Recovery scenarios](../execution/recovery.md) for expired overlap locks and crash
windows, and [External effects](../execution/external-effects.md) for the limits
of cancellation, idempotence, and fencing. The [verification plan](../execution/verification.md)
separates schema checks, storage tests, and worker recovery acceptance tests.
