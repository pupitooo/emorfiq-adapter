# Configuration loading

The [scheduler JSON format](format.md) is mapped into typed
application objects before task execution.

## Immutable snapshot

`InMemorySchedulerConfiguration` receives the JSON file path in its constructor.
It reads and converts the file once, retaining an immutable `Configuration`
object in memory. `getConfiguration()` returns that object on every call.
The shared `SchedulerConfiguration` contract declares this return type, allowing
a future database provider to supply the same representation.

`Configuration` contains `version: int` and `tasks: list<ScheduleDefinition>`.
Each `ScheduleDefinition` contains `id`, `task`, `cron`, and `timezone` as string
properties. An omitted time zone becomes `UTC` in the returned definition.
Implementation code refers to version 1 through `Configuration::FORMAT_VERSION`
and to the default time zone through `ScheduleDefinition::DEFAULT_TIMEZONE`.
JSON definitions and fixture expectations keep the corresponding literal values.

## Parsing and validation

`JsonConfigurationParser` decodes JSON with PHP and delegates required fields,
types, defaults, and object construction to
[`nette/schema`](https://doc.nette.org/en/schema). `castTo()` passes the validated
values to the constructors of `Configuration` and `ScheduleDefinition`.
The domain objects remain independent of Nette.

Small JSON container checks preserve the distinction between objects, arrays,
and null before Nette normalization. String values, unique schedule
identifiers, cron expressions, and time zone validity are checked on
the mapped configuration. String validation uses `Nette\Utils\Strings::trim()`,
including its Unicode whitespace support, to detect empty values and surrounding
whitespace. Both are rejected without changing the stored values; see
[String validation](format.md#string-validation).

Cron values first pass the shared non-empty field check, including Unicode
whitespace. Missing keys are reported by the required-field schema; empty or
whitespace-only values produce a non-empty field error. Non-empty cron values
then pass field-count, syntax, range, and alias validation through
`dragonmantank/cron-expression`. See [Cron syntax](cron.md) for accepted forms.
There is no separate cron regular expression or alias list. Cron syntax
validation receives the original string. Returned definitions retain accepted
values unchanged, so adapters can use them directly without normalization.

## Failures and alternative providers

Unreadable files, invalid JSON, unsupported versions, invalid field types,
empty strings or surrounding whitespace, duplicate identifiers, invalid time zones, and cron
expressions with invalid syntax, ranges, field counts, or unknown aliases raise
`ConfigurationException`.
Schema failures preserve `Nette\Schema\ValidationException` as their previous
exception and include the collected schema messages. Native JSON and time zone
failures retain their original exception types as before.
Task instances are resolved separately through [Task services](../task-services.md).

The database provider exposes the same method but currently throws
`ConfigurationException('Not Implemented')`.

For the fixtures and checks that cover these rules, see
[Configuration tests](tests.md).
