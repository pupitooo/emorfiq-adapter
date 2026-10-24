# Laravel Scheduler integration

`LaravelAdapter::createSchedule()` converts configuration definitions into
Illuminate Scheduler callback events. Building the schedule does not resolve
or run any tasks. At execution time, each callback uses `TaskResolver` to
retrieve its task through Symfony DependencyInjection and calls `Task::run()`.

## Execution cycle

Cron starts `bin/run.php` every minute. Our entry point and adapter serve as the
application's scheduler runner in place of invoking Artisan's `schedule:run`.
Registering events describes the plan; Laravel's `dueEvents()` selects which
events are eligible at the current time. The adapter uses those results:

```php
$schedule = $this->createSchedule();
$events = $schedule->dueEvents($this->application);
foreach ($events as $event) {
    if (! $event->filtersPass($this->application)) {
        continue;
    }

    $event->run($this->application);
}
```

Laravel evaluates each expression in its event time zone, including supported
cron aliases. `filtersPass()` applies the event's additional conditions. Skipped
events never resolve or construct their tasks. Events that pass are executed
in configuration order. An empty configuration or a plan with no due events
executes nothing.

One invocation evaluates the current schedule and returns; cron provides the
next invocation. It does not wait for future execution times or record completed
time slots. Calling it again in the same minute may execute the same due events
again. See Laravel's [Running the Scheduler](https://laravel.com/docs/13.x/scheduling#running-the-scheduler)
and [ScheduleRunCommand](https://github.com/illuminate/console/blob/13.x/Scheduling/ScheduleRunCommand.php)
for the corresponding standard entry point and orchestration.

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

Illuminate's PHPDoc expects the full `Foundation\Application` contract and
omits the element type of the collection returned by `dueEvents()`. The adapter's
local PHPStan annotations describe the actual event collection and the limited
context used by these APIs: environment and maintenance methods for due-time
selection, and the Illuminate container for event filters. The integration tests
exercise these calls against the installed Illuminate implementation.

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

`run()` delegates selection to Laravel and invokes only the selected events;
see [Execution cycle](#execution-cycle) for the runner flow and
[Architecture](architecture.md#current-implementation-and-pending-work) for
remaining integration work.

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

`DueEventsTest` freezes Laravel's clock and runs actual Symfony task services.
It covers selection across consecutive minutes, Prague and New York time zones
in summer and winter, the UTC default regardless of PHP's time zone, cron
aliases, and a plan with no due work. An unregistered task is skipped when not
due. A task execution failure propagates immediately, before later tasks can
be resolved. Each scenario restores the previous clock state in `finally`.
