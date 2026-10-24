# Log viewer

Start the local environment with `make up`, then open
[http://localhost:8088](http://localhost:8088). The viewer reads the shared JSON
log file through a server-sent event stream and displays the latest 200 received
records. See [Local environment](local-environment.md) for ports and services.

## Inspect execution

Lifecycle records display their schedule ID in the Task column. Task-owned
messages without a schedule ID display their `context.task` value. ERROR and
higher levels use a red badge; WARNING uses amber.

Expand **Context** beside a message to see its full JSON context, including
schedule ID, task class, event, elapsed milliseconds, and serialized exception
with stack trace. Values are displayed as text, including error messages that
contain HTML. The stream preserves the full context of valid records and still
accepts existing task messages such as `ping`.

The ping indicator tolerates a 90-second gap to accommodate the current
one-minute cron interval. It monitors `PingTask` messages, not the success of
all tasks. Inspect lifecycle failure records and scheduler process output for
other failures. A connected stream only indicates that the viewer is connected.

See [Task execution](../task-execution.md) for lifecycle fields and failure
handling. The viewer is a local development tool, not durable monitoring or an
alerting system.
