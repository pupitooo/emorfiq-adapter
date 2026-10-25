# Local environment

Requires Docker with Compose v2+ and Make. PHP 8.5, Composer, and development
tools run in containers; they do not need to be installed on the host.
Run commands from the application root.

## Start and stop

```sh
make up
```

Open [http://localhost:8088](http://localhost:8088) to view live task logs.
After source changes, run `make up` again to rebuild the application image.
To choose another port:

```sh
APP_PORT=8092 make up
```

Run `make down` to stop the application while keeping its logs and database data.
The environment is intended for local development. The web service uses PHP's
development server and is exposed only on `127.0.0.1`.

## Services and logs

| Service | Purpose |
| --- | --- |
| `postgres` | Runs PostgreSQL with persistent storage and UTC time zones. |
| `adminer` | Provides the database UI at [http://localhost:8090](http://localhost:8090). |
| `scheduler` | Runs the CLI entry point through supercronic every minute. |
| `web` | Serves the live log viewer and its HTTP/SSE endpoints. |
| `check` | Runs tests and quality tools in an isolated container. |
| `docs` | Runs the independent documentation preview. |

The scheduler writes task records to `storage/logs/adapter.log` in the shared
Docker `runtime` volume. The [log viewer](log-viewer.md) reads that same file
and displays lifecycle details, durations, and exceptions. `make logs`
follows scheduler process output and adapter errors in the terminal.
See [Architecture](../architecture.md) for the invocation flow and current scheduling limits.

See [Database](database.md) for Adminer login details, SQL access, and storage.

## Tests and quality checks

| Command | Purpose |
| --- | --- |
| `make up` | Build and start the application, PostgreSQL, and Adminer. |
| `make down` | Stop services while retaining logs and database data. |
| `make logs` | Follow scheduler output and adapter errors. |
| `make psql` | Open an interactive PostgreSQL session. |
| `make test` | Run the implemented application suite in a separate Docker container. |
| `make check` | Run PHPStan, ECS coding-standard checks, and the implemented application suite. |

Tests cover task logging, JSON parsing and validation, cached configuration
snapshots, task service resolution, and execution of configured definitions.
CI runs `make test` and `make check`.
See [Recovery verification](../execution/verification.md) for planned database
checks, recovery acceptance contracts, and the implementation completion gate.

Documentation commands are described in [Documentation workflow](documentation.md).
