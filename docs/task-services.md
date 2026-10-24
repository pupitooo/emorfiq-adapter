# Task services

Tasks implement `App\Task\Contract\Task` and expose `run(): void`.
Schedules select a task by its fully qualified class name; see
[Scheduler JSON](configuration/format.md) for the configuration format.

## Service configuration

`config/services.php` imports service definitions grouped by responsibility:

- `config/services/logging.php` defines the Monolog JSON formatter, locking file handler,
  and shared logger, aliased to the PSR-3 `LoggerInterface`.
- `config/services/tasks.php` automatically registers classes from `app/Task/Execution/`
  and enables constructor autowiring. This directory contains only concrete tasks;
  contracts and infrastructure live in separate directories.
  An `instanceof` rule makes services implementing `Task` public and non-shared.
  The file also registers `SymfonyTaskResolver` against the current container and
  aliases it to `TaskResolver`.
- `config/services/scheduler.php` registers `SchedulerApplication` and autowires
  `TaskExecutor` with the resolver and the shared logger.

`bin/run.php` loads this configuration with Symfony's `PhpFileLoader`, compiles the
container for each CLI invocation, and retrieves the configured execution services.
`TaskExecutor` depends on the application-owned `TaskResolver` contract. Tasks implement `Task::run(): void`.

Task classes are registered automatically under their fully qualified class names,
matching the `task` values in `config/scheduler.json`. The resolver retrieves registered
services; it does not discover or construct arbitrary classes. Each task service is public
and non-shared, producing a fresh task for each schedule definition. Logger dependencies
remain private and shared within the process.

## Add a task

To add a task, create a class implementing `App\Task\Contract\Task` in
`app/Task/Execution/` with the `App\Task\Execution` namespace, and add its schedule to
`config/scheduler.json`. No individual service registration is needed.

Autowiring supplies registered dependencies based on constructor types, including
`LoggerInterface`. Define new dependencies in the appropriate service configuration
file; additional areas can have their own files imported from `config/services.php`.
Defaults and the task rule apply only within their configuration file, so task settings do not affect
the shared logger or other services.

## Container and failures

The container uses `symfony/dependency-injection` and `symfony/config` directly.
The Symfony framework and YAML loader are not required by this PHP configuration.

`SymfonyTaskResolver` raises `LogicException` for an unregistered task or a service
that does not implement `Task`. Container resolution failures are wrapped in
`LogicException` with the original exception preserved as the previous exception.

During scheduled execution these failures are logged and handled like task
execution failures; see [Task execution](task-execution.md).

For the complete invocation flow and current limitations, see
[Architecture](architecture.md#execution-flow).
