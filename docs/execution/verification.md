# Recovery verification

## Current status and commands

Schema migrations, production coordination, runner integration, and PostgreSQL
storage are implemented. `make test-storage` exercises production storage in
isolated schemas, including concurrent PHP workers. `make test-recovery` runs all
43 acceptance scenarios through production coordination, the Laravel runner,
real worker processes, and receiver fixtures.

| Command | Scope | Current result |
| --- | --- | --- |
| `make check` | PHPStan, ECS, and application tests | Must pass; required in CI. |
| `make test-db` | Migrations 001 and 002, upgrade history, and partial indexes | Must pass; required in CI. |
| `make test-storage` | 22 production PostgreSQL storage scenarios, including races and commit failures | Must pass; required in CI. |
| `make test-recovery` | 43 named acceptance scenarios | Must pass; required in CI. |
| `docker compose run --rm --no-deps -T check composer test:recovery:list` | List acceptance scenarios without executing them | Lists 43 scenarios; rebuild `check` after source changes. |

`make test-db` streams the real migrations and SQL fixtures into an isolated
transactional schema and rolls everything back. Its 14 scenarios verify upgrade
compatibility, retained occurrence reservations, overlap protection, fixed
expiration, independent flags and IDs, recovery attribution, and late success or
failure without unlocking a replacement. The legacy fixture is inserted after
migration 001 and before migration 002. No application migrations need to be
applied in advance, and application data and migration history remain untouched.
These checks validate storage and indexes, not production transition SQL or races.

The [storage suite](storage.md#storage-tests) exercises transition SQL and races
through production storage. Its database clock is controlled inside each isolated
schema. It covers both commit orders for recovery and completion, waits crossing
expiry, concurrent recovery/claims, rollback, and lost commit acknowledgement.
Worker crashes and receiver boundaries are covered by the separate acceptance
harness below.

The acceptance runner creates a fresh `PostgresRecoveryHarness` per scenario.
It applies the real migrations in a random schema, then stops workers and drops
the schema after both success and failure. The runner releases cyclic Dibi
references between scenarios so their PostgreSQL sessions close promptly.
Failures are reported as failures and make the command exit nonzero.

## Harness implementation

`tests/recovery-worker.php` runs production `ExecutionCoordinator` operations or
the full `LaravelAdapter` with both coordinated mutexes and a recording task.
The task and resolver persist counters and effects through an independent
connection, so observations survive worker death. A line protocol pauses child
processes at explicit barriers. The parent sends SIGKILL at crash boundaries;
these paths cannot execute task exception handling or completion cleanup.

Reservation rollback uses a temporary database constraint. Lost acknowledgement
is injected after the reservation's real commit, leaving advisory read commits
unaffected. Completion unavailability terminates only the owner's PostgreSQL
backend. Row locks and commit barriers force both completion/recovery orders;
clock changes do not depend on elapsed wall time.

Receiver probes use separate connections and atomic SQL for idempotence and
resource generations. A submitted request pauses in a separate receiver worker
before applying its durable effect. These are test receivers, not guarantees
about any external integration.

## Harness obligations

The adapter must invoke production coordination rather than duplicate its
reservation, completion, or recovery decisions. Use isolated real PostgreSQL
storage and clean up connections, workers, and schemas even on failure.

- `at(seconds)` controls a shared clock relative to `2026-10-26 10:00:00 UTC`.
  Protected claims use a fixed 300-second overlap lock; there is no renewal.
  Occurrence offsets are seconds identifying the intended UTC minute. Claims
  without overlap protection store no execution deadline.
- `claim` recovers the same schedule as actor `claim-worker`, then confirms a
  committed reservation. Conflicts return null; infrastructure errors and unknown
  commit outcomes must remain distinguishable in production diagnostics.
- `recover` returns the transition count and accepts an actor identity, defaulting
  to `recovery-worker`. Only expired protected `in_progress` rows change. Store
  `abandoned_at` and `abandoned_by`, leaving `finished_at` empty.
- `finish` records the original run's result by ID and token from `in_progress`
  or `abandoned`, regardless of expiry. Preserve abandonment fields and reject
  wrong tokens or already confirmed results. `finish(false)` includes a failure
  diagnostic. Return false for an observed unsuccessful write, including injected
  infrastructure failure, while preserving production error classification.
- `record` and `runs` observe committed state from another connection even when
  the original owner's connection is unavailable. Include nullable fixed expiry,
  completion time, abandonment time and actor, and error diagnostic.
- Race helpers use two real sessions and barriers, not sleeps. For
  `raceCompletionAndRecovery`, set time to 300 and force each commit order.
  Recovery first retains its audit fields after completion; completion first
  leaves both fields empty. `finishAfterWaitingPastDeadline` completes at 301
  after a row-lock wait, without intervening recovery; it must succeed.
- `execute` uses the real runner and a recording task applying two effects.
  `TaskException` throws with a diagnostic; crash modes terminate before task
  invocation, after one effect, or after both effects, bypassing task cleanup.
  Rollback and lost-acknowledgement modes test reservation failures. Completion
  unavailability prevents the final write. None may silently retry the task.
- `executeLongTask` blocks the real task without renewal. Recover at 300 as
  `recovery-worker`, release the task at the requested duration, and verify late
  completion with unchanged expiry and preserved recovery history.
- Runner probes inspect committed state and closed reservation transactions at
  callback entry. Resolution and invocation counters are independent: denied or
  unconfirmed reservations must not resolve a task.
- `disconnectOwner` blocks only that owner's DB access, leaving observation and
  external probes available. `resumeEffect` does not require cancellation checks.
  `submitEffect` pauses a request accepted by the receiver;
  `completeSubmittedEffect` can finish it after abandonment.
- Receiver probes illustrate stable-key idempotence and ordered fencing per
  resource. They are boundary examples, not scheduler features or proof of a real
  receiver's guarantees. Automatic cancellation and ownership-loss signalling
  are deferred, with no acceptance requirement for them in this version.

## Scenario coverage

All 43 scenarios below run from `tests/Contract/Recovery/` against the production
harness. Their scope is the fixed-expiration policy and the fixture boundaries
described above.

### Reservation and indexes

See [scenario details](coordination.md). Suite: `CoordinationContractTest`.

- `rejectsSameOccurrenceAfterTerminalStates`
- `blocksLaterOccurrenceWhileProtectedRunIsActive`
- `allowsLaterOccurrenceWithOnlySingleServerProtection`
- `allowsSameOccurrenceAfterOverlapOnlyCompletion`
- `allowsDuplicatesWhenBothProtectionsAreDisabled`
- `keepsScheduleIdentifiersIndependent`
- `choosesOneWinnerAcrossConcurrentClaims`
- `assignsFreshOwnerTokens`

### Recovery and retained history

See [scenario details](recovery.md). Suite: `RecoveryContractTest`.

- `abandonsExpiredRunAndAllowsLaterOccurrence`
- `recoversBeforeClaimingNextOccurrence`
- `keepsAbandonedOccurrenceReserved`
- `leavesLiveLeasesAndOtherSchedulesUntouched`
- `keepsRecoveryIdempotentAndPreservesHistory`
- `ignoresCompletedRunsDuringRecovery`
- `choosesOneWinnerDuringConcurrentRecoveryAndClaim`
- `acceptsLateCompletionWithoutChangingReplacement`

### Completion and races

See [scenario details](recovery.md#recovery-racing-with-completion). Suite: `OwnershipContractTest`.

- `acceptsCompletionAtDeadline`
- `acceptsCompletionAfterDeadlineWithoutRecovery`
- `acceptsLateSuccessAndPreservesAbandonment`
- `acceptsLateFailureAndPreservesAbandonment`
- `rejectsWrongTokenBeforeAndAfterRecovery`
- `keepsConfirmedResultsImmutable`
- `acceptsCompletionAfterDatabaseLockWait`
- `preservesAbandonmentWhenRecoveryWinsCompletionRace`
- `avoidsAbandonmentWhenCompletionWinsRecoveryRace`

### Runner and crash windows

See [scenario details](recovery.md#crash-windows-and-uncertain-outcomes). Suite: `ExecutionContractTest`.

- `commitsReservationBeforeResolvingAndRunningTask`
- `recordsLateCompletionWithoutRenewingLock`
- `persistsTaskFailureAndReleasesActiveReservation`
- `neverResolvesTaskAfterLosingClaim`
- `neverInvokesTaskAfterReservationRollback`
- `neverInvokesTaskAfterLosingCommitAcknowledgement`
- `recoversCrashBeforeTaskInvocationWithoutRetry`
- `recoversCrashWithPartialEffectsWithoutUndoingThem`
- `keepsCompletedEffectsWhenResultWasNeverRecorded`
- `leavesOutcomeUnconfirmedWhenCompletionWriteFails`

### External effects and receiver boundaries

See [scenario details](external-effects.md). Suite: `ExternalEffectsContractTest`.

- `demonstratesSurvivingWorkerCanActAfterAbandonment`
- `cannotCancelAlreadySubmittedExternalRequest`
- `doesNotAutomaticallyCancelWorkerAfterExpiry`
- `deduplicatesStableBusinessOperationKeysAtReceiver`
- `demonstratesDifferentKeysDoNotDeduplicateEffects`
- `rejectsOlderGenerationAfterReceiverObservesNewOwner`
- `keepsFencingGenerationsIndependentForDifferentResources`
- `demonstratesFencingCannotUndoEarlierAcceptedWrites`

## Production completion criteria

The production coordinator, PostgreSQL storage, Laravel lifecycle integration,
and acceptance harness are implemented. CI requires `make check`, `make test-db`,
`make test-storage`, `make test-recovery`, and `make docs-check`.

The acceptance suite verifies committed reservations before task resolution,
owner-checked late completion, recovery attribution, concurrent claims, both
completion/recovery orders, process crashes, and uncertain database outcomes.
Receiver fixtures demonstrate idempotence and fencing boundaries. Real external
integrations still need their own validation where these guarantees matter;
scheduler indexes cannot guarantee exactly-once external effects.

Renewal, periodic recovery sweeps, task ownership-loss signals, process
termination, automatic retries, catch-up, deduplication-history deletion, overlap
detection, and general fencing are deferred extensions. The first version
recovers expired overlap locks before claims, preserves occurrence reservations,
and accepts the original process's eventual result with retained recovery history.
