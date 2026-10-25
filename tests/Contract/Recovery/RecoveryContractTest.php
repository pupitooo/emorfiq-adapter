<?php

declare(strict_types=1);

namespace Tests\Contract\Recovery;

use Closure;
use Tests\Support\Recovery\Claim;
use Tests\Support\Recovery\RecoveryAssertions as A;
use Tests\Support\Recovery\RecoveryHarness;

final readonly class RecoveryContractTest
{
    /**
     * @return list<Closure(RecoveryHarness): void>
     */
    public function cases(): array
    {
        return [
            $this->abandonsExpiredRunAndAllowsLaterOccurrence(...),
            $this->recoversBeforeClaimingNextOccurrence(...),
            $this->keepsAbandonedOccurrenceReserved(...),
            $this->leavesLiveLeasesAndOtherSchedulesUntouched(...),
            $this->keepsRecoveryIdempotentAndPreservesHistory(...),
            $this->ignoresCompletedRunsDuringRecovery(...),
            $this->choosesOneWinnerDuringConcurrentRecoveryAndClaim(...),
            $this->rejectsLateOwnerWithoutChangingReplacement(...),
        ];
    }

    private function abandonsExpiredRunAndAllowsLaterOccurrence(RecoveryHarness $h): void
    {
        $old = A::claimed($h->claim('sync', 0));
        $h->at(360);
        A::same(1, $h->recover('sync'));
        A::same('abandoned', $h->record($old)->status);
        A::same(360, $h->record($old)->finishedAt);
        A::claimed($h->claim('sync', 360));
    }

    private function recoversBeforeClaimingNextOccurrence(RecoveryHarness $h): void
    {
        $old = A::claimed($h->claim('sync', 0));
        $h->at(360);
        A::claimed($h->claim('sync', 360));
        A::same('abandoned', $h->record($old)->status);
        A::same(2, count($h->runs('sync')));
    }

    private function keepsAbandonedOccurrenceReserved(RecoveryHarness $h): void
    {
        $old = A::claimed($h->claim('sync', 0));
        $h->at(300);
        A::same(1, $h->recover('sync'));
        A::same(null, $h->claim('sync', 0));
        A::same('abandoned', $h->record($old)->status);
        A::same(1, count($h->runs('sync')));
    }

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

    private function keepsRecoveryIdempotentAndPreservesHistory(RecoveryHarness $h): void
    {
        $old = A::claimed($h->claim('sync', 0));
        $h->at(300);
        A::same(1, $h->recover('sync'));
        $record = $h->record($old);
        $h->at(400);
        A::same(0, $h->recover('sync'));
        A::same($record->status, $h->record($old)->status);
        A::same($record->finishedAt, $h->record($old)->finishedAt);
        A::same(1, count($h->runs('sync')));
    }

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

    private function choosesOneWinnerDuringConcurrentRecoveryAndClaim(RecoveryHarness $h): void
    {
        $old = A::claimed($h->claim('sync', 0));
        $h->at(300);
        A::same(1, count($h->raceRecoveryAndClaim('sync', 300)));
        A::same('abandoned', $h->record($old)->status);
        A::same(2, count($h->runs('sync')));
    }

    private function rejectsLateOwnerWithoutChangingReplacement(RecoveryHarness $h): void
    {
        $old = A::claimed($h->claim('sync', 0));
        $h->at(300);
        $new = A::claimed($h->claim('sync', 300));
        A::same(false, $h->renew($old));
        A::same(false, $h->finish($old));
        $forged = new Claim($new->id, $old->ownerToken);
        A::same(false, $h->finish($forged));
        A::same(false, $h->renew($forged));
        A::same('abandoned', $h->record($old)->status);
        A::same('in_progress', $h->record($new)->status);
    }
}
