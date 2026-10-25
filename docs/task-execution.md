# Task execution

`TaskExecutor` surrounds each selected task with lifecycle logging. It depends
on the application-owned `TaskResolver` and the existing PSR-3 `LoggerInterface`.
The Laravel adapter invokes it inside each callback, so task construction and
`Task::run()` occur only for due events whose conditions pass.

## Lifecycle records

The shared Monolog logger writes newline-delimited JSON to
`storage/logs/adapter.log`. Task messages and scheduler lifecycle records use
the same logger and destination. Tasks keep their own logging behavior.

| Event in `context.event` | Level | Meaning |
| --- | --- | --- |
| `scheduler.task.started` | INFO | Task resolution is about to begin. |
| `scheduler.task.completed` | INFO | `Task::run()` returned successfully. |
| `scheduler.task.failed` | ERROR | Task resolution or execution threw a `Throwable`. |

Every lifecycle record includes `context.schedule_id` and `context.task` (the
fully qualified task class). Completion and failure records also include
`context.duration_ms`, measured with PHP's monotonic clock from the start of
execution, including task resolution. Failure records include the original
exception under the PSR-3 `exception` key; Monolog serializes its class, message,
location, and stack trace. The record's `datetime` supplies wall-clock time.

Example failure context:

```json
{
  "schedule_id": "inventory-sync",
  "task": "App\\Task\\Execution\\InventorySyncTask",
  "event": "scheduler.task.failed",
  "duration_ms": 24.8,
  "exception": {
    "class": "RuntimeException",
    "message": "Remote service unavailable",
    "code": 0,
    "file": "/app/app/Task/Execution/InventorySyncTask.php:25",
    "trace": ["/app/app/Scheduler/Application/TaskExecutor.php:30"]
  }
}
```

Non-due events and events rejected by conditions produce no lifecycle records
and do not resolve tasks. A started record without completion or failure can
indicate an interrupted process; PHP termination, process kills, and memory
exhaustion cannot be reliably handled by catching exceptions.

## Failure policy

`TaskExecutor` catches `Throwable` from task resolution and execution, including
PHP `Error`, logs it, and throws `TaskExecutionException`. This wrapper retains
the schedule definition and original cause. It does not retry or roll back work.
Tasks should throw when they cannot complete successfully; swallowing their own
failure makes the scheduler report successful completion.

`LaravelAdapter` catches these already logged task failures and continues the
remaining due events in configuration order. Tasks are treated as independent;
their ordering does not express dependencies. No aggregate exception or additional
STDERR summary is produced.

### Process exit status

A completed scheduler invocation exits with status `0`, including when individual
tasks fail or no tasks are due. This follows Laravel
[`schedule:run`](https://github.com/illuminate/console/blob/13.x/Scheduling/ScheduleRunCommand.php):
it reports task exceptions and continues; its `handle()` returns no failure code.
Laravel
[`Command::execute()`](https://github.com/illuminate/console/blob/13.x/Command.php)
converts that return value to status `0`. The status indicates that the scheduler
processed the plan; task success must be checked through lifecycle records.

Returning `1` instead would mark the entire invocation as failed to the caller.
[Supercronic](https://github.com/aptible/supercronic/blob/master/cron/cron.go)
would emit an error for that invocation, even with the configured `-quiet` flag.
It would not automatically retry the tasks or stop the scheduler. The current
Docker health check checks only that supercronic is running, and the container
restart policy applies to the container process, not its PHP child. A shell, CI
job, or other caller could also use a nonzero status to trigger failure handling.

### Infrastructure failures

Configuration, container initialization, scheduler infrastructure, and lifecycle
logger errors propagate immediately. The adapter catches only task failure
wrappers, so infrastructure failures do not masquerade as ordinary task failures.

## Integration boundary

Lifecycle logging belongs to the application execution layer and uses its
existing logger. It does not depend on Laravel's event dispatcher or the
`ScheduledTaskStarting`, `ScheduledTaskFinished`, and `ScheduledTaskFailed`
events emitted by Artisan's scheduler command. Our own runner invokes
`TaskExecutor` explicitly. This keeps the lifecycle available to another adapter
without recreating Laravel's application event infrastructure.

See [LaravelAdapter](adapter/laravel.md#select-due-tasks) for event selection,
[Adapter limitations](adapter/index.md#current-implementation-limits) for current
runtime limits, and [Adapter UI](development/adapter-ui.md) for inspecting the
records locally.

The persistent coordinator has a separate [execution contract](execution/coordination.md)
and [recovery design](execution/recovery.md). It records every admitted run in
[history](execution/history.md), including unprotected executions. Its database state is distinct from
the lifecycle log: a completed effect can still have an unconfirmed database
outcome if the process or final write fails.
