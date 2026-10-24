# Emorfiq Scheduler Adapter

A PHP foundation for integrating task schedulers through application-owned
interfaces. Its purpose is to allow scheduling libraries to change without
coupling application code to their types.

The current foundation provides:

- A framework-independent `Adapter` execution contract with `run(): void`.
- A `LaravelAdapter` that runs the example `PingTask` on each invocation.
- A `CrunzAdapter` placeholder that reports `Not Implemented`.
- A Docker environment using **PHP 8.5**, with separate execution and web services.
- Static analysis and coding-standard checks for local development and CI.

Application code is grouped into `Scheduler` and `Task` namespaces. The optional
log viewer lives separately in `tools/log-viewer/` under `DevTools\LogViewer`,
following the [application structure](docs/STRUCTURE_PROPOSAL.md).
The unit test checks `PingTask` logging in memory through Monolog's `TestHandler`.

## Run locally

Requires Docker with Compose v2+ and Make. PHP and Composer run inside Docker;
neither needs to be installed on the host.

```sh
cd src
make up
```

The UI is available at **[http://localhost:8088](http://localhost:8088)** and lets
you view live task logs.
To use a different port, run `APP_PORT=8090 make up`. After changing source files,
run `make up` again to rebuild the application.

The Docker environment is intended for local development. The web service uses
PHP's development server and is exposed only on `127.0.0.1`.

## Makefile commands

| Command | Purpose |
| --- | --- |
| `make up` | Build and start the web server and cron in Docker. |
| `make down` | Stop the application while keeping its logs. |
| `make logs` | Follow cron output and adapter errors in the terminal. |
| `make test` | Run all tests in a separate Docker container. |
| `make check` | Run PHPStan, ECS coding-standard checks, and the PingTask test in a separate Docker container. |

## Runtime connections

- Cron starts the process.
- `bin/run.php` assembles dependencies and calls `Adapter::run()`.
- `LaravelAdapter` is selected by default.
- `CrunzAdapter` implements the same contract as the alternative and currently
  throws `LogicException('Not Implemented')`.
- The adapter handles job execution.

```mermaid
flowchart LR
    Cron["Cron: supercronic<br/>Docker scheduler service"] --> CLI["bin/run.php<br/>compose dependencies"]
    CLI --> Laravel["LaravelAdapter<br/>implements Adapter: run(): void"]
    CLI -. alternative .-> Crunz["CrunzAdapter<br/>implements Adapter: run(): void"]
    Laravel --> Task["Tasks execution"]
    Crunz --> Error["LogicException<br/>Not Implemented"]
```

- `scheduler` starts a new CLI process every second during development.
  Its healthcheck monitors the cron process.
- `SchedulerConfiguration` is an empty configuration contract, not yet connected to the adapters.
- Planned: the adapter translates configuration for the selected scheduler and
  coordinates mutex locking; the scheduler evaluates due jobs and executes them.
  Scheduler and mutex integration are not implemented yet.
