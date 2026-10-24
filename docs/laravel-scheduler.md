# Laravel Scheduler bootstrap

The current bootstrap prepares an Illuminate container for scheduler callbacks.
Symfony DependencyInjection continues to construct task services and their
dependencies. A scheduled callback can capture `TaskResolver` and retrieve its
task through that existing boundary.

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
facades, change PHP's time zone, or execute scheduled work.

## Integration boundary

`LaravelAdapter` still runs each configured definition once per invocation.
Translation of configuration into Scheduler events and due-time execution remain
the next steps; see [Architecture](architecture.md#current-implementation-and-pending-work).

The `Schedule` constructor resolves both `EventMutex` and `SchedulingMutex`
through Illuminate's global container. Creating a `Schedule` therefore remains
pending until the mutex placeholders and their scoped container wiring are
introduced. Production mutex bindings, cache storage, and locking policies are
not part of this bootstrap.

## Verification

`SchedulerApplicationTest` runs actual Illuminate `CallbackEvent` instances to
verify container injection and task resolution through Symfony. It also checks
the default context, explicit environments, independent container bindings,
and preservation of the existing global container. The callback tests supply a
test-only mutex that throws `LogicException` with a `Not Implemented` message
and event details if called.
No lock operation is needed for these callbacks.
