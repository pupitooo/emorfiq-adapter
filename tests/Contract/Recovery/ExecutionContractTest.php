<?php

declare(strict_types=1);

namespace Tests\Contract\Recovery;

use Closure;
use Tests\Support\Recovery\ExecutionFault;
use Tests\Support\Recovery\RecoveryAssertions as A;
use Tests\Support\Recovery\RecoveryHarness;

final readonly class ExecutionContractTest
{
    /**
     * @return list<Closure(RecoveryHarness): void>
     */
    public function cases(): array
    {
        return [
            $this->commitsReservationBeforeResolvingAndRunningTask(...),
            $this->renewsLeaseWhileTaskIsBlocked(...),
            $this->persistsTaskFailureAndReleasesActiveReservation(...),
            $this->neverResolvesTaskAfterLosingClaim(...),
            $this->neverInvokesTaskAfterReservationRollback(...),
            $this->neverInvokesTaskAfterLosingCommitAcknowledgement(...),
            $this->recoversCrashBeforeTaskInvocationWithoutRetry(...),
            $this->recoversCrashWithPartialEffectsWithoutUndoingThem(...),
            $this->keepsCompletedEffectsWhenResultWasNeverRecorded(...),
            $this->leavesOutcomeUnconfirmedWhenCompletionWriteFails(...),
        ];
    }

    private function commitsReservationBeforeResolvingAndRunningTask(RecoveryHarness $h): void
    {
        $h->execute('sync', 0);
        A::same(true, $h->committedBeforeInvocation());
        A::same(true, $h->reservationTransactionClosedDuringTask());
        A::same(1, $h->taskResolutions());
        A::same(1, $h->taskStarts());
        A::same('succeeded', A::onlyRun($h, 'sync')->status);
    }

    private function renewsLeaseWhileTaskIsBlocked(RecoveryHarness $h): void
    {
        $h->executeLongTask('sync', 0, 600);
        $run = A::onlyRun($h, 'sync');
        A::same('succeeded', $run->status);
        A::same(600, $run->finishedAt);
        A::same(true, $run->leaseExpiresAt > 600);
        A::same(1, $h->taskStarts());
        A::same(2, $h->effectCount());
    }

    private function persistsTaskFailureAndReleasesActiveReservation(RecoveryHarness $h): void
    {
        $h->execute('sync', 0, ExecutionFault::TaskException);
        $run = A::onlyRun($h, 'sync');
        A::same('failed', $run->status);
        A::same(true, $run->error !== null && $run->error !== '');
        A::same(null, $h->claim('sync', 0));
        A::claimed($h->claim('sync', 60));
    }

    private function neverResolvesTaskAfterLosingClaim(RecoveryHarness $h): void
    {
        A::claimed($h->claim('sync', 0));
        $h->execute('sync', 0);
        A::same(0, $h->taskResolutions());
        A::same(0, $h->taskStarts());
        A::same(1, count($h->runs('sync')));
    }

    private function neverInvokesTaskAfterReservationRollback(RecoveryHarness $h): void
    {
        $h->execute('sync', 0, ExecutionFault::ReservationRollback);
        A::same(0, $h->taskResolutions());
        A::same(0, $h->taskStarts());
        A::same([], $h->runs('sync'));
        A::claimed($h->claim('sync', 0));
    }

    private function neverInvokesTaskAfterLosingCommitAcknowledgement(RecoveryHarness $h): void
    {
        $h->execute('sync', 0, ExecutionFault::ReservationAcknowledgementLost);
        A::same(0, $h->taskResolutions());
        A::same(0, $h->taskStarts());
        A::same('in_progress', A::onlyRun($h, 'sync')->status);
        $h->at(300);
        A::same(1, $h->recover('sync'));
        A::same(null, $h->claim('sync', 0));
    }

    private function recoversCrashBeforeTaskInvocationWithoutRetry(RecoveryHarness $h): void
    {
        $h->execute('sync', 0, ExecutionFault::CrashBeforeTask);
        A::same(0, $h->taskStarts());
        A::same(0, $h->effectCount());
        $h->at(300);
        A::same(1, $h->recover('sync'));
        A::same('abandoned', A::onlyRun($h, 'sync')->status);
        A::same(null, $h->claim('sync', 0));
        A::same(0, $h->taskStarts());
    }

    private function recoversCrashWithPartialEffectsWithoutUndoingThem(RecoveryHarness $h): void
    {
        $h->execute('sync', 0, ExecutionFault::CrashDuringTask);
        A::same(1, $h->taskStarts());
        A::same(1, $h->effectCount());
        $h->at(300);
        A::same(1, $h->recover('sync'));
        A::same('abandoned', A::onlyRun($h, 'sync')->status);
        A::same(1, $h->effectCount());
    }

    private function keepsCompletedEffectsWhenResultWasNeverRecorded(RecoveryHarness $h): void
    {
        $h->execute('sync', 0, ExecutionFault::CrashAfterEffect);
        A::same(2, $h->effectCount());
        A::same('in_progress', A::onlyRun($h, 'sync')->status);
        $h->at(300);
        A::same(1, $h->recover('sync'));
        A::same('abandoned', A::onlyRun($h, 'sync')->status);
        A::same(2, $h->effectCount());
    }

    private function leavesOutcomeUnconfirmedWhenCompletionWriteFails(RecoveryHarness $h): void
    {
        $h->execute('sync', 0, ExecutionFault::CompletionUnavailable);
        A::same(2, $h->effectCount());
        A::same('in_progress', A::onlyRun($h, 'sync')->status);
        $h->at(300);
        A::same(1, $h->recover('sync'));
        A::same('abandoned', A::onlyRun($h, 'sync')->status);
    }
}
