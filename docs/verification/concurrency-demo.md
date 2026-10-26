# Manual concurrency testing

The sample `config/scheduler.json` keeps the unprotected `ping` and adds three
e-commerce simulations plus worker-crash and late-completion demos. The simulations only
wait and log progress; they do not generate files, send reports, or connect to any service.

All sample tasks live directly in `app/Task/Execution/`, under the
`App\Task\Execution` namespace.

## Schedules

Each demo runs for 210 seconds and logs progress every 30 seconds. Their schedules
repeat every three minutes, offset by one minute. Times below are illustrative
UTC times; the same pattern repeats throughout every hour.

| Schedule ID | Task | Due minutes | Protection |
| --- | --- | --- | --- |
| `product-feed` | `GenerateProductFeedTask` | 00, 03, 06, … | `withoutOverlapping` |
| `sales-report` | `PrepareSalesReportTask` | 01, 04, 07, … | `onOneServer` |
| `warehouse-stock-sync` | `SyncWarehouseStockTask` | 02, 05, 08, … | Both |

Both protected execution locks expire after five minutes, leaving 90 seconds
beyond the normal simulation duration. Expiration does not stop a process.
See [Concurrency](../configuration/concurrency.md) for the lock semantics.

## Observe execution

Apply [migrations](../development/migrations.md) before starting the scheduler. Rebuild and start
the local environment with `make up`, then open the
[adapter UI](http://localhost:8088/) to follow history, locks, and logs.
Supercronic uses `overlapping` so each minute can invoke a new scheduler process
while an earlier invocation is still running. Events within one invocation still
execute sequentially; `ping` is listed first so its heartbeat stays immediate.

Starting from 10:00:

- **Feed:** starts at 10:00, skips 10:03 while busy, finishes around 10:03:30,
  and starts again at 10:06. Skipped occurrences are not queued.
- **Sales report:** starts at 10:01 and again at 10:04. Until 10:04:30, the two
  different occurrences run concurrently.
- **Stock sync:** starts at 10:02, skips 10:05 while busy, finishes around
  10:05:30, and starts again at 10:08.

To observe server coordination, start a second scheduler instance:

```sh
docker compose up -d --no-recreate --scale scheduler=2 scheduler
```

Both instances share PostgreSQL and schedule IDs. For a given scheduled minute,
only one instance runs the sales report or stock sync. The winning instance can
change between occurrences. Feed protection only prevents concurrent execution;
it does not deduplicate an occurrence after completion.

Return to one instance with:

```sh
docker compose up -d --no-recreate --scale scheduler=1 scheduler
```

## Observe abandoned runs

`worker-crash` resolves `SimulateWorkerCrashTask` every minute, with both
protections enabled and a one-minute overlap expiration. It is listed last:
earlier due tasks finish before it runs, so the simulations can delay its start.

The task logs a critical `demo.task.crashed` event and calls `exit(1)`, terminating
the current scheduler invocation without recording completion. A thrown exception
would be caught and recorded as `failed`; exiting instead simulates a worker crash.
Supercronic remains running and starts later scheduler invocations normally.

The history row initially stays `in_progress`. The next reservation attempt for
`worker-crash` after its lock expires marks it `abandoned`, with `abandoned_at`
and `abandoned_by` populated and `finished_at` empty. Every run that reaches this
task follows that path; the newest row may still await recovery. Expiration alone
does not update history, and stopping the scheduler delays recovery until it runs
again. See [Recovery scenarios](../failure-and-recovery/recovery.md) for the transition rules.

## Observe late success after abandonment

`late-completion` resolves `SimulateLateCompletionTask` every minute, with both
protections enabled and a one-minute overlap expiration. It runs after the
e-commerce simulations and before `worker-crash`, so earlier due tasks can delay
its start. Keep overlapping Supercronic invocations enabled as described above.

The task logs `demo.task.waiting`, sleeps for 150 seconds without logging or
renewing its lock, logs `demo.task.resumed`, and returns normally. During that
wait, a later invocation can reserve the same schedule after its overlap lock
expires. This marks the old run `abandoned` and starts a new occurrence even
though the original process is still sleeping. `onOneServer` prevents duplicate
execution of the same occurrence, not later occurrences after overlap expiry.

Watch history for the original run ID: `in_progress` → `abandoned` → `succeeded`.
After it wakes, the scheduler records success on that original row, sets
`finished_at`, and preserves `abandoned_at` and `abandoned_by`. It does not release
the newer run's lock. The UI uses `succeeded` for successful completion.

The intermediate state requires another reservation attempt during the wait;
running the task once or stopping the scheduler before recovery only demonstrates
late success after expiration. See [Long tasks without renewal](../failure-and-recovery/recovery.md#long-tasks-without-renewal)
for the full lifecycle. The later `worker-crash` task cannot undo this success,
because completion has already been persisted before that task starts.
