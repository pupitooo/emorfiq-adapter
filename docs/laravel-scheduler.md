# Laravel Scheduler integration

`LaravelAdapter::createSchedule()` converts configuration definitions into
Illuminate Scheduler callback events. Building the schedule does not resolve
or run any tasks. At execution time, each callback uses `TaskResolver` to
retrieve its task through Symfony DependencyInjection and calls `Task::run()`.

## Definition mapping

Each definition produces one callback event, preserving configuration order:

| Definition field | Scheduler mapping |
| --- | --- |
| `id` | Event name via `name()`. |
| `cron` | Expression via `cron()`, with surrounding whitespace trimmed locally. Supported aliases are retained. |
| `timezone` | Event time zone via `timezone()`, including the configuration's UTC default. |
| `task` | Class passed to `TaskResolver` inside the callback when the event runs. |

Each call to `createSchedule()` returns a fresh schedule, so repeated builds
do not accumulate events. The configuration snapshot retains its original
values, including surrounding cron whitespace. See
[Cron syntax](configuration/cron.md) for supported expressions and aliases.

## Runtime context

`config/services/scheduler.php` registers `SchedulerApplication` in the Symfony
container. The service is shared within one CLI invocation and constructed only
when requested. It extends `Illuminate\Container\Container` and binds itself
under its concrete class, the Illuminate container class, and the container
contract, so callback injection receives the current instance.

The context supplies the environment and maintenance methods used by Scheduler:

- `environment()` returns `production` by default. An explicit constructor
  argument can select another environment.
- `isDownForMaintenance()` returns `false`; this application has no Laravel
  maintenance lifecycle.

This is a limited scheduler context, not an implementation of Laravel's full
`Foundation\Application` contract. It uses `illuminate/console` and
`illuminate/container` without installing `laravel/framework`. The existing
`dragonmantank/cron-expression` dependency supplies cron parsing.

Creating this service does not replace Illuminate's global container, initialize
facades, change PHP's time zone, or execute scheduled work. Its
`createSchedule()` method temporarily installs this context as the global
Illuminate container while constructing `Schedule`, then restores the previous
container in `finally`, including on initialization failure.

## Integration boundary

`bin/run.php` retrieves `SchedulerApplication` from the compiled Symfony
container and supplies it to `LaravelAdapter`. For each definition, the adapter
registers a Scheduler callback:

```php
$schedule->call(function () use ($definition): void {
    $this->taskResolver->resolve($definition->task)->run();
})
    ->name($definition->id)
    ->cron(trim($definition->cron))
    ->timezone($definition->timezone);
```

Task resolution occurs inside the callback. Task failures propagate to the
caller and stop processing further definitions.

`run()` currently executes every registered event once using
`$event->run($this->application)`. Cron and time zone metadata are registered,
but due-time filtering is not yet applied. Each configured definition therefore
still runs on every invocation. Due-time evaluation is the next step; see
[Architecture](architecture.md#current-implementation-and-pending-work).

## Mutex placeholders

The `Schedule` constructor requires both `EventMutex` and `SchedulingMutex`,
even when no locking feature is enabled. `SchedulerApplication` binds these
interfaces to `NotImplementedEventMutex` and `NotImplementedSchedulingMutex`.
Every lock operation throws `LogicException('Not Implemented')`.

Ordinary callback execution does not use these lock operations.
`withoutOverlapping()` and single-server execution remain unsupported; no
locking options have been added to JSON configuration or cache storage.

## Verification

`SchedulerApplicationTest` runs actual Illuminate callbacks and schedules to
verify container injection, task resolution through Symfony, independent plans,
and restoration of the global container on success and failure. It also checks
that overlapping and single-server execution fail explicitly through the mutex
placeholders.

`LaravelAdapterTest` verifies mapping of identifiers, cron expressions, aliases,
and time zones without resolving tasks. Existing execution tests cover task
order, empty configurations, and propagation of task resolution failures.
