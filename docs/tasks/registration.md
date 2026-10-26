# Task services

## Steps

1. **Choose the task class:** create, edit, rename or move it; keep it unchanged
   for schedule-only changes. See [Task class](#task-class).
2. **Implement or adjust `run(): void`:** throw on failure; skip this step if
   the task's behavior stays unchanged.
3. **Supply dependencies if needed:** use configured services or register new
   ones. See [Dependencies](#dependencies).
4. **Update schedules:** add an entry, change its timing or class reference,
   keep it for code-only changes, or remove it to stop scheduling. If deleting
   the class too, remove all references first. See [Schedule](#schedule).

## Task class

Place new tasks in `app/Task/Execution/` with the matching `App\Task\Execution`
namespace and implement `App\Task\Contract\Task`. Use `PingTask.php` as a minimal
example. `config/services/tasks.php` registers these classes automatically.

Each execution gets a fresh instance; do not rely on task properties retaining
state between runs. Lifecycle logging is automatic; see
[Task execution](execution-outcomes.md) for log records and failure handling.

## Dependencies

Declare services as typed constructor parameters:

- **Existing service:** use its configured type; `Psr\Log\LoggerInterface`
  is already available for task-specific logging.
- **New service:** register it under `config/services/`; import new configuration
  files from `config/services.php`.
- **Interface parameter:** configure a service alias or explicit argument binding.
- **Scalar parameter:** configure its value or provide a default.

See [Task resolution](dependency-resolution.md) for dependency wiring.
See [Service wiring](../development/service-wiring.md) for configuration file ownership
and database connections.

## Schedule

Entries belong in the `tasks` array in `config/scheduler.json`. Each needs a
unique schedule ID and the exact fully qualified class name:

```json
{
    "id": "ping",
    "task": "App\\Task\\Execution\\PingTask",
    "cron": "* * * * *"
}
```

Adjust `cron` or `timezone` to change timing; the default time zone is UTC.
When renaming or moving a class, update every matching `task` value. JSON
validation does not check task class existence.

See [Scheduler JSON](../configuration/format.md) and [Cron syntax](../configuration/cron.md)
for available options.
