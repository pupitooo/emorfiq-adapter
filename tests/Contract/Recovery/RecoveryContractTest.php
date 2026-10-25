<?php

declare(strict_types=1);

namespace Tests\Contract\Recovery;

use Tests\Support\Recovery\Claim;
use Tests\Support\Recovery\RecoveryAssertions as A;
use Tests\Support\Recovery\RecoveryCase;
use Tests\Support\Recovery\RecoveryHarness;

final readonly class RecoveryContractTest
{
    /**
     * @return list<RecoveryCase>
     */
    public function cases(): array
    {
        return [
            new RecoveryCase($this->abandonsExpiredRunAndAllowsLaterOccurrence(...)),
            new RecoveryCase($this->recoversBeforeClaimingNextOccurrence(...)),
            new RecoveryCase($this->keepsAbandonedOccurrenceReserved(...)),
            new RecoveryCase($this->leavesLiveLeasesAndOtherSchedulesUntouched(...)),
            new RecoveryCase($this->keepsRecoveryIdempotentAndPreservesHistory(...)),
            new RecoveryCase($this->ignoresCompletedRunsDuringRecovery(...)),
            new RecoveryCase($this->choosesOneWinnerDuringConcurrentRecoveryAndClaim(...)),
            new RecoveryCase($this->acceptsLateCompletionWithoutChangingReplacement(...)),
        ];
    }

    /**
     * @throws \Exception
     */
    private function abandonsExpiredRunAndAllowsLaterOccurrence(RecoveryHarness $h): void
    {
        $old = A::claimed($h->claim('sync', 0));
        $h->at(360);
        A::same(1, $h->recover('sync'));
        A::same('abandoned', $h->record($old)->status);
        A::same(null, $h->record($old)->finishedAt);
        A::same(360, $h->record($old)->abandonedAt);
        A::same('recovery-worker', $h->record($old)->abandonedBy);
        A::claimed($h->claim('sync', 360));
    }

    /**
     * @throws \Exception
     */
    private function recoversBeforeClaimingNextOccurrence(RecoveryHarness $h): void
    {
        $old = A::claimed($h->claim('sync', 0));
        $h->at(360);
        A::claimed($h->claim('sync', 360));
        A::same('abandoned', $h->record($old)->status);
        A::same(null, $h->record($old)->finishedAt);
        A::same(360, $h->record($old)->abandonedAt);
        A::same('claim-worker', $h->record($old)->abandonedBy);
        A::same(2, count($h->runs('sync')));
    }

    /**
     * @throws \Exception
     */
    private function keepsAbandonedOccurrenceReserved(RecoveryHarness $h): void
    {
        $old = A::claimed($h->claim('sync', 0));
        $h->at(300);
        A::same(1, $h->recover('sync'));
        A::same(null, $h->claim('sync', 0));
        A::same('abandoned', $h->record($old)->status);
        A::same(1, count($h->runs('sync')));
    }

    /**
     * @throws \Exception
     */
    private function leavesLiveLeasesAndOtherSchedulesUntouched(RecoveryHarness $h): void
    {
        $old = A::claimed($h->claim('old', 0));
        $h->at(100);
        $live = A::claimed($h->claim('live', 60));
        $h->at(300);
        A::same(0, $h->recover('live'));
        A::same('in_progress', $h->record($old)->status);
        A::same('in_progress', $h->record($live)->status);
        A::same(1, $h->recover('old'));
    }

    /**
     * @throws \Exception
     */
    private function keepsRecoveryIdempotentAndPreservesHistory(RecoveryHarness $h): void
    {
        $old = A::claimed($h->claim('sync', 0));
        $h->at(300);
        A::same(1, $h->recover('sync'));
        $record = $h->record($old);
        $h->at(400);
        A::same(0, $h->recover('sync', 'another-recovery-worker'));
        A::same($record->status, $h->record($old)->status);
        A::same(null, $h->record($old)->finishedAt);
        A::same($record->abandonedAt, $h->record($old)->abandonedAt);
        A::same($record->abandonedBy, $h->record($old)->abandonedBy);
        A::same(1, count($h->runs('sync')));
    }

    /**
     * @throws \Exception
     */
    private function ignoresCompletedRunsDuringRecovery(RecoveryHarness $h): void
    {
        foreach ([true, false] as $success) {
            $id = $success ? 'success' : 'failure';
            $claim = A::claimed($h->claim($id, 0));
            A::same(true, $h->finish($claim, $success));
        }
        $h->at(300);
        A::same(0, $h->recover('success'));
        A::same(0, $h->recover('failure'));
        A::same('succeeded', A::onlyRun($h, 'success')->status);
        A::same('failed', A::onlyRun($h, 'failure')->status);
    }

    /**
     * @throws \Exception
     */
    private function choosesOneWinnerDuringConcurrentRecoveryAndClaim(RecoveryHarness $h): void
    {
        $old = A::claimed($h->claim('sync', 0));
        $h->at(300);
        A::same(1, count($h->raceRecoveryAndClaim('sync', 300)));
        A::same('abandoned', $h->record($old)->status);
        A::same(2, count($h->runs('sync')));
    }

    /**
     * @throws \Exception
     */
    private function acceptsLateCompletionWithoutChangingReplacement(RecoveryHarness $h): void
    {
        $old = A::claimed($h->claim('sync', 0));
        $h->at(300);
        $new = A::claimed($h->claim('sync', 300));
        $replacement = $h->record($new);
        $h->at(360);
        A::same(true, $h->finish($old));
        A::same(false, $h->finish(new Claim($new->id, $old->ownerToken)));
        A::same('succeeded', $h->record($old)->status);
        A::same(300, $h->record($old)->abandonedAt);
        A::same('claim-worker', $h->record($old)->abandonedBy);
        A::same(360, $h->record($old)->finishedAt);
        A::same($replacement->status, $h->record($new)->status);
        A::same($replacement->leaseExpiresAt, $h->record($new)->leaseExpiresAt);
        A::same(null, $h->record($new)->finishedAt);
        A::same(null, $h->record($new)->abandonedAt);
        A::same(0, $h->recover('sync'));
        A::same(null, $h->claim('sync', 360));
    }
}
