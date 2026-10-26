# Mutex design choices

The first version deliberately follows Laravel's default scheduling behavior:
`onOneServer` reserves a scheduled occurrence, and `withoutOverlapping` rejects
another occurrence while an overlap reservation is live. A lock conflict skips that invocation;
the overlap deadline is fixed and does not stop the running process.

The assignment requires an application-owned mutex implementation. Owning the
coordination layer lets us change these policies as project requirements become
clearer; those additional requirements are not yet defined. The first version
establishes a familiar baseline rather than assuming which extensions will be
needed.

Compared with Laravel's default scheduler mutexes, this version already adds:

- **Atomic history and locks:** the admitted run, its configuration snapshot,
  and all requested locks commit together before task resolution.
- **Owner-aware completion:** a late result updates the original run and cannot
  release a replacement owner's overlap lock.
- **Recorded recovery:** reclaiming an expired lock records abandonment, which
  remains visible even if the original run later reports its outcome.
- **Retained occurrence reservations:** the full UTC occurrence remains reserved
  after completion or recovery, until its lock is explicitly removed.

The two Illuminate mutex interfaces share one reservation through
`ScheduledExecution`; PostgreSQL stores its history and locks in one transaction.
See [Layers and lifecycle](layers.md) for the calls and components.

## First-version scope

Changing the cache backend, using owner-aware cache locks, or adding application
services can also address some requirements within Laravel. The comparison
below describes current behavior; [Possible extensions](extensions.md) separates
future work from guarantees already implemented.

Atomicity here covers scheduler history and lock rows only. Business writes made
inside a task are outside these transactions.

## Current behavior

| Situation | Chosen mechanism |
| --- | --- |
| Several scheduler processes select the same scheduled minute. | An occurrence reservation selects one winner when `onOneServer` is enabled. |
| Another occurrence becomes due while a previous execution holds a live overlap lock. | A separate overlap lock blocks the new run when `withoutOverlapping` is enabled. |
| Both protections are enabled for one event. | Both Illuminate gates and the callback reuse one `RunClaim`; requested locks and the run record commit together. |
| A worker with overlap protection crashes, or an expired worker later reports completion. | Expiry recovery records abandonment; completion identifies the original run and owner, preserving a replacement run's lock. |
| Operators need to explain what was scheduled and what happened. | Each accepted run retains its configuration snapshot, state, and reported outcome, including runs with both protections disabled. |

`ExecutionMutex` expresses these lifecycle operations independently of Illuminate.
The Laravel mutex classes translate scheduling gates into that contract, while
`TaskRunStorage` owns persistence. This keeps SQL and recovery decisions out of
the scheduling hooks and task implementations.

The shared context matters because `onOneServer`, `withoutOverlapping`, and the
callback can all ask for permission to run. They must reuse the same reservation
instead of competing with each other or creating multiple history rows. A
conflict leaves no new run or partially acquired locks; recovery of older expired
locks still commits. See [Coordination contract](../coordination/contract.md).

## Limits and costs

- **Database availability is required for every run.** Even with both protections
  disabled, reservation and completion persist history. Storage failures stop
  the invocation; an unknown reservation commit outcome does not permit task
  execution or an automatic retry.
- **The application owns more code.** Shared execution state, error translation,
  expiry/completion races, and compatibility with Illuminate's hooks need
  maintenance and integration tests.
- **Coordination adds database work.** Each run requires transactions, writes,
  indexes, and connections. Competing runs can wait on database locks. No
  throughput advantage over a cache-backed design has been established.
- **Expiry is a recovery boundary, not task cancellation.** A worker can continue
  after its overlap lock expires. There is no renewal, worker termination, or
  general fencing of business writes. See [Recovery](../failure-and-recovery/recovery.md) and
  [External effects](../failure-and-recovery/external-effects.md).
- **This is not a durable work queue.** Conflicts skip execution. There is no
  automatic retry, catch-up, or guarantee that a task starts after its reservation
  commits. A crash can occur between commit and invocation.
- **Stored state needs a retention policy.** Occurrence reservations have no
  automatic expiry. Removing one allows that occurrence to run again. A crashed
  run without overlap protection has no recovery deadline and can remain
  `in_progress`. See [History and locks](../coordination/history.md#relationship-and-retention).

## Compared with Laravel's default mutexes

Laravel already provides production mutexes backed by a shared cache, including
a database cache driver. Choosing PostgreSQL therefore does not, by itself,
justify replacing them. See Laravel's
[overlap protection](https://laravel.com/docs/13.x/scheduling#preventing-task-overlaps)
and [single-server scheduling](https://laravel.com/docs/13.x/scheduling#running-tasks-on-one-server).

The comparison below uses `illuminate/console` **v13.38.0**, currently pinned in
`composer.lock`, specifically
[`CacheEventMutex`](https://github.com/illuminate/console/blob/c09de725a41256ad90f970db9efad798f0beb9e2/Scheduling/CacheEventMutex.php)
and
[`CacheSchedulingMutex`](https://github.com/illuminate/console/blob/c09de725a41256ad90f970db9efad798f0beb9e2/Scheduling/CacheSchedulingMutex.php).
It describes those scheduler implementations, rather than every use of
Laravel's cache lock API.

| Concern | Default Laravel scheduler mutexes | This adapter |
| --- | --- | --- |
| Two enabled protections | Separate cache mutex acquisitions at their respective scheduling gates. | One shared claim commits both requested locks and the run record together. |
| Execution history | The mutexes manage cache keys; run history needs separate application logic. | History and ownership are part of reservation and completion. |
| Occurrence retention | The scheduling mutex key includes the scheduled hour/minute and expires after one hour. | A lock keyed by schedule ID and full UTC occurrence remains until explicitly removed. |
| Overlap expiry | The cache lock has a configured lifetime; the cache backend handles expiry. | A stored deadline is checked during recovery, which removes the expired lock and records abandonment. |
| Overlap cleanup | `CacheEventMutex::forget()` uses `forceRelease()` on its lock-provider path, or deletes the named cache key. | `finish()` updates the original run and removes only its overlap lock by run ID and owner token. `forget()` clears local context. |
| Commit uncertainty | The cache mutex methods expose acquisition through the cache API. | A separate reservation error identifies an unconfirmed database commit and prevents task invocation. |

Consider the same situation in both implementations: worker A's overlap lock
expires, worker B acquires a new lock for the same schedule, and A finishes late.

- **Default Laravel Scheduler:** `CacheEventMutex::forget()` releases the lock
  identified by the event's cache key. On its `forceRelease()` path, it does not
  check whether A still owns that lock. A's cleanup can therefore remove the
  replacement lock now held by B.
- **Our adapter:** `ScheduledExecution` reports A's result through
  `finish()` → `complete()`. Storage updates A's history row and deletes an
  overlap lock only when both the run ID and owner token match A's `RunClaim`.
  B's lock has a different run ID and token, so it remains intact. Our
  `CoordinatedEventMutex::forget()` only resets the cached claim and attempt
  flag in memory; it does not delete database locks.

Laravel's general [atomic lock API](https://laravel.com/docs/13.x/cache#managing-locks-across-processes)
also supports release using an owner token. The comparison above concerns the
default **Scheduler's `CacheEventMutex::forget()` implementation**, not a
limitation of all Laravel locks.

### Shared limits and available Laravel primitives

Both implementations use a fixed overlap deadline without automatic renewal.
Our expiry recovery additionally records abandonment; it does not establish
that the old process has stopped. Our default duration is 60 minutes, while
Laravel's documented `withoutOverlapping()` default is 24 hours.

Laravel's general cache lock API already supports owner-aware release. In
`illuminate/cache` v13.38.0, both
[`RedisLock::refresh()`](https://github.com/illuminate/cache/blob/v13.38.0/RedisLock.php)
and
[`DatabaseLock::refresh()`](https://github.com/illuminate/cache/blob/v13.38.0/DatabaseLock.php)
can renew a still-owned, unexpired lock. Support depends on the driver; the base
[`Lock::refresh()`](https://github.com/illuminate/cache/blob/v13.38.0/Lock.php)
throws when unsupported. The default scheduler mutex does not retain and renew
that lock during task execution. An application must arrange renewal and handle
ownership loss itself. Renewal is therefore a possible extension on either
platform, not a unique benefit of our coordinator.

Both approaches still need task-level protection against duplicate or stale
business effects. Neither a cache lock nor our database reservation can stop an
already running process merely because its deadline passed.
See [Owner tokens](../coordination/history.md#owner-tokens) for completion protection and
[Fencing](../failure-and-recovery/external-effects.md#fencing-stale-owners) for the separate protection of business writes.

Changing the backend can improve deployment durability in either implementation.
It does not by itself change scheduler cleanup, add renewal, or provide fencing.
PostgreSQL storage is not an unconditional failover guarantee.

## Why PostgreSQL

PostgreSQL fits this application's requirement to keep coordination and durable
run history consistent:

- **One transaction per transition:** reservation and completion each commit
  their history and lock changes together. There is no separate cache/history
  write that can succeed while the other fails.
- **Database-enforced competition:** unique indexes choose a winner for each
  protected key. Advisory existence checks are not relied on to authorize a run.
- **Short database locks:** row locks protect transitions and races;
  coordination transactions and their row locks end before `Task::run()`.
- **A shared expiry clock:** the database supplies transition times and checks
  deadlines, avoiding different worker clocks deciding overlap expiry.
- **Deployment assumption:** the target project is expected to already operate
  a database that can also store scheduler history and coordination state.
  Reusing that database can avoid introducing another service solely for
  scheduler coordination. This implementation uses PostgreSQL; a project with
  another database would need a compatible storage implementation.

This is a consistency and operational simplicity choice for the current project,
not a claim that SQL is always faster or safer than other production backends.
See [Storage transactions](../coordination/storage.md) for the implementation.

### Availability and failover assumptions

All schedulers must use the same authoritative database and schedule IDs. The
deployment must preserve accepted reservations across failover and prevent
independent writable primaries. PostgreSQL's default asynchronous replication
can lose committed writes on promotion of a lagging standby; deployment durability
settings therefore remain part of the guarantee. Database transactions alone
do not settle this operational choice. See
[PostgreSQL replication](https://www.postgresql.org/docs/18/warm-standby.html#SYNCHRONOUS-REPLICATION).

## Production alternatives

These are candidates for a new implementation, not backends currently selectable
by configuration. The current `TaskRunStorage` combines locking and run history.
A complete replacement must preserve both; moving only locks requires an
explicit integration with history persistence.

| Alternative | When it is a reasonable choice | Work and tradeoffs for this application |
| --- | --- | --- |
| Another transactional relational database, such as MySQL/InnoDB | The deployment already standardizes on it and wants history and locks in one transactional system. | Port the PostgreSQL-specific schema, uniqueness rules, SQL, time handling, and error classification; rerun the concurrency and recovery suites. InnoDB provides [transactional locking](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking.html), but the current SQL is not portable unchanged. |
| Redis as a representative key-value store | Redis is already operated for coordination, and measured load or latency justifies moving lock traffic there. | Implement atomic lock acquisition, owner-checked release, and expiry. Address history integration separately, as described below. |

Moving coordination to Redis would require the following changes:

**Redis for locking**

- Acquire both requested protections atomically; a conflict must leave no
  partially acquired locks.
- Check ownership before release; a late worker must not delete a replacement
  owner's lock.
- Keep lock lifetimes distinct: overlap locks expire, occurrence reservations
  survive that expiry.
- Preserve accepted reservations across memory pressure, restarts, and failover;
  account for [eviction](https://redis.io/docs/latest/develop/reference/eviction/),
  [persistence](https://redis.io/docs/latest/operate/oss_and_stack/management/persistence/),
  and [replication behavior](https://redis.io/docs/latest/develop/clients/patterns/distributed-locks/).
- Treat an unconfirmed acquisition as an infrastructure error; do not invoke
  the task or blindly retry.

**History stored separately**

- History may remain in PostgreSQL, but it will no longer share one transaction
  with Redis locks as it does in today's `TaskRunStorage`.
- Define when a run is confirmed and how to repair partial failures, such as a
  successful Redis acquisition followed by a failed history insert. Repair must
  never release a newer owner's lock.
- Retain the metadata needed to record abandonment and accept late results.

The first version makes no claim to automatic retries, renewable leases,
resource-level concurrency, or receiver-side protection. Those require explicit
protocols and integration work; see [Possible extensions](extensions.md).
