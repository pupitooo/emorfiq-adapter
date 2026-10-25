# Concurrency and server coordination

Each schedule can independently request protection against overlapping runs and
execution on one server. These options are part of configuration version `1`.
Both protections default to disabled.

## Configuration

```json
{
    "version": 1,
    "tasks": [
        {
            "id": "ping",
            "task": "App\\Task\\Execution\\PingTask",
            "cron": "* * * * *",
            "withoutOverlapping": true,
            "overlapExpiresAfterMinutes": 30,
            "onOneServer": true
        }
    ]
}
```

| Field | JSON type | Default | Meaning |
| --- | --- | --- | --- |
| `withoutOverlapping` | boolean | `false` | Skip an occurrence while the same schedule holds its execution lock. |
| `overlapExpiresAfterMinutes` | positive integer | `60` | Execution lock lifetime in minutes (1 hour by default). May be specified only when `withoutOverlapping` is `true`. |
| `onOneServer` | boolean | `false` | Allow only the server that acquires the scheduling lock to execute an occurrence. |

Flags accept only JSON `true` or `false`. Explicit expiration requires
`withoutOverlapping: true` and an integer greater than zero. The parser rejects
it when that flag is omitted or `false`, even if `onOneServer` is `true` or the
expiration equals the default `60`. Omit expiration to use the 60-minute default.
Strings, floating-point numbers, explicit `null`, and unknown fields are rejected.

The current sample in `config/scheduler.json` explicitly disables both protections
and omits expiration, so it continues to run without locking.

## Overlapping runs

`withoutOverlapping` protects the lifetime of an execution. For a schedule that
starts every minute but runs for five minutes, later occurrences are skipped
while its lock is held. They are not queued or retried.

The adapter passes `overlapExpiresAfterMinutes` to Illuminate's
`withoutOverlapping()` method. Normal completion and task failure release the
execution lock. The duration is fixed at acquisition and is not renewed.
Expiration allows eventual recovery if a process cannot release
it. Set it longer than the expected maximum execution time: expiration can allow
another run even while the original process is still running. It is not a task
timeout and does not terminate the original execution.

## One server

`onOneServer` coordinates a scheduled occurrence across scheduler processes.
The runner checks `Schedule::serverShouldRun()` after event filters and before
execution, using the time captured at the start of the invocation. Losing the
scheduling lock skips the occurrence before task resolution.

This protection differs from an execution lock: an occurrence can finish quickly
and still must not execute again on another server for the same scheduled minute.
It does not prevent a later occurrence from starting while an earlier one runs.
Enable both flags when both guarantees are required.

The existing unique `id` becomes the callback event name and therefore identifies
its mutexes. Different IDs for the same task class have independent locks. All
servers must use consistent IDs and a shared atomic locking backend for server
coordination. `overlapExpiresAfterMinutes` configures only the execution lock;
it does not change the scheduling lock lifetime. With `onOneServer` alone,
the database design has no execution-lock expiration and requires no renewal.

## Implementation status

Parsing, typed definitions, Illuminate event mapping, runner checks, and
[PostgreSQL storage](../execution/storage.md) are implemented. Protected events
reserve runs through `ExecutionCoordinator` before resolving their tasks. Apply
the [migrations](../development/migrations.md) before using these options.
An event that is not due does not request a lock. With both protections disabled,
the runner invokes the task directly without storage access.

See [LaravelAdapter execution conditions](../adapter/laravel.md#execution-conditions)
for the current service boundary.

## Persistent coordination design

The PostgreSQL target design keeps occurrence deduplication separate from active
execution ownership. See [Execution coordination](../execution/coordination.md),
[Recovery scenarios](../execution/recovery.md), and
[Verification](../execution/verification.md). Those pages define future behavior;
they do not change the implementation status above.

## Scheduler references

The flags follow
[Laravel Scheduler](https://laravel.com/docs/13.x/scheduling#preventing-task-overlaps).
This application's default execution lock lifetime is 60 minutes (1 hour).
Laravel documents [single-server scheduling](https://laravel.com/docs/13.x/scheduling#running-tasks-on-one-server)
as a separate capability requiring shared cache storage.

[Crunz](https://github.com/crunzphp/crunz#parallelism-and-the-locking-mechanism)
accepts a Symfony Lock store per event. Its
[event implementation](https://github.com/crunzphp/crunz/blob/master/src/Event.php)
also supports refreshing locks while work is running. Renewal is a deferred
extension here. Backend selection is not a JSON option; application service
configuration remains responsible for infrastructure dependencies.
