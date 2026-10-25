# Configuration loading

Configuration is loaded and validated before the adapter builds its execution
plan. The JSON provider converts the file into typed, immutable application
objects and keeps that snapshot in memory. The adapter consumes the same
configuration representation regardless of where it came from.

This approach puts responsibility for interpreting external data at the loading
boundary. Scheduling code can then work with explicit values and defaults
without repeatedly checking the input format.

## Why validate and use typed objects

- **Catch configuration errors early.** Invalid configuration is rejected before
  the adapter builds events from it. A malformed definition cannot silently
  produce a partially accepted configuration.
- **Keep scheduling code focused.** Required fields, types, defaults, and schedule
  validity are handled during loading. Consumers do not need their own array-key
  checks or fallback values.
- **Make changes easier to follow.** Named properties describe what each value
  means and support IDE navigation and static analysis. Changes to the model are
  easier to trace than conventions spread across associative arrays.
- **Keep the application independent.** The configuration model belongs to the
  application, rather than the JSON format, validation library, or scheduler
  framework. Providers and adapters meet at that shared model.

Validation covers configuration data. Task service lookup and construction are
separate responsibilities; see [Task resolution](../task-resolution.md).

## Why use declarative validation

The JSON loader uses `nette/schema` to describe the expected structure, required
fields, types, and defaults together. This keeps the format easier to review and
extend than a growing sequence of manual checks. Structure and type checks are
complemented by scheduling-specific rules, such as cron expression validity and
identifier uniqueness.

The tradeoff is an additional dependency and the need to understand its
validation behavior.
Keeping validation at the loading boundary contains that dependency: the
configuration objects and scheduling code do not depend on Nette.

## Why keep an immutable snapshot

Reading and validating once gives each provider instance a stable view of the
configuration. Repeated access does not repeat file I/O or parsing, and consumers
cannot change the shared definitions accidentally. A file edit during that
instance's lifetime does not change its already loaded schedules.

This favors predictable execution over immediate updates. To use a changed file,
create a new provider and rebuild the execution plan; a long-running process
needs an explicit reload or restart strategy. The current provider does not
watch the file or refresh itself.

## When requirements change

The configuration source, input validation, and refresh policy are separate
choices. Changing one does not require abandoning typed configuration objects
or moving input handling into the adapter. The current requirements do not
justify replacing the JSON loader and its declarative validation.

- **Database provider:** useful when schedules need central administration or
  updates through an application. It would return the same typed configuration
  through the shared provider contract, while handling database availability and
  consistency during loading. The database provider is not implemented yet.
- **JSON Schema:** useful if other tools or applications outside PHP need a
  shared, language-neutral definition for validating the JSON input. PHP object
  mapping and scheduling-specific rules would still be needed. There is
  currently no requirement for that shared schema.

## Format and failure behavior

Loading either returns the complete validated configuration or raises
`ConfigurationException`. It does not skip invalid entries or repair invalid
values silently. The caller decides how to report the failure and whether to
retry.

See the [JSON format](format.md) for fields, defaults, and validation rules, and
[Cron syntax](cron.md) for accepted schedule expressions.
