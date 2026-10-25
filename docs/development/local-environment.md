# Local environment

Requirements on the host:

- Docker (with Compose v2+)
- Make

PHP 8.5, PostgreSQL, Composer, and development tools run in containers.
Run commands from the application root.

## Start and stop

```sh
make up
```

Open [http://localhost:8088](http://localhost:8088) to view configuration, task history, and logs.
After source changes, run `make up` again to rebuild the application image.
To choose another port:

```sh
APP_PORT=8092 make up
```

Run `make down` to stop the application while keeping its logs and database data.
The environment is intended for local development. The web service uses PHP's
development server and is exposed only on `127.0.0.1`.

## Reset database and logs

Run `make clean` to stop and remove the Compose containers, including the
documentation preview, and delete the `postgres_data` and `runtime` volumes.
This permanently removes the local database, migration records, and application
logs. Docker images are kept.

To start again with an empty database and the current schema, run:

```sh
make clean
docker compose up -d --wait postgres
make migrate
make up
```

Apply migrations before starting the scheduler so its first execution uses the
current schema. New task history and logs accumulate once the scheduler starts.

## Services and logs

| Service | Purpose |
| --- | --- |
| `postgres` | Runs PostgreSQL with persistent storage and UTC time zones. |
| `adminer` | Provides the database UI at [http://localhost:8090](http://localhost:8090). |
| `scheduler` | Runs the CLI entry point through supercronic every minute. |
| `web` | Serves the adapter UI and its read-only HTTP/SSE endpoints. |
| `check` | Runs tests and quality tools in an isolated container. |
| `docs` | Runs the independent documentation preview. |

The scheduler writes task records to `storage/logs/adapter.log` in the shared
Docker `runtime` volume. The [adapter UI](adapter-ui.md) reads that same file
and displays lifecycle details, durations, and exceptions. `make logs`
follows scheduler process output and adapter errors in the terminal.
See [Adapter](../adapter/index.md) for the invocation cycle and scheduling limits.

See [Database](database.md) for Adminer login details, SQL access, and storage.

## Tests and quality checks

| Command | Purpose |
| --- | --- |
| `make up` | Build and start the application, PostgreSQL, and Adminer. |
| `make down` | Stop services while retaining logs and database data. |
| `make clean` | Stop and remove services and delete the local database and logs. |
| `make logs` | Follow scheduler output and adapter errors. |
| `make psql` | Open an interactive PostgreSQL session. |
| `make test` | Run the implemented application suite in a separate Docker container. |
| `make check` | Run PHPStan, ECS coding-standard checks, and the implemented application suite. |

Tests cover task logging, JSON parsing and validation, cached configuration
snapshots, task service resolution, and execution of configured definitions.
CI runs `make test` and `make check`.

Documentation commands are described in [Documentation workflow](documentation.md).
