# Scheduler configuration structure

The JSON configuration is stored in [`config/scheduler.json`](../config/scheduler.json).

## Examples covered by parser unit tests

The [example JSON](../tests/Fixtures/Scheduler/schedules.json) contains four
schedules for the same `PingTask` class:

- Every minute, using UTC because `timezone` is omitted.
- At 09:00 on weekdays in Prague.
- Every fifteen minutes during the hours 09 through 17 on weekdays in Prague.
- At 18:30 on the first and fifteenth day of each month in New York.

These examples illustrate wildcards, ranges, steps, lists, distinct schedule
identifiers, and per-schedule time zones. The [empty configuration](../tests/Fixtures/Scheduler/no-tasks.json)
illustrates a valid configuration with no scheduled work.

The [parser unit test](../tests/Unit/Scheduler/JsonConfigurationParserTest.php)
passes the fixture JSON directly to `JsonConfigurationParser::parse()` and
checks the returned typed values. It also checks that the UTC default is
independent of PHP's current time zone. Fixture reading is test setup;
the provider is not involved in these scenarios.

The [provider integration test](../tests/Integration/Scheduler/InMemorySchedulerConfigurationTest.php)
covers loading an actual file, retaining the original configuration after the
source file changes, rejecting missing files and directories, and propagating
one representative parser failure. Parser validation cases are covered by unit
tests. All suites are included in `tests/run.php`; cron due-time evaluation
remains outside their scope.

## Invalid examples

The [invalid fixtures](../tests/Fixtures/Scheduler/invalid) each isolate one
configuration error. The [negative parser unit test](../tests/Unit/Scheduler/InvalidJsonConfigurationParserTest.php)
lists the expected exception message next to each filename and is included in
`tests/run.php`.

| Fixtures | Invalid input |
| --- | --- |
| `malformed-json.json` | Trailing comma makes the JSON syntax invalid. |
| `root-is-array.json` | The document root is an array instead of an object. |
| `missing-version.json`, `unsupported-version.json`, `version-is-string.json` | Version is missing, unsupported, or has the wrong type. |
| `missing-tasks.json`, `tasks-is-object.json` | The task list is missing or is an object instead of an array. |
| `task-is-string.json` | A task definition is a string instead of an object. |
| `missing-id.json`, `missing-task.json`, `missing-cron.json` | A required task field is missing. |
| `empty-id.json` | The identifier contains only whitespace. |
| `task-is-number.json` | The task class name is a number instead of a string. |
| `cron-has-seconds.json` | The cron expression has six fields instead of five. |
| `invalid-timezone.json`, `null-timezone.json`, `empty-timezone.json` | The time zone is unknown, explicitly null, or empty. |
| `duplicate-ids.json` | Two schedules use the same identifier. |

The parser throws `ConfigurationException` at the first detected error and
does not return a partial configuration. The provider propagates that failure.
Malformed JSON preserves its original `JsonException` as the previous exception;
an invalid time zone preserves its `DateInvalidTimeZoneException`. Both exception
chains are checked in unit tests. Omitting `timezone` remains valid and uses UTC;
providing `null` or an empty string is rejected.

## Loading and typed configuration

`InMemorySchedulerConfiguration` receives the JSON file path in its constructor.
It reads and converts the file once, retaining an immutable `Configuration`
object in memory. `getConfiguration()` returns that object on every call.
The shared `SchedulerConfiguration` contract declares this return type, allowing
a future database provider to supply the same representation.

`Configuration` contains `version: int` and `tasks: list<ScheduleDefinition>`.
Each `ScheduleDefinition` contains `id`, `task`, `cron`, and `timezone` as string
properties. An omitted time zone becomes `UTC` in the returned definition.
Implementation code refers to version 1 through `Configuration::FORMAT_VERSION_1`
and to the default time zone through `ScheduleDefinition::DEFAULT_TIMEZONE`.
JSON definitions and fixture expectations keep the corresponding literal values.

Unreadable files, invalid JSON, unsupported versions, invalid field types,
empty required strings, duplicate identifiers, invalid time zones, and cron
expressions with other than five fields raise `ConfigurationException`.
Full cron expression validation and resolving task classes into executable
instances remain part of the later scheduler integration.

The database provider exposes the same method but currently throws
`LogicException('Not Implemented')`.

## Root object

| Field | JSON type | Required | Meaning |
| --- | --- | --- | --- |
| `version` | integer | Yes | Configuration format version. The defined version is `1`. |
| `tasks` | array of objects | Yes | Scheduled task definitions. An empty array defines no tasks. |

## Task definition

| Field | JSON type | Required | Meaning |
| --- | --- | --- | --- |
| `id` | string | Yes | Non-empty schedule identifier, unique within `tasks`. |
| `task` | string | Yes | Fully qualified PHP class name of the task. Backslashes are escaped as `\\` in JSON. |
| `cron` | string | Yes | Five-field cron expression: minute, hour, day of month, month, day of week. |
| `timezone` | string | No | Time zone identifier, such as `UTC` or `Europe/Prague`. Defaults to `UTC` when omitted. |

The same task class can have multiple schedules, each with a distinct `id`.
Cron expressions have minute precision and contain no seconds field.

The default time zone is part of this format and is independent of the server's
or PHP's time zone. A task's `timezone` overrides that default for its own schedule.
To use the default, omit the field; `null` and an empty string are not valid time zones.

## Example using the default time zone

`PingTask` runs every minute, with the cron expression interpreted in UTC:

```json
{
    "version": 1,
    "tasks": [
        {
            "id": "ping",
            "task": "App\\Task\\PingTask",
            "cron": "* * * * *"
        }
    ]
}
```

## Example with a task time zone

This definition schedules `PingTask` daily at 09:00 in the Prague time zone:

```json
{
    "version": 1,
    "tasks": [
        {
            "id": "ping-morning",
            "task": "App\\Task\\PingTask",
            "cron": "0 9 * * *",
            "timezone": "Europe/Prague"
        }
    ]
}
```
