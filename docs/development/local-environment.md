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
APP_PORT=8090 make up
```

Run `make down` to stop the application while keeping its logs.
The environment is intended for local development. The web service uses PHP's
development server and is exposed only on `127.0.0.1`.

## Services and logs

| Service | Purpose |
| --- | --- |
| `scheduler` | Runs the CLI entry point through supercronic every minute. |
| `web` | Serves the live log viewer and its HTTP/SSE endpoints. |
| `check` | Runs tests and quality tools in an isolated container. |
| `docs` | Runs the independent documentation preview. |

The scheduler writes task records to `storage/logs/adapter.log` in the shared
Docker `runtime` volume. The web viewer reads that same file. `make logs`
follows scheduler process output and adapter errors in the terminal.
See [Architecture](../architecture.md) for the invocation flow and current scheduling limits.

## Tests and quality checks

| Command | Purpose |
| --- | --- |
| `make up` | Build and start the web server and cron. |
| `make down` | Stop the application while retaining the runtime volume. |
| `make logs` | Follow scheduler output and adapter errors. |
| `make test` | Run the complete test suite in a separate Docker container. |
| `make check` | Run PHPStan, ECS coding-standard checks, and the complete test suite. |

Tests cover task logging, JSON parsing and validation, cached configuration
snapshots, task service resolution, and execution of configured definitions.
CI runs both `make test` and `make check`.

Documentation commands are described in [Documentation workflow](documentation.md).
