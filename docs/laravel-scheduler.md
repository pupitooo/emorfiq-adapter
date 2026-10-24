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
| `cron` | Validated expression via `cron()`, passed unchanged. Supported aliases are retained. |
| `timezone` | Event time zone via `timezone()`, including the configuration's UTC default. |
| `task` | Class passed to `TaskResolver` inside the callback when the event runs. |

Each call to `createSchedule()` returns a fresh schedule, so repeated builds
do not accumulate events. The parser rejects invalid values, including
[surrounding whitespace](configuration/format.md#string-validation), so the adapter
uses the configuration values directly. See
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
`createSchedule()` method resolves the two mutex services from this context and
passes them directly to `Schedule`. Schedule construction leaves the
global container untouched, including while resolving dependencies and on failure.

### Schedule construction

`App\Scheduler\Infrastructure\Laravel\Runtime\Schedule` extends Illuminate's
`Schedule` and replaces only its constructor. It assigns the supplied `EventMutex` and `SchedulingMutex` to the
inherited protected properties, bypassing the upstream constructor's global
container lookup. Event registration and execution continue to use Illuminate's
implementation; the adapter sets time zones on individual events.

This small compatibility boundary depends on the upstream protected properties
and constructor initialization. Review it when upgrading Illuminate. Other
Illuminate APIs that access the global container, such as `command()` and
`job()`, are outside the current callback-based adapter.

## Integration boundary

`bin/run.php` retrieves `SchedulerApplication` from the compiled Symfony
container and supplies it to `LaravelAdapter`. For each definition, the adapter
registers a Scheduler callback:

```php
$schedule->call(function () use ($definition): void {
    $this->taskResolver->resolve($definition->task)->run();
})
    ->name($definition->id)
    ->cron($definition->cron)
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

`Schedule` accepts both `EventMutex` and `SchedulingMutex` explicitly,
even when no locking feature is enabled. `SchedulerApplication` binds these
interfaces to `NotImplementedEventMutex` and `NotImplementedSchedulingMutex`.
Every lock operation throws `LogicException('Not Implemented')`; implementing
the lock behavior is deferred.

Ordinary callback execution does not use these lock operations.
`withoutOverlapping()` and single-server execution remain unsupported; no
locking options have been added to JSON configuration or cache storage.

## Verification

`SchedulerApplicationTest` runs actual Illuminate callbacks and schedules to
verify container injection, task resolution through Symfony, independent plans,
and preservation of the global container during dependency resolution and on
success and failure. It also checks
that overlapping and single-server execution fail explicitly through the mutex
placeholders.

`LaravelAdapterTest` verifies mapping of identifiers, cron expressions, aliases,
and time zones without resolving tasks. Existing execution tests cover task
order, empty configurations, and propagation of task resolution failures.
