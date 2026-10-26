# Possible coordination extensions

The first version keeps fixed-expiration locks and skips conflicting invocations.
It already adds the ownership, history, and reservation behavior described in
[Design choices](design.md). The features below are not implemented or
committed plans. The application-owned contracts give them an integration point;
they do not make the features automatic or exclusive to this project.

## Renewable leases and ownership loss

Renewal could let a healthy long-running task keep a relatively short lease,
reducing recovery delay after a crash. It would require:

- A `renew` operation matching the run, owner, and an unexpired overlap lock.
  Renewal must atomically compete with recovery and must never revive an old
  owner after a replacement has acquired the schedule.
- A defined relationship between the current lock deadline and the history
  snapshot. Renewal must not silently make stored history misleading.
- A way to schedule heartbeats while synchronous `Task::run()` is busy. Possible
  designs include cooperative checkpoints or a supervisor; adding a timer to
  the existing blocking call is not sufficient.
- A policy for failed or unconfirmed renewal: stop admitting new effects,
  signal cancellation, and expose the failure. A separate heartbeating process
  must not renew forever after its worker has died or stopped making progress.

Laravel's general cache API also supports renewal with selected drivers; its
default scheduler does not run that renewal loop. See
[shared limits and Laravel primitives](design.md#shared-limits-and-available-laravel-primitives).
Neither renewal nor a cancellation check removes the need for
[destination-side protection](../failure-and-recovery/external-effects.md#fencing-stale-owners) when stale writes are unacceptable.

## Waiting, retry, and catch-up

The coordinator currently returns a claim or a conflict. The adapter also has
early gates that can skip a live overlap lock before requesting a claim. There
is no persistent pending-work record for either kind of denial.

A bounded wait is a smaller extension than durable retry: repeat admission until
a deadline, without holding a transaction during the wait. The policy must
specify whether a delayed execution still belongs to the original occurrence,
how it interacts with newer occurrences, and whether waiting delays unrelated
tasks in the current sequential runner. All denial paths would need to use that
policy. Laravel's general cache API already has bounded `block()` acquisition;
the default scheduler's skip policy does not use it.

Durable retry or catch-up is a larger feature. It needs persisted work identity,
attempts, retry timing and limits, a worker, and rules for failures and partial
effects. In particular, our `onOneServer` occurrence reservation survives a
crashed run. Retrying that work needs an explicit attempt model; it cannot
simply call today's `claim()` again for the same occurrence. Reconstructing
missed occurrences also requires a policy for configuration changes and time
zones. None of this follows from keeping run history alone.

Choose whether to extend coordination or delegate delivery to an existing queue.
Every retry still needs business idempotency or an equivalent receiver contract;
see [External effects](../failure-and-recovery/external-effects.md#idempotent-business-operations).

## Work performed by another worker

Today a successful return from `Task::run()` completes the scheduler run. A task
that merely enqueues work therefore reports dispatch completion. Protecting the
actual work requires worker-side acquisition, or an explicit ownership handoff
and completion protocol. A delayed worker must not begin using an expired
reservation acquired at dispatch time.

This would change the meaning of admission, start, completion, and crash recovery
and would need separate dispatch and work outcomes. It can be built with Laravel
jobs too. The existing `RunClaim` is an internal scheduler credential, not an
implemented job handoff API. See
[Work that outlives the task call](../failure-and-recovery/external-effects.md#work-that-outlives-taskrun).

## Protecting shared business resources

Current locks use schedule identity. Different schedules are independent, even
when they modify the same business resource. Shared resource keys or a limit of
several simultaneous workers would require explicit resource identity and new
acquisition rules. Laravel's cache API already accepts application-chosen keys;
simple resource locking does not require this coordinator.

For stale business writes, see [Fencing stale owners](../failure-and-recovery/external-effects.md#fencing-stale-owners).
