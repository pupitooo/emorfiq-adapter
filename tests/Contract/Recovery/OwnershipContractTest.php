<?php

declare(strict_types=1);

namespace Tests\Contract\Recovery;

use Tests\Support\Recovery\Claim;
use Tests\Support\Recovery\RecoveryAssertions as A;
use Tests\Support\Recovery\RecoveryCase;
use Tests\Support\Recovery\RecoveryHarness;

final readonly class OwnershipContractTest
{
    /**
     * @return list<RecoveryCase>
     */
    public function cases(): array
    {
        return [
            new RecoveryCase($this->acceptsCompletionAtDeadline(...)),
            new RecoveryCase($this->acceptsCompletionAfterDeadlineWithoutRecovery(...)),
            new RecoveryCase($this->acceptsLateSuccessAndPreservesAbandonment(...)),
            new RecoveryCase($this->acceptsLateFailureAndPreservesAbandonment(...)),
            new RecoveryCase($this->rejectsWrongTokenBeforeAndAfterRecovery(...)),
            new RecoveryCase($this->keepsConfirmedResultsImmutable(...)),
            new RecoveryCase($this->acceptsCompletionAfterDatabaseLockWait(...)),
            new RecoveryCase($this->preservesAbandonmentWhenRecoveryWinsCompletionRace(...)),
            new RecoveryCase($this->avoidsAbandonmentWhenCompletionWinsRecoveryRace(...)),
        ];
    }

    /**
     * @throws \Exception
     */
    private function acceptsCompletionAtDeadline(RecoveryHarness $h): void
    {
        $claim = A::claimed($h->claim('sync', 0));
        $h->at(300);
        A::same(true, $h->finish($claim));
        $run = $h->record($claim);
        A::same('succeeded', $run->status);
        A::same(300, $run->finishedAt);
        A::same(300, $run->leaseExpiresAt);
        A::same(null, $run->abandonedAt);
        A::same(null, $run->abandonedBy);
        A::same(0, $h->recover('sync'));
    }

    /**
     * @throws \Exception
     */
    private function acceptsCompletionAfterDeadlineWithoutRecovery(RecoveryHarness $h): void
    {
        $claim = A::claimed($h->claim('sync', 0));
        $h->at(301);
        A::same(true, $h->finish($claim));
        A::same('succeeded', $h->record($claim)->status);
        A::same(301, $h->record($claim)->finishedAt);
        A::same(null, $h->record($claim)->abandonedAt);
        A::same(null, $h->record($claim)->abandonedBy);
        A::same(0, $h->recover('sync'));
    }

    /**
     * @throws \Exception
     */
    private function acceptsLateSuccessAndPreservesAbandonment(RecoveryHarness $h): void
    {
        $claim = A::claimed($h->claim('sync', 0));
        $h->at(300);
        A::same(1, $h->recover('sync', 'server-b:worker-42'));
        A::same(null, $h->record($claim)->finishedAt);
        $h->at(360);
        A::same(true, $h->finish($claim));
        $run = $h->record($claim);
        A::same('succeeded', $run->status);
        A::same(360, $run->finishedAt);
        A::same(300, $run->abandonedAt);
        A::same('server-b:worker-42', $run->abandonedBy);
        A::same(300, $run->leaseExpiresAt);
    }

    /**
     * @throws \Exception
     */
    private function acceptsLateFailureAndPreservesAbandonment(RecoveryHarness $h): void
    {
        $claim = A::claimed($h->claim('sync', 0));
        $h->at(300);
        A::same(1, $h->recover('sync'));
        $h->at(360);
        A::same(true, $h->finish($claim, succeeded: false));
        $run = $h->record($claim);
        A::same('failed', $run->status);
        A::same(360, $run->finishedAt);
        A::same(300, $run->abandonedAt);
        A::same('recovery-worker', $run->abandonedBy);
        A::same(true, $run->error !== null && $run->error !== '');
    }

    /**
     * @throws \Exception
     */
    private function rejectsWrongTokenBeforeAndAfterRecovery(RecoveryHarness $h): void
    {
        $claim = A::claimed($h->claim('sync', 0));
        $forged = new Claim($claim->id, '00000000-0000-4000-8000-000000000000');
        A::same(false, $h->finish($forged));
        A::same('in_progress', $h->record($claim)->status);
        $h->at(300);
        A::same(1, $h->recover('sync'));
        A::same(false, $h->finish($forged));
        A::same('abandoned', $h->record($claim)->status);
        A::same(null, $h->record($claim)->finishedAt);
        A::same(300, $h->record($claim)->abandonedAt);
    }

    /**
     * @throws \Exception
     */
    private function keepsConfirmedResultsImmutable(RecoveryHarness $h): void
    {
        foreach ([true, false] as $success) {
            $id = $success ? 'success' : 'failure';
            $claim = A::claimed($h->claim($id, 0));
            A::same(true, $h->finish($claim, $success));
            $run = $h->record($claim);
            $h->at(100);
            A::same(false, $h->finish($claim, ! $success));
            A::same(false, $h->finish($claim, $success));
            A::same($success ? 'succeeded' : 'failed', $h->record($claim)->status);
            A::same($run->finishedAt, $h->record($claim)->finishedAt);
            A::same($run->error, $h->record($claim)->error);
        }
    }

    /**
     * @throws \Exception
     */
    private function acceptsCompletionAfterDatabaseLockWait(RecoveryHarness $h): void
    {
        $claim = A::claimed($h->claim('sync', 0));
        A::same(true, $h->finishAfterWaitingPastDeadline($claim));
        A::same('succeeded', $h->record($claim)->status);
        A::same(301, $h->record($claim)->finishedAt);
        A::same(300, $h->record($claim)->leaseExpiresAt);
        A::same(null, $h->record($claim)->abandonedAt);
    }

    /**
     * @throws \Exception
     */
    private function preservesAbandonmentWhenRecoveryWinsCompletionRace(RecoveryHarness $h): void
    {
        $claim = A::claimed($h->claim('sync', 0));
        $h->raceCompletionAndRecovery($claim, completionWins: false);
        A::same('succeeded', $h->record($claim)->status);
        A::same(300, $h->record($claim)->finishedAt);
        A::same(300, $h->record($claim)->abandonedAt);
        A::same('recovery-worker', $h->record($claim)->abandonedBy);
        A::same(0, $h->recover('sync'));
    }

    /**
     * @throws \Exception
     */
    private function avoidsAbandonmentWhenCompletionWinsRecoveryRace(RecoveryHarness $h): void
    {
        $claim = A::claimed($h->claim('sync', 0));
        $h->raceCompletionAndRecovery($claim, completionWins: true);
        A::same('succeeded', $h->record($claim)->status);
        A::same(300, $h->record($claim)->finishedAt);
        A::same(null, $h->record($claim)->abandonedAt);
        A::same(null, $h->record($claim)->abandonedBy);
        A::same(0, $h->recover('sync'));
    }
}
