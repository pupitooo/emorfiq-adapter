# JSON scheduler configuration

Scheduler definitions are stored in `config/scheduler.json`. The format belongs
to the application and is independent of the scheduling library.

## Complete example

```json
{
    "version": 1,
    "tasks": [
        {
            "id": "ping",
            "task": "App\\Task\\Execution\\PingTask",
            "cron": "* * * * *"
        },
        {
            "id": "ping-morning",
            "task": "App\\Task\\Execution\\PingTask",
            "cron": "0 9 * * *",
            "timezone": "Europe/Prague"
        }
    ]
}
```

The first definition describes a schedule every minute in UTC. The second
describes a daily schedule at 09:00 in the Prague time zone. Definitions describe
the intended schedule; translating them into executable scheduler jobs is part
of the scheduler integration.

## Root object

The document must be a JSON object containing these fields:

| Field | JSON type | Required | Meaning |
| --- | --- | --- | --- |
| `version` | integer | Yes | Configuration format version. The supported value is `1`. |
| `tasks` | array of objects | Yes | Scheduled task definitions. An empty array defines no tasks. |

The smallest valid configuration defines no scheduled tasks:

```json
{
    "version": 1,
    "tasks": []
}
```

## Task definition

Each element of `tasks` must be a JSON object:

| Field | JSON type | Required | Meaning |
| --- | --- | --- | --- |
| `id` | string | Yes | Non-empty schedule identifier, unique within `tasks`. |
| `task` | string | Yes | Fully qualified PHP task class name, for example `App\Task\Execution\PingTask`. Write each backslash as `\\` inside a JSON string. |
| `cron` | string | Yes | Five-field cron expression or a [supported alias](cron.md), for example `@daily`. |
| `timezone` | string | No | PHP time zone identifier, for example `UTC` or `Europe/Prague`. Defaults to `UTC` when omitted. |

The same task class can have multiple schedules, each with its own unique `id`.
Required strings cannot be empty or contain only whitespace.

### Cron expression

Use a valid five-field expression or a supported alias such as `@daily`.
See [Cron syntax](cron.md) for field order, aliases, extended expressions,
and validation details.

### Time zone

The default `UTC` belongs to this configuration format and is independent of
the server's or PHP's default time zone. An explicit `timezone` applies only to
that schedule and must be accepted by PHP's `DateTimeZone`.

Omit the field to use UTC. Explicit `null`, an empty string, and invalid time
zone identifiers are rejected.

## Validation

Configuration loading rejects:

- An unreadable file or invalid JSON.
- A root value other than an object.
- A missing `version`, a value other than integer `1`, or a string such as `"1"`.
- A missing `tasks` field or a value other than an array.
- A task entry other than an object.
- Missing, non-string, empty, or whitespace-only `id`, `task`, or `cron` values.
- Duplicate task identifiers.
- Invalid cron expressions or unknown aliases; see [Cron syntax](cron.md).
- Explicit `timezone` values that are not non-empty strings or valid PHP time zones.
- Unknown fields at the document root or in a task definition.

These failures raise `ConfigurationException`. Task class existence and
instantiation are not checked by the JSON parser.

## Task registration

Each `task` value must match a registered service implementing
`App\Task\Contract\Task`. Concrete tasks in `app/Task/Execution/` are
registered automatically. JSON defines the schedule; PHP service configuration
provides constructor dependencies. See [Task services](../task-services.md) for
registration, autowiring, instance lifetimes, and resolution failures.

The current adapter runs every configured definition once per invocation;
see [Architecture](../architecture.md#current-implementation-and-pending-work)
for scheduling limitations.

## Implementation and tests

See [Configuration loading](loading.md) for typed objects, cached
snapshots, parser behavior, and exception chains. [Configuration tests](tests.md)
describes valid and invalid fixtures and the coverage of each test suite.
