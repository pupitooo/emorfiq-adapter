# Service wiring

Register services under `config/services/`; import new files from
`config/services.php`.

## Configuration files

| File under `config/services/` | Responsibility |
| --- | --- |
| `logging.php` | Monolog services and the PSR-3 logger alias. |
| `database.php` | PostgreSQL connection factory and Dibi connection registration. |
| `scheduler.php` | Task run storage selection, execution coordinator, and task executor. |
| `scheduler-laravel.php` | Illuminate mutex aliases, shared event registry, and scheduler runtime. |
| `tasks.php` | Task discovery, task lifetime, and the task resolver alias. |

Enable autowiring per file with `$services->defaults()->autowire()`. Bind scalar
arguments explicitly and use aliases to select interface implementations.

See [Task services](../tasks/registration.md) for adding tasks and their dependencies.

The scheduler entry point also requires `SERVER_ID`; see
[Server identity](server-identity.md) for configuration and semantics.

## Database boundary

Set these environment variables for the scheduler and Adapter UI:

| Variable | Purpose |
| --- | --- |
| `DATABASE_DSN` | PDO PostgreSQL connection string. |
| `DATABASE_USER` | Database username. |
| `DATABASE_PASSWORD` | Database password. |

Compose supplies local defaults. For standalone PHP, export the variables
explicitly; PHP does not load `.env`. All three values must be non-empty.

Keep `Dibi\Connection` non-shared (`->share(false)`) so task and scheduler
transactions use separate connections.

See [Database](database.md) for local setup and
[Mutex layers](../mutex/layers.md) for scheduler integration.
