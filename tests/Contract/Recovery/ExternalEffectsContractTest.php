<?php

declare(strict_types=1);

namespace Tests\Contract\Recovery;

use Closure;
use Tests\Support\Recovery\RecoveryAssertions as A;
use Tests\Support\Recovery\RecoveryHarness;

final readonly class ExternalEffectsContractTest
{
    /**
     * @return list<Closure(RecoveryHarness): void>
     */
    public function cases(): array
    {
        return [
            $this->demonstratesSurvivingWorkerCanActAfterAbandonment(...),
            $this->cannotCancelAlreadySubmittedExternalRequest(...),
            $this->stopsCooperativeWorkerAfterOwnershipLoss(...),
            $this->deduplicatesStableBusinessOperationKeysAtReceiver(...),
            $this->demonstratesDifferentKeysDoNotDeduplicateEffects(...),
            $this->rejectsOlderGenerationAfterReceiverObservesNewOwner(...),
            $this->keepsFencingGenerationsIndependentForDifferentResources(...),
            $this->demonstratesFencingCannotUndoEarlierAcceptedWrites(...),
        ];
    }

    private function demonstratesSurvivingWorkerCanActAfterAbandonment(RecoveryHarness $h): void
    {
        $old = A::claimed($h->claim('sync', 0));
        $h->disconnectOwner($old);
        $h->at(300);
        A::claimed($h->claim('sync', 300));
        A::same('abandoned', $h->record($old)->status);
        A::same(true, $h->resumeEffect($old, cooperative: false));
        A::same(1, $h->effectCount());
        A::same(false, $h->finish($old));
    }

    private function cannotCancelAlreadySubmittedExternalRequest(RecoveryHarness $h): void
    {
        $old = A::claimed($h->claim('sync', 0));
        $h->submitEffect($old);
        $h->at(300);
        A::same(1, $h->recover('sync'));
        A::same(false, $h->resumeEffect($old, cooperative: true));
        A::same(true, $h->completeSubmittedEffect($old));
        A::same(1, $h->effectCount());
    }

    private function stopsCooperativeWorkerAfterOwnershipLoss(RecoveryHarness $h): void
    {
        $old = A::claimed($h->claim('sync', 0));
        $h->at(300);
        A::same(1, $h->recover('sync'));
        A::same(false, $h->resumeEffect($old, cooperative: true));
        A::same(0, $h->effectCount());
    }

    private function deduplicatesStableBusinessOperationKeysAtReceiver(RecoveryHarness $h): void
    {
        A::same(true, $h->applyIdempotentEffect('payment-42'));
        A::same(false, $h->applyIdempotentEffect('payment-42'));
        A::same(1, $h->effectCount());
    }

    private function demonstratesDifferentKeysDoNotDeduplicateEffects(RecoveryHarness $h): void
    {
        A::same(true, $h->applyIdempotentEffect('occurrence-10:00'));
        A::same(true, $h->applyIdempotentEffect('occurrence-10:01'));
        A::same(2, $h->effectCount());
    }

    private function rejectsOlderGenerationAfterReceiverObservesNewOwner(RecoveryHarness $h): void
    {
        A::same(true, $h->applyFencedEffect('inventory', 8));
        A::same(false, $h->applyFencedEffect('inventory', 7));
        A::same(1, $h->effectCount());
    }

    private function keepsFencingGenerationsIndependentForDifferentResources(RecoveryHarness $h): void
    {
        A::same(true, $h->applyFencedEffect('first', 8));
        A::same(true, $h->applyFencedEffect('second', 7));
        A::same(2, $h->effectCount());
    }

    private function demonstratesFencingCannotUndoEarlierAcceptedWrites(RecoveryHarness $h): void
    {
        A::same(true, $h->applyFencedEffect('inventory', 7));
        A::same(true, $h->applyFencedEffect('inventory', 8));
        A::same(2, $h->effectCount());
    }
}
