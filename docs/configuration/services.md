# Service wiring

`bin/run.php` loads `config/services.php` and compiles the Symfony container for
each invocation. It uses `compile(true)` to resolve environment placeholders
before using the container directly. The root configuration imports service
files explicitly, in the order shown below.

## Configuration files

| File under `config/services/` | Responsibility |
| --- | --- |
| `logging.php` | Monolog services and the PSR-3 logger alias. |
| `database.php` | PostgreSQL connection factory and Dibi connection registration. |
| `scheduler.php` | Task run storage selection, execution coordinator, and task executor. |
| `scheduler-laravel.php` | Illuminate mutex aliases, shared event registry, and scheduler runtime. |
| `tasks.php` | Task discovery, task lifetime, and the task resolver alias. |

Each file that uses autowiring enables it through
`$services->defaults()->autowire()`. Defaults apply within that file. Constructor
types supply service dependencies; explicit arguments supply scalar settings.
Aliases choose implementations for interfaces. Services retrieved directly by
the CLI remain public; other services stay private.

See [Task services](../task-services.md) for adding tasks and their dependencies.

## Database boundary

`App\Database\PostgresConnectionFactory` creates `Dibi\Connection` instances through the PDO PostgreSQL driver without depending
on scheduler contracts or exceptions. Dibi creates the PDO connection from its configuration array;
connection failures propagate as `Dibi\Exception`. Its `fromEnvironment()` method is the single place that
maps process environment variables to connection settings, shared by Symfony DI and Adapter UI:

| Variable | Purpose |
| --- | --- |
| `DATABASE_DSN` | PDO PostgreSQL connection string. |
| `DATABASE_USER` | Database username. |
| `DATABASE_PASSWORD` | Database password. |

Docker Compose defines local defaults once in the shared runtime environment and passes them to web and scheduler.
Standalone PHP invocations must supply them explicitly. The PHP bootstrap does
not load `.env` files; Compose reads `.env` for its own configuration. See the
[local database guide](../development/database.md) for connection details.
Missing or empty settings raise `RuntimeException` before a connection is opened.

`Dibi\Connection` is a non-shared service: each consumer receives its own connection. This
keeps task transactions separate from the scheduler's coordination transactions.
The factory service itself can be shared because each `connect()` call creates
a new connection.

`PostgresTaskRunStorage` remains in scheduler infrastructure because it implements
the scheduler's `TaskRunStorage` contract and owns task run SQL. Its static `connect()` factory receives the shared connection factory through
autowiring, creates a dedicated Dibi connection, and translates connection failures
to `MutexStorageException`. Storage is lazy through its interface. Resolving the coordinator
or building the scheduler does not open a database connection; the first storage
operation does. Its transaction handling and reservation semantics remain
scheduler responsibilities. See [Coordination](../execution/coordination.md).

Query modifiers, escaping, and result formats are described in
[Database queries](../development/database-queries.md).

## Scheduler integration

`scheduler.php` chooses `PostgresTaskRunStorage` for `TaskRunStorage` and
`ExecutionCoordinator` for `ExecutionMutex` independently of Illuminate.

`scheduler-laravel.php` selects `CoordinatedEventMutex` and
`CoordinatedSchedulingMutex` for Illuminate's mutex interfaces. These aliases let
autowiring supply `SchedulerApplication` without explicit constructor argument
bindings. Both mutex implementations use the same shared `EventExecutions`
registry. See [Mutex layers](../execution/mutex-layers.md) for the runtime flow.
