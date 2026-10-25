# Database queries

Application storage and Adapter UI history use `Dibi\Connection` with the PDO
PostgreSQL driver. Inject the non-shared connection service for task queries;
see [Service wiring](../configuration/services.md#database-boundary). Each consumer
owns its transaction boundaries. Scheduler storage keeps its explicit transaction
handling to distinguish rejected commits from lost acknowledgements; see
[PostgreSQL storage](../execution/storage.md).

## Values and SQL structure

Pass values separately from SQL using [Dibi modifiers](https://github.com/dibi/dibi#modifiers):
`%s` for strings, `%i` for integers, and `%b` for booleans. Dibi translates and
escapes these values through the active driver before executing SQL; these are
not PDO prepared-statement placeholders. A `null` value becomes SQL `NULL`.

```php
$connection->query('UPDATE task_runs SET %a WHERE id = %i', [
    'status' => $outcome->value,
    'error_message' => $error,
], $claim->id);
```

Use `%a` for assignment arrays and `%v` for insert column/value arrays. Values
remain next to their column names. Put SQL expressions in
`Connection::expression(...)`, with their own modifiers and arguments, rather
than mixing SQL strings with data. For example, reservation inserts use database
expressions for UUIDs and a shared timestamp from a materialized CTE.

Keep SQL structure and column choices in application code. `%n` can quote an
identifier selected from a fixed allowlist; quoting does not authorize arbitrary
user-selected columns. Do not interpolate input or pass it through `%SQL`.

## Filters and pagination

`HistoryFilters::conditions()` returns validated expression arrays. Both the
count and page queries pass those arrays through `%and`, which handles grouping
and an empty filter list. `%in` expands value lists. `%lmt` and `%ofs` accept the
validated integer page size and offset.

Use `ILIKE %~like~` for case-insensitive substring searches. Dibi escapes literal
`%`, `_`, backslashes, and quotes before adding the surrounding wildcards, so
input cannot change the search pattern or SQL structure. Keep domain validation
for supported statuses, booleans, dates, ID bounds, and page sizes.

## Instants and results

PDO's Dibi `%dt` modifier omits the timezone offset. For `timestamptz` values,
pass `DateTimeImmutable::format('Y-m-d H:i:s.uP')` through `%s` or an insert array
to retain the instant and microseconds regardless of the connection's timezone.
Database clocks and interval calculations remain SQL expressions.

The connection uses `formatDateTime: native`, preserving timestamp strings for
the history API. Dibi returns rows as `Dibi\Row`; convert them with `toArray()` at
the API boundary. `fetch()` and `fetchSingle()` return `null` when no row exists.
Read affected update counts through `getAffectedRows()`.

The PDO driver requires `PDO::ERRMODE_SILENT` and converts failed queries into
Dibi exceptions. Use the Dibi connection for application queries. The scheduler's
transaction boundary accesses native PDO only to inspect existing transactions
and read SQLSTATE after a failed commit; it reads SQLSTATE before rollback can
replace that error information.
