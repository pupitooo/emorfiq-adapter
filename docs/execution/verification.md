# Recovery verification

## Current status and commands

The schema is implemented. Production coordination, its test harness, and the
additional verification commands below are not included in this documentation
change. The plan separates database guarantees from acceptance contracts that
must become green during implementation.

The existing checks remain `make test` for the application suite and `make check`
for PHPStan, ECS and that suite. Both run in CI.

### Planned verification commands

These commands and test sources require a separate implementation change.

| Command | Scope | Expected result before implementation |
| --- | --- | --- |
| `make test-db` | Actual migration and partial indexes on PostgreSQL | Green. |
| `make test-recovery` | 43 named acceptance scenarios | Red: production harness adapter is missing. |
| `docker compose run --rm --no-deps -T check composer test:recovery:list` | List acceptance scenarios without running them | Lists 43 scenarios. Rebuild `check` after source changes. |

The planned `make test-db` command should stream `tests/Database/setup.sql`, the actual migration, and
`tests/Database/task-runs.sql` into PostgreSQL in a new per-session
schema inside a transaction and roll it back. It must leave application tables and
migration history untouched, require no `make migrate`, and verify which
unique index rejects each insert. Its 11 planned scenarios cover terminal reservations,
active overlap, expiry without transition, explicit abandonment, independent
flags and IDs, predicate boundaries, and repeatable cleanup. Add `make test-db`
to CI and make it start PostgreSQL if necessary. These SQL checks will not be evidence of
implemented production recovery or concurrent coordinator correctness.

The planned `tests/recovery.php` runner should run each named contract against a
fresh `RecoveryHarness` from `tests/Support/Recovery/RecoveryHarnessFactory.php`.
Until the production adapter exists, the factory must throw an explicit pending
error. Failures must not be swallowed or reported as skips; the command must exit
nonzero. This preserves a useful red phase before implementation while keeping
the existing application suite usable. Do not substitute an in-memory coordinator
to make these tests green.

## Harness obligations

Implement this test adapter alongside the production coordinator. It translates
test operations into production calls; it must not reimplement reservation,
renewal, or recovery decisions. Each scenario gets isolated real PostgreSQL
storage. Always clean up connections, workers, and schemas, including failures.

- `at(seconds)` sets a shared controlled ownership clock relative to
  `2026-10-26 10:00:00 UTC`. `claim` uses a five-minute lease and converts its
  integer occurrence offset into the intended UTC minute. This is a test lease,
  not a new user configuration option. The production clock must have the same
  deadline semantics and be independently checked against PostgreSQL time.
- `claim` includes recovery for that schedule and a committed reservation;
  conflicts return null. `recover` returns the number of actual transitions.
  `renew` and `finish` expose whether ownership was confirmed. Infrastructure
  exceptions are captured by the test adapter as unsuccessful observed outcomes;
  production logging and exception behavior must remain visible and be tested
  during runner integration.
- `record` and `runs` observe committed state from an independent connection,
  including while the owner's connection is unavailable. `finish(false)` stores
  a failure diagnostic. Each claim gets a unique row ID and fresh nonzero UUID.
- Race methods use two real connections and synchronization barriers. Force both
  renewal/recovery orders around expiry. Exercise lock waits crossing the deadline;
  do not use sleeps as the synchronization mechanism. The renewed deadline must
  be valid at the recovery decision; the abandoned row must never be resurrected.
- `execute` runs the actual runner with a recording task. The normal task applies
  two observable effects. `TaskException` throws with a diagnostic before success;
  `CrashDuringTask` dies after the first effect; `CrashAfterEffect` dies after both.
  `CrashBeforeTask` dies after commit but before invocation. Abrupt-death modes
  must bypass task cleanup, for example using a controlled child process.
- `ReservationRollback` fails before commit. `ReservationAcknowledgementLost`
  commits and then hides the acknowledgement. `CompletionUnavailable` prevents
  the final write after both effects. The adapter captures the fault without
  silently calling the task again. `execute` without faults uses the real runner's
  task failure handling and final-state writes.
- `executeLongTask` blocks the real task while its actual renewal supervisor
  runs. Advance the shared clock through the original lease deadline, run a
  recovery sweep there, then release the task after the requested duration.
  Observing success after ten minutes with a five-minute initial lease must
  demonstrate live renewal during execution, not synthetic renewal after return.
- The runner probes inspect the committed reservation and transaction boundary
  at callback entry. Task-resolution and invocation counters are independent, so
  a denied claim cannot resolve a task and merely skip its invocation later.
- `disconnectOwner` blocks the owner's DB access while leaving observation and
  the external probe available. `resumeEffect` resumes a controlled fixture task:
  its uncooperative variant ignores loss; its cooperative variant checks the
  cancellation/ownership signal before a new effect. `submitEffect` pauses a
  request already accepted by the receiver; `completeSubmittedEffect` completes
  it after ownership loss, independently of cancellation. This does not assert that
  arbitrary user tasks can be forcibly cancelled.
- Receiver probe methods atomically deduplicate a stable operation key or enforce
  a highest observed generation per resource. They illustrate the required
  external contract, not an implemented scheduler fencing feature. A real task
  integration must test its actual receiver instead of treating the probe as
  proof of production idempotence or fencing.

## Scenario coverage

Each scenario below must become an executable assertion against the production
implementation. These are planned cases; this documentation does not establish
that they have passed. Use the names as methods under
`tests/Contract/Recovery/`.

### Reservation and indexes

Scenario diagrams: [Reservation and indexes](coordination.md). Suite: `CoordinationContractTest`.

- `rejectsSameOccurrenceAfterTerminalStates`
- `blocksLaterOccurrenceWhileProtectedRunIsActive`
- `allowsLaterOccurrenceWithOnlySingleServerProtection`
- `allowsSameOccurrenceAfterOverlapOnlyCompletion`
- `allowsDuplicatesWhenBothProtectionsAreDisabled`
- `keepsScheduleIdentifiersIndependent`
- `choosesOneWinnerAcrossConcurrentClaims`
- `assignsFreshOwnerTokens`

### Recovery and retained history

Scenario diagrams: [Recovery and retained history](recovery.md). Suite: `RecoveryContractTest`.

- `abandonsExpiredRunAndAllowsLaterOccurrence`
- `recoversBeforeClaimingNextOccurrence`
- `keepsAbandonedOccurrenceReserved`
- `leavesLiveLeasesAndOtherSchedulesUntouched`
- `keepsRecoveryIdempotentAndPreservesHistory`
- `ignoresCompletedRunsDuringRecovery`
- `choosesOneWinnerDuringConcurrentRecoveryAndClaim`
- `rejectsLateOwnerWithoutChangingReplacement`

### Lease ownership and races

Scenario diagrams: [Lease ownership and races](recovery.md). Suite: `OwnershipContractTest`.

- `renewsLiveLeaseWithoutChangingOwner`
- `rejectsRenewalAndCompletionAtDeadline`
- `rejectsRenewalAndCompletionAfterDeadline`
- `rejectsWrongTokenWhileLeaseIsLive`
- `neverRevivesTerminalRuns`
- `keepsRenewedLeaseWhenRenewalWinsRecoveryRace`
- `neverRevivesRunWhenRecoveryWinsRenewalRace`
- `rechecksDeadlineAfterDatabaseLockWait`
- `signalsOwnershipLossWhenRenewalCannotReachDatabase`

### Runner and crash windows

Scenario diagrams: [Runner and crash windows](recovery.md#crash-windows-and-uncertain-outcomes). Suite: `ExecutionContractTest`.

- `commitsReservationBeforeResolvingAndRunningTask`
- `renewsLeaseWhileTaskIsBlocked`
- `persistsTaskFailureAndReleasesActiveReservation`
- `neverResolvesTaskAfterLosingClaim`
- `neverInvokesTaskAfterReservationRollback`
- `neverInvokesTaskAfterLosingCommitAcknowledgement`
- `recoversCrashBeforeTaskInvocationWithoutRetry`
- `recoversCrashWithPartialEffectsWithoutUndoingThem`
- `keepsCompletedEffectsWhenResultWasNeverRecorded`
- `leavesOutcomeUnconfirmedWhenCompletionWriteFails`

### External effects and receiver boundaries

Scenario diagrams: [External effects and receiver boundaries](external-effects.md). Suite: `ExternalEffectsContractTest`.

- `demonstratesSurvivingWorkerCanActAfterAbandonment`
- `cannotCancelAlreadySubmittedExternalRequest`
- `stopsCooperativeWorkerAfterOwnershipLoss`
- `deduplicatesStableBusinessOperationKeysAtReceiver`
- `demonstratesDifferentKeysDoNotDeduplicateEffects`
- `rejectsOlderGenerationAfterReceiverObservesNewOwner`
- `keepsFencingGenerationsIndependentForDifferentResources`
- `demonstratesFencingCannotUndoEarlierAcceptedWrites`

## Completion criteria and remaining decisions

Documentation and the planned contracts define the target behavior. This does not
mean only a small mutex class remains. Completion requires all of the following:

1. Implement transactional PostgreSQL reservation, conditional renewal and
   completion, explicit recovery, token validation, error classification, and
   conflict handling. Add the PHP database driver, connection configuration, and
   dependency injection needed by the runtime and tools images.
2. Integrate one execution lifecycle with `LaravelAdapter`, `TaskExecutor`,
   `SchedulingMutex`, and `EventMutex`. The existing two mutex callbacks must not
   create two unrelated execution rows or reserve an occurrence without a clear
   owner and lifecycle. Denied or uncertain claims must not resolve tasks.
3. Decide and implement the renewal supervision/cancellation mechanism during
   blocking tasks, its cadence, and production lease policy, including schedules
   using only `onOneServer`. The current overlap duration option alone does not
   define all those operational choices. Decide whether an additional periodic
   recovery sweep is needed beyond recovery before the next claim.
4. Connect the harness to production calls, real connections, controlled workers,
   fault injection, and the clock. Run all 43 contracts and fix the implementation
   until green. Verify real database-time SQL, lock-wait boundaries, diagnostics,
   and the runner's integration. A green model of the protocol is insufficient.
5. Add `make test-recovery` to required CI checks and the normal completion gate
   when the harness and implementation exist. Remove obsolete tests asserting
   `Not Implemented`; update implementation-status documentation. Keep schema
   checks and existing task behavior green.
6. For effectful tasks, establish and verify business idempotence, cooperative
   cancellation, or receiver-side fencing as required by their actual effects.
   The scheduler cannot supply an unconditional exactly-once effects guarantee.

Automatic retry, replay/catch-up, retention that deletes deduplication history,
and a general fencing protocol are separate features. Do not introduce them
implicitly as part of recovery. Without them, the coordinator can still be
complete for the contract described here: expired ownership is recoverable,
future occurrences can run, and past protected occurrences remain reserved.
