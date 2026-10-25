# Configuration tests

The suite verifies the [JSON format](format.md) and
[configuration loading](loading.md). Run it with `make test`;
see [Local environment](../development/local-environment.md#tests-and-quality-checks).

## Valid examples

The [example JSON](https://github.com/pupitooo/emorfiq-adapter/blob/main/tests/Fixtures/Scheduler/schedules.json) contains four
schedules for the same `PingTask` class:

```json
{
    "version": 1,
    "tasks": [
        {
            "id": "ping-every-minute",
            "task": "App\\Task\\Execution\\PingTask",
            "cron": "* * * * *"
        },
        {
            "id": "ping-weekday-morning",
            "task": "App\\Task\\Execution\\PingTask",
            "cron": "0 9 * * 1-5",
            "timezone": "Europe/Prague"
        },
        {
            "id": "ping-quarter-hour-weekdays",
            "task": "App\\Task\\Execution\\PingTask",
            "cron": "*/15 9-17 * * 1-5",
            "timezone": "Europe/Prague"
        },
        {
            "id": "ping-twice-monthly",
            "task": "App\\Task\\Execution\\PingTask",
            "cron": "30 18 1,15 * *",
            "timezone": "America/New_York"
        }
    ]
}
```

- Every minute, using UTC because `timezone` is omitted.
- At 09:00 on weekdays in Prague.
- Every fifteen minutes during the hours 09 through 17 on weekdays in Prague.
- At 18:30 on the first and fifteenth day of each month in New York.

These examples illustrate wildcards, ranges, steps, lists, distinct schedule
identifiers, and per-schedule time zones. The [empty configuration](https://github.com/pupitooo/emorfiq-adapter/blob/main/tests/Fixtures/Scheduler/no-tasks.json)
illustrates a valid configuration with no scheduled work.

The [parser unit test](https://github.com/pupitooo/emorfiq-adapter/blob/main/tests/Unit/Scheduler/JsonConfigurationParserTest.php)
passes the fixture JSON directly to `JsonConfigurationParser::parse()` and
checks the returned typed values. It also checks that the UTC default is
independent of PHP's current time zone. Fixture reading is test setup;
the provider is not involved in these scenarios.

`CronConfigurationParserTest` adds valid numeric boundaries, named months and
weekdays, and mixed whitespace between fields. It rejects empty and Unicode-whitespace-only
expressions, too few or too many fields, out-of-range values, invalid tokens,
steps, ranges, and lists. It accepts all seven built-in aliases, including
aliases with uppercase letters, and preserves their
original values. Extended expressions cover `L`, `W`, `LW`, `#`, and `?`.
Unknown aliases (including `@reboot`) and malformed aliases are rejected.
These tests validate syntax and stored values; they do not evaluate run dates.
The existing empty-cron and six-field fixtures remain covered.
Missing `cron` keys produce a required-field schema error. Empty strings and
ASCII- or Unicode-whitespace-only cron values produce a non-empty field error;
non-empty expressions with invalid syntax produce a cron syntax error.

`InvalidJsonConfigurationParserTest` rejects leading and trailing spaces, tabs,
line breaks, and Unicode whitespace independently for `id`, `task`, `cron`, and
`timezone`. Cron cases cover both five-field expressions and aliases. These
failures name the affected field and enforce [String validation](format.md#string-validation).

The [provider integration test](https://github.com/pupitooo/emorfiq-adapter/blob/main/tests/Integration/Scheduler/InMemorySchedulerConfigurationTest.php)
covers loading an actual file, retaining the original configuration after the
source file changes, rejecting missing files and directories, and propagating
one representative parser failure. Parser validation cases are covered by unit
tests. Cron due-time evaluation is covered separately by `DueEventsTest`.
All suites are included in `tests/run.php`.

## Concurrency options

`LockingConfigurationParserTest` verifies the unrestricted defaults, independent
combinations of both flags, and positive integer expirations. It rejects numeric
and string flags, nulls, arrays, objects, non-positive expirations, and
floating-point or string expirations. Failures identify the affected field.
Existing fixture assertions also check the new default values.

The [locking JSON fixture](https://github.com/pupitooo/emorfiq-adapter/blob/main/tests/Fixtures/Scheduler/locking.json)
contains seven schedules in one document: omitted options, each flag enabled
independently, both flags with a custom expiration, explicit disabled flags,
expiration without flags, and the one-minute minimum. Parser assertions check
each schedule's own values, order, and 60-minute default where expiration is
omitted, including after a schedule with custom options.

Negative JSON fixtures cover invalid flags and expirations with schema error
codes and field paths. A combined fixture verifies that all three invalid lock
options are reported together.

`LaravelLockingTest` covers the JSON-to-event mapping and runner behavior with
recording mutexes. It also verifies that enabling either protection with the
current mutex placeholders fails explicitly before task resolution. See
[Concurrency](concurrency.md) for the configuration contract and implementation status.

## Invalid examples

The [invalid fixtures](https://github.com/pupitooo/emorfiq-adapter/tree/main/tests/Fixtures/Scheduler/invalid) each isolate one
configuration error. The [negative parser unit test](https://github.com/pupitooo/emorfiq-adapter/blob/main/tests/Unit/Scheduler/InvalidJsonConfigurationParserTest.php)
lists the expected exception messages or structured schema errors next to each
filename and is included in `tests/run.php`. For schema failures, it checks error
codes and field paths rather than exact library message wording. It also checks
aggregation of multiple schema errors into a single configuration failure.

| Fixtures | Invalid input |
| --- | --- |
| `malformed-json.json` | Trailing comma makes the JSON syntax invalid. |
| `root-is-array.json` | The document root is an array instead of an object. |
| `missing-version.json`, `unsupported-version.json`, `version-is-string.json`, `version-is-float.json` | Version is missing, unsupported, or has the wrong type. |
| `missing-tasks.json`, `tasks-is-object.json`, `tasks-is-null.json` | The task list is missing or is an object or null instead of an array. |
| `task-is-string.json`, `task-is-array.json`, `task-is-null.json` | A task definition is not a JSON object. |
| `missing-id.json`, `missing-task.json`, `missing-cron.json` | A required task field is missing. |
| `empty-id.json`, `unicode-whitespace-id.json` | The identifier contains only ASCII or Unicode whitespace. |
| `empty-task.json`, `empty-cron.json` | The task class name or cron expression contains only whitespace. |
| `surrounding-whitespace.json` | The identifier has surrounding whitespace. |
| `task-is-number.json` | The task class name is a number instead of a string. |
| `cron-has-seconds.json` | The cron expression has six fields instead of five. |
| `invalid-timezone.json`, `null-timezone.json`, `empty-timezone.json` | The time zone is unknown, explicitly null, or empty. |
| `without-overlapping-is-string.json`, `on-one-server-is-null.json` | Lock flags are a string or null instead of booleans. |
| `overlap-expiry-is-zero.json`, `overlap-expiry-is-float.json` | Lock expiration is zero or a floating-point number. |
| `multiple-invalid-lock-options.json` | All three lock options are invalid; the parser reports all three fields. |
| `duplicate-ids.json` | Two schedules use the same identifier. |
| `unknown-root-field.json`, `unknown-task-field.json` | The root or a task contains an unrecognized field. |
| `multiple-invalid-fields.json` | One task has both a missing identifier and a task class with the wrong type. |

The parser throws `ConfigurationException` and does not return a partial
configuration. Schema validation can report multiple field errors together;
JSON container and schedule rule checks stop at the first failure. The provider
propagates the configuration failure.
Malformed JSON preserves its original `JsonException` as the previous exception;
an invalid time zone preserves its `DateInvalidTimeZoneException`. Both exception
chains are checked in unit tests. Omitting `timezone` remains valid and uses UTC;
providing `null` or an empty string is rejected.
