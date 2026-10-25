# Cron syntax

The `cron` field in the [JSON format](format.md) accepts five-field expressions
and the standard aliases below. Schedules use the definition's `timezone`.

## Five-field expressions

The fields have minute precision:

```text
minute  hour  day-of-month  month  day-of-week
   *      *        *          *        *
```

| Expression | Intended schedule |
| --- | --- |
| `* * * * *` | Every minute. |
| `*/5 * * * *` | Every five minutes. |
| `0 9 * * *` | Daily at 09:00. |

## Standard aliases

An alias replaces the complete expression:

```json
"cron": "@daily"
```

| Alias | Equivalent expression |
| --- | --- |
| `@yearly`, `@annually` | `0 0 1 1 *` |
| `@monthly` | `0 0 1 * *` |
| `@weekly` | `0 0 * * 0` |
| `@daily`, `@midnight` | `0 0 * * *` |
| `@hourly` | `0 * * * *` |

Alias matching ignores letter case. The parser preserves the original string,
as it does for five-field expressions. Surrounding whitespace is rejected for
both aliases and five-field expressions; see [String validation](format.md#string-validation).
Unknown aliases, including `@reboot`, are rejected. Alias support comes from
the cron library; JSON does not define custom aliases. If PHP code registers
an alias through the library's API before parsing, validation accepts it too.

The standard aliases match
[`dragonmantank/cron-expression`](https://github.com/dragonmantank/cron-expression/blob/v3.6.1/src/Cron/CronExpression.php),
the library used by Crunz.

## Extended expressions

The parser also accepts these extended forms supported by
the [cron library](https://github.com/dragonmantank/cron-expression/blob/v3.6.1/README.md#cron-expressions):

| Expression | Intended schedule |
| --- | --- |
| `0 9 * JAN MON-FRI` | Weekdays in January at 09:00; month and weekday names. |
| `0 0 L * *` | Last day of each month at midnight. |
| `0 9 15W * *` | Weekday nearest the 15th of each month at 09:00. |
| `0 9 LW * *` | Last Monday-to-Friday day of each month at 09:00. |
| `0 9 * * 1#2` | Second Monday of each month at 09:00. |
| `0 9 * * 5L` | Last Friday of each month at 09:00. |
| `0 9 ? * MON` | Mondays at 09:00; `?` acts as `*` in a day field. |

Wildcards, lists, ranges, and steps can also be used within fields.

## Validation and execution limits

The required-field schema rejects a missing `cron` key. The shared non-empty
field check rejects empty and whitespace-only cron values, including Unicode
whitespace, with a specific non-empty field error.

For non-empty values, the parser uses `CronExpression::isValidExpression()` to validate field counts,
syntax, ranges, and aliases during configuration loading. It validates the
original value without trimming or transforming it. Invalid expressions raise
`ConfigurationException` naming the cron field. Syntax validation does not guarantee that an expression has a matching
calendar date. Laravel Scheduler evaluates whether an expression is due during
the [due-task selection](../adapter/laravel.md#select-due-tasks).

Six-field expressions (seconds or a year), crontab command columns, and
environment assignments are outside this format.
[Crunz rejects expressions with more than five fields](https://github.com/crunzphp/crunz/blob/master/src/Event.php).
