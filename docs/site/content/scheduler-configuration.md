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
            "task": "App\\Task\\PingTask",
            "cron": "* * * * *"
        },
        {
            "id": "ping-morning",
            "task": "App\\Task\\PingTask",
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
| `task` | string | Yes | Fully qualified PHP task class name, for example `App\Task\PingTask`. Write each backslash as `\\` inside a JSON string. |
| `cron` | string | Yes | Five-field cron expression: minute, hour, day of month, month, day of week. |
| `timezone` | string | No | PHP time zone identifier, for example `UTC` or `Europe/Prague`. Defaults to `UTC` when omitted. |

The same task class can have multiple schedules, each with its own unique `id`.
Required strings cannot be empty or contain only whitespace.

### Cron expression

The five fields have minute precision; there is no seconds field:

```text
minute  hour  day-of-month  month  day-of-week
   *      *        *          *        *
```

| Expression | Intended schedule |
| --- | --- |
| `* * * * *` | Every minute. |
| `*/5 * * * *` | Every five minutes. |
| `0 9 * * *` | Daily at 09:00 in the task's time zone. |

The configuration parser checks that the expression has exactly five
whitespace-separated fields. Full validation of cron syntax and ranges belongs
to the scheduler integration. Do not use six-field expressions or shortcuts
such as `@daily`.

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
- Cron expressions with other than five fields.
- Explicit `timezone` values that are not non-empty strings or valid PHP time zones.
- Unknown fields at the document root or in a task definition.

These failures raise `ConfigurationException`. Task class existence and
instantiation are not checked by the JSON parser.
