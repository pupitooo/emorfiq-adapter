# Why SymfonyTaskResolver is needed

The scheduler configuration identifies a task by its fully qualified class name.
`SymfonyTaskResolver` connects that name to a registered Symfony service, so the
container can create the task with its constructor dependencies. `TaskExecutor`
uses the application-owned `TaskResolver` contract, keeping Symfony container
access outside the adapter and executor.

## From configured class name to running task

```mermaid
flowchart TD
    subgraph Registration["Service registration before adapter execution"]
        Tasks["config/services/tasks.php<br/>Register task classes as services<br/>Enable constructor autowiring"]
        Dependencies["Task constructor dependencies<br/>Registered services, interface aliases<br/>and configured argument values"]
        Container["Symfony DI container<br/>Load definitions and compile wiring"]
        Tasks --> Container
        Dependencies --> Container
    end

    Config["1. config/scheduler.json<br/>task = fully qualified class name"]
    Bootstrap["2. bin/run.php<br/>Pass TaskExecutor, scheduler runtime<br/>and SchedulerConfiguration to LaravelAdapter"]
    Container -->|"Create executor with resolver and logger"| Bootstrap
    Config --> Bootstrap
    Bootstrap --> Adapter["3. LaravelAdapter builds the schedule<br/>Register a callback capturing ScheduleDefinition"]
    Adapter --> Callback["4. A due event invokes its callback<br/>TaskExecutor::execute(definition)"]
    Callback --> Executor["5. TaskExecutor calls<br/>TaskResolver::resolve(definition.task)"]
    Executor --> Resolver["6. SymfonyTaskResolver<br/>Look up the registered service by class name<br/>container.has(name) + container.get(name)"]
    Resolver --> Creation["7. The same Symfony DI container<br/>Resolve task constructor dependencies<br/>Create a fresh task instance"]
    Container -.->|"Registered services and wiring"| Creation
    Creation --> Instance["8. SymfonyTaskResolver verifies the Task contract<br/>Return the instance to TaskExecutor"]
    Instance --> Run["9. TaskExecutor calls Task::run()"]
```

The solid arrows show preparation and execution order. The dotted arrow connects
runtime task creation to the service registrations prepared earlier. Task
instances are created only when their callbacks execute.

## How the flow fits together

- **A class name selects the task.** The `task` field in
  `config/scheduler.json` contains a fully qualified PHP class name. The class
  must implement `Task` and be registered as a service under that name in the
  Symfony container. Here, `config/services/tasks.php` automatically registers
  classes from `app/Task/Execution/`; an individual service definition for each
  task is unnecessary. The JSON entry selects a service; it does not register it.
- **Task constructor dependencies must be resolvable.** Each required object
  parameter in a task's constructor needs a corresponding service registration,
  either explicit or automatic. Interface types need a matching service alias or explicit argument
  binding. Scalar arguments need configured values unless a usable default
  exists. For example, `LoggerInterface` is aliased to the configured logger in
  `config/services/logging.php`. The same requirement applies to dependencies
  of those services. See [Task services](task-services.md) for registration details.
- **Symfony DI constructs the complete object.** Autowiring connects registered
  services using constructor parameter types. When the task is requested, the
  container obtains its dependencies and injects them into a fresh task instance.
  Dependencies may themselves be shared services; they are not necessarily
  constructed again. A class name alone, or `new $taskClass()` without arguments,
  cannot supply this configured dependency graph.
- **The adapter receives the executor and configuration.** `bin/run.php`
  retrieves `TaskExecutor` from Symfony DI and passes it to `LaravelAdapter`
  together with `SchedulerConfiguration` and the scheduler runtime. Each parsed
  `ScheduleDefinition` still carries the task class name as a string.
- **The scheduler triggers execution through a callback.** The adapter creates
  the schedule and registers callbacks that capture their definitions. When a
  due event is allowed to run, its callback calls
  `TaskExecutor::execute(ScheduleDefinition $definition)`. Task instances are
  not resolved while the schedule is being built.
- **The resolver bridges the executor and container.** `TaskExecutor` receives a
  `TaskResolver`; the service alias selects `SymfonyTaskResolver`. The executor
  calls `resolve($definition->task)`. The resolver uses its injected Symfony
  container to retrieve the registered service and checks that it implements
  `Task`. The container performs construction and dependency injection. The
  separate Illuminate runtime used by Scheduler does not supply these task
  services.
- **The executor runs the resolved task.** The resolver returns the ready task
  instance to `TaskExecutor`, which calls `Task::run()`. Scheduler invokes the
  callback; `TaskExecutor` performs the actual task invocation. Resolution and
  execution errors follow the existing [task execution policy](task-execution.md).

## Benefits and alternatives

- **Task dependency wiring:** DI supplies the dependencies required by each
  task's constructor.
- **Construction on demand:** Tasks skipped by scheduling conditions are not
  instantiated.
- **Container independence:** `TaskExecutor` uses `TaskResolver` instead of a
  particular container API. The resolver isolates lookup and container error
  translation.
- **Reusable execution:** Another scheduler adapter can use the same executor.
- **Focused tests:** Executor tests can replace the small `TaskResolver`
  contract without setting up a DI container.

Drawbacks of the current design:

- **Additional indirection:** An interface, implementation and service alias
  need maintenance and add another call to follow.
- **Broad container access:** `SymfonyTaskResolver` still receives the whole
  Symfony container.
- **No validation or performance guarantee:** The resolver layer itself does
  not detect configuration errors earlier or improve task construction performance.
  Early task-reference validation is proposed in
  [issue #12](https://github.com/pupitooo/emorfiq-adapter/issues/12)
  and is not implemented in this version.

### Alternatives

Putting the Symfony container directly in `TaskExecutor` preserves dependency
injection, deferred task construction, shared dependencies and fresh task
instances. The resolver adds an explicit boundary around container access; it
does not provide those construction capabilities itself. Direct container access
is a reasonable simpler choice when Symfony is a fixed application dependency.
The executor can still be tested using a container test double.

| Approach | Benefits | Drawbacks |
| --- | --- | --- |
| `new $taskClass()` | Minimal setup for tasks without required constructor dependencies. | The caller must supply constructor arguments and manage their creation and sharing. Task construction becomes coupled to scheduling or execution code. |
| Explicit map of task factories | Makes supported tasks and construction rules visible. Can create instances on demand and use stable task names. | Factory registrations need maintenance. Without DI, dependency wiring is manual; factories that use DI still need container integration. |
| Symfony container inside `TaskExecutor` | Fewer abstractions. Retains DI and construction on demand. | Lookup, `Task` checks and container error handling move into the executor. Its code and tests depend on Symfony's container API; changing containers changes the execution layer. |
| PSR-11 container inside `TaskExecutor` | A standard container interface reduces dependence on Symfony-specific APIs. | The executor still needs lookup, result validation and error handling. The broad container contract does not express that the returned service must be a `Task`; container integration still needs configuration. |
| Laravel container for all application services | One container can handle both the scheduler runtime and task dependencies, simplifying bootstrap wiring. | Existing Symfony service definitions need replacement, including task lifetime and logger wiring. Direct Laravel container access in the executor introduces the same coupling; retaining a resolver retains that abstraction's maintenance cost. |

### Choosing the abstraction

In practice, start with the framework the team prefers, the application already
depends on, and the team is satisfied with. When that framework meets the
application's needs and there is no concrete requirement to support another,
omitting the resolver abstraction can be preferable. Using the chosen
framework's container directly in `TaskExecutor` keeps fewer layers to understand
and maintain.

Supporting multiple implementations can complicate future features. A feature
that uses framework-specific behavior may require changes to the shared contract,
updates to each integration, or additional special cases. The current design also
requires following the adapter, scheduler callbacks, executor, resolver and DI
container to understand how a configured task runs. That cost matters when
deciding whether the flexibility is useful in this application.

A separate resolver remains useful when supporting different container
integrations is an actual requirement. The two-container setup here follows from
combining Symfony-managed application services with the Illuminate scheduler
runtime; it is not required by the `TaskResolver` contract.

## Validate task references before scheduling

This is a proposed improvement, tracked in
[issue #12: Validate task class references before scheduler execution](https://github.com/pupitooo/emorfiq-adapter/issues/12).
It is not implemented by the current parser.

At present, a misspelled task name can pass JSON parsing and fail only when a
due callback asks the resolver for the task. Adding or removing the resolver
layer does not remove that risk. Validation can happen earlier in two stages:

1. **Validate the class in the parser.** Check `class_exists($taskClass)` and
   `is_a($taskClass, Task::class, true)`, and check the canonical class name so
   its spelling matches the service ID exactly. This catches unknown classes
   and classes that do not implement `Task`. These checks may autoload class
   definitions without constructing task instances. PHP documents this behavior
   for [`class_exists()`](https://www.php.net/manual/en/function.class-exists.php)
   and [`is_a()`](https://www.php.net/manual/en/function.is-a.php).
2. **Validate registration before invoking the adapter.** An existing PHP class
   is not necessarily a registered task service. Inspect the compiled
   container's service-definition metadata to check that each configured class
   is available under its expected public service ID. Keep this Symfony-specific
   validation outside the JSON parser. Public lookup is part of the current
   integration's [service visibility](https://symfony.com/doc/current/service_container.html#public-versus-private-services)
   requirement.

Both checks should reject invalid references in every definition, including
tasks that are not currently due, with a `ConfigurationException` identifying
`tasks[n].task`. Use metadata rather than `get()` or `resolve()` during validation
so it does not construct tasks or their dependencies. The issue contains code
examples for both stages and their integration into configuration loading.

Early validation catches class-reference and registration errors. It cannot
guarantee that constructors, factories or external resources will succeed at
runtime. Container compilation detects some dependency-wiring errors; runtime
resolver checks and task failure handling remain necessary.
