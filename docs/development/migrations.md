# Database migrations

## Apply and inspect

Start PostgreSQL with `make up`, then run:

```sh
make migrate
make migration-status
```

Migrations are explicit. Starting a container does not apply them. The
`migrations/` directory is mounted read-only into PostgreSQL, so SQL changes do
not require rebuilding PHP images.

`migrations/migrate.sql` serializes runners with a transaction-scoped advisory
lock and records version 1 in `schema_migrations` in the same transaction as the
schema creation. An error rolls back both. Repeated execution skips the applied
version.

## Single schema migration

`001_task_runs.sql` is the only schema migration. During this development phase,
edit this file for subsequent schema changes rather than adding migrations.
The database will be recreated; there is no upgrade path from the previous
schema. Editing an already applied migration does not update an existing database.
Recreate the application schema and its migration record before applying it again.
The application database has not been cleared as part of this source change.

The migration creates:

- `task_runs`: execution history and the original configuration snapshot.
- `task_locks`: overlap locks and occurrence reservations, linked to history.

See [Run history and locks](../execution/history.md) for the relationship,
indexes, and retention behavior. History lifecycle states are `in_progress`,
`succeeded`, `failed`, and `abandoned`. Recovery records `abandoned_at` and
`abandoned_by` without setting `finished_at`; late completion retains that
attribution. The lock table constrains lock types and their required keys and
deadlines. Application configuration and lifecycle validation remain in PHP.

## Runtime boundary

The migration defines the schema; [PostgreSQL storage](../execution/storage.md)
implements atomic acquisition, completion, and recovery. See
[Coordination](../execution/coordination.md) for their contract and
[Recovery](../execution/recovery.md) for crash windows. Test adaptation to the
split schema is deferred.
