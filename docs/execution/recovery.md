# Recovery scenarios

Recovery releases stale execution ownership so later occurrences can run.
It does not retry the abandoned occurrence or reverse its effects. These are
requirements for the future coordinator, not implemented runtime behavior.
The [coordination contract](coordination.md) defines normal execution and indexes.

## Process crash and expired lease

A process can disappear after committing its reservation. `finally` cannot
reliably clean up after a killed process, machine failure, or power loss.
Passing `lease_expires_at` does not change `status`; explicit recovery is needed.

```mermaid
sequenceDiagram
    participant A as Server A
    participant DB as PostgreSQL
    participant B as Server B
    A->>DB: Commit 10:00 claim, lease until 10:05
    A->>A: Process crashes
    Note over DB: 10:05 passes, status is still in_progress
    B->>DB: Attempt protected insert without recovery
    DB-->>B: Active-schedule conflict remains
    B->>DB: Conditionally abandon expired active row
    DB-->>B: One row changed
    B->>DB: Reserve 10:06 and commit
    DB-->>B: New owner
    B->>B: Task::run()
```

The coordinator should recover the relevant schedule before attempting its next
claim. A periodic sweep can additionally keep history current for schedules that
have no new due occurrence. Recovery must ignore live leases and terminal rows,
be repeatable, and leave unrelated schedules untouched. History is retained.
`finished_at` on abandonment records when loss was recognized, not a proven task
completion time.

The occurrence index still contains the abandoned 10:00 row when `onOneServer`
was enabled. Reclaiming 10:00 is rejected; claiming 10:06 is allowed. Automatic
retry, catch-up, and deduplication-history deletion are outside this contract.

## Crash windows and uncertain outcomes

The point of failure determines what we know about effects, but not whether the
old lease must eventually be recovered.

```mermaid
sequenceDiagram
    participant R as Runner
    participant DB as PostgreSQL
    participant E as External system
    R->>DB: Commit reservation
    alt Crash before task invocation
        Note over R: Task never starts
    else Crash during task
        R->>E: Some operations may complete
        Note over R: Process stops before finishing
    else Crash after effects
        R->>E: Complete business operation
        E-->>R: Success
        Note over R: Crash before saving succeeded
    else Completion write unavailable
        R->>E: Complete business operation
        R-xDB: Cannot confirm completion
    end
    Note over DB: Row remains in_progress until explicit recovery
    DB->>DB: Later recovery marks abandoned
```

The last two cases can have fully completed effects and an abandoned history
row. Recovery must never infer failure or success merely from lease expiration.
A task exception is `failed` only when the current owner can persist that result.
If the completion write is unavailable, the outcome remains unconfirmed.

## Healthy long task and lease renewal

A live owner can renew before expiry. Renewal keeps the same token and extends
the deadline; recovery must then leave the row active. The runner needs a real
renewal mechanism during a blocking `Task::run()`, such as a supervised worker or
cooperative checkpoints. A loop after `Task::run()` cannot renew a running task.

```mermaid
sequenceDiagram
    participant W as Worker
    participant DB as PostgreSQL
    participant S as Recovery sweep
    W->>DB: Claim at 10:00, deadline 10:05
    W->>W: Task keeps running
    W->>DB: Renew at 10:04 with matching owner token
    DB-->>W: Deadline becomes 10:09
    S->>DB: Recover at 10:05
    DB-->>S: Nothing expired
    W->>DB: Complete before 10:09
    DB-->>W: Mark succeeded
```

Renewal at or after the deadline is rejected, even if nobody has recovered the
row yet. An expired owner cannot bring itself back to life. A DB outage during
renewal means ownership cannot be confirmed; the runner must signal ownership
loss to cooperative tasks and stop authorizing new protected operations. It
cannot promise to cancel an already submitted external request.

## Renewal racing with recovery

Reading the row and deciding later is insufficient. Both operations must enforce
their conditions atomically under database concurrency.

```mermaid
sequenceDiagram
    participant W as Worker
    participant DB as PostgreSQL
    participant S as Recovery sweep
    W->>DB: Conditional renewal near deadline
    S->>DB: Conditional recovery near deadline
    alt Renewal wins while lease is valid
        DB-->>W: Extend deadline
        DB-->>S: Recheck row, renewed lease is live
    else Recovery wins after expiry
        DB-->>S: Change in_progress to abandoned
        DB-->>W: Recheck row, renewal rejected
    end
    Note over DB: No abandoned row can be resurrected
```

Tests must exercise both controlled orders with two real connections and
barriers, including a statement that waits across the deadline. No sleeps or
single-threaded in-memory imitation can establish this database guarantee.

## Two servers recovering and claiming

Both servers can observe a stale row. Only one can perform its state transition;
both can then compete for the next protected occurrence. The unique indexes
select one winner. The losing runner must not invoke the task.

```mermaid
sequenceDiagram
    participant A as Server A
    participant DB as PostgreSQL
    participant B as Server B
    A->>DB: Recover expired row
    B->>DB: Recover same expired row
    DB-->>A: One transition
    DB-->>B: Already recovered, no transition
    A->>DB: Reserve 10:06
    B->>DB: Reserve 10:06
    DB-->>A: Commit winner
    DB-->>B: Conflict, skip
    A->>A: Task::run()
```

Recovery and reservation must leave valid state even when the new insert loses
or rolls back. They must not erase historical rows. Conflict handling must not
continue issuing SQL in a failed PostgreSQL transaction without rollback or an
appropriate savepoint. The implementation can use a conflict-safe insert.

## Late worker completion

A paused process may resume after its row was abandoned and another run began.
Its original token does not authorize completion or renewal anymore.

```mermaid
sequenceDiagram
    participant A as Old worker
    participant DB as PostgreSQL
    participant B as New worker
    B->>DB: Abandon expired old run and claim next occurrence
    DB-->>B: New run ID and owner token
    A->>DB: Finish old run with old token
    DB-->>A: Rejected, old state remains abandoned
    A->>DB: Renew old run
    DB-->>A: Rejected
    A->>DB: Finish new run using old token
    DB-->>A: Rejected, new state unchanged
```

This protects scheduler history. It does not stop external operations; see
[external effects](external-effects.md). The [test plan](verification.md) maps
these scenarios to executable acceptance tests and states what remains missing.
