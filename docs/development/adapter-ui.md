# Adapter UI

Start the local environment with `make up`, then open
[http://localhost:8088](http://localhost:8088). The UI has three tabs:
configuration, task history, and logs. It is a read-only development tool.
See [Local environment](local-environment.md) for ports and services.

## Configuration

The first tab reads `config/scheduler.json` from the web container. It shows the
source JSON and each schedule's effective settings, including defaults obtained
from the existing configuration parser. Copy the JSON or reload it after a
change. Configuration is loaded on the next scheduler invocation; running task
history retains the configuration captured when that execution was reserved.
After source changes, rebuild with `make up` so web and scheduler use the same
configuration files. The UI does not edit configuration or trigger tasks.

## Task history

Apply the [migrations](migrations.md) before opening history. The tooling reads
`task_runs` directly using a short read-only transaction and the shared
[database configuration](../configuration/services.md#database-boundary). It never calls execution
coordination, changes run state, or returns owner tokens.

Filter by run ID, schedule ID, task class, status, planned time range, start or
completion date, and either protection flag. Text filters match literal
substrings without case sensitivity. All displayed and filtered times use UTC;
range endpoints are inclusive and date filters cover the whole UTC day. Filters
apply on the server to the complete stored history. Results are ordered by
newest run ID, with 25, 50, or 100 rows per page. Use the row's detail button for
the original configuration, deadline, abandonment history, and error diagnostic.

An empty history is normal for schedules with both protections disabled: the
runner executes them without a storage reservation. The UI explicitly explains
that scope. A completed or abandoned row remains visible, and its state is
refreshed with **Obnovit historii**.

## Logs

The log tab reads the shared `storage/logs/adapter.log` JSON file through a
server-sent event stream. It accepts general records without task metadata or a
specific message. Time, severity, channel, message, context, and extra metadata
are displayed as text; there is no ping-based health indicator.

The browser retains the latest 200 received records. Search across messages,
channels, and metadata or select a severity. Expand **Kontext a metadata** to
inspect structured details. Filtering affects only the retained browser records.
Pause/resume controls reception, and clearing the view does not alter the file.
The stream connects while the log tab is active and reconnects automatically;
returning to it loads the current tail. A connected stream confirms transport,
not task success. See [Task execution](../task-execution.md) for lifecycle fields.

## Tooling boundary

HTTP endpoints, history SQL, log parsing, and frontend assets live exclusively
under `tools/adapter-ui/` with the `DevTools\AdapterUi` namespace. Configuration
reuses the application's existing parser and database access reuses the generic
connection factory; no viewer-specific behavior is added to application services.
Tooling backend has no dedicated test suite. Validate changes through static
checks and a browser/API smoke check.
