<?php

declare(strict_types=1);

namespace Tests\Contract\Recovery;

use Tests\Support\Recovery\RecoveryAssertions as A;
use Tests\Support\Recovery\RecoveryCase;
use Tests\Support\Recovery\RecoveryHarness;

final readonly class ExternalEffectsContractTest
{
    /**
     * @return list<RecoveryCase>
     */
    public function cases(): array
    {
        return [
            new RecoveryCase($this->demonstratesSurvivingWorkerCanActAfterAbandonment(...)),
            new RecoveryCase($this->cannotCancelAlreadySubmittedExternalRequest(...)),
            new RecoveryCase($this->doesNotAutomaticallyCancelWorkerAfterExpiry(...)),
            new RecoveryCase($this->deduplicatesStableBusinessOperationKeysAtReceiver(...)),
            new RecoveryCase($this->demonstratesDifferentKeysDoNotDeduplicateEffects(...)),
            new RecoveryCase($this->rejectsOlderGenerationAfterReceiverObservesNewOwner(...)),
            new RecoveryCase($this->keepsFencingGenerationsIndependentForDifferentResources(...)),
            new RecoveryCase($this->demonstratesFencingCannotUndoEarlierAcceptedWrites(...)),
        ];
    }

    /**
     * @throws \Exception
     */
    private function demonstratesSurvivingWorkerCanActAfterAbandonment(RecoveryHarness $h): void
    {
        $old = A::claimed($h->claim('sync', 0));
        $h->disconnectOwner($old);
        $h->at(300);
        A::claimed($h->claim('sync', 300));
        A::same('abandoned', $h->record($old)->status);
        A::same(true, $h->resumeEffect($old));
        A::same(1, $h->effectCount());
        // The owner remains disconnected; the result cannot be persisted yet.
        A::same(false, $h->finish($old));
    }

    /**
     * @throws \Exception
     */
    private function cannotCancelAlreadySubmittedExternalRequest(RecoveryHarness $h): void
    {
        $old = A::claimed($h->claim('sync', 0));
        $h->submitEffect($old);
        $h->at(300);
        A::same(1, $h->recover('sync'));
        A::same(true, $h->completeSubmittedEffect($old));
        A::same(1, $h->effectCount());
    }

    /**
     * @throws \Exception
     */
    private function doesNotAutomaticallyCancelWorkerAfterExpiry(RecoveryHarness $h): void
    {
        $old = A::claimed($h->claim('sync', 0));
        $h->at(300);
        A::same(1, $h->recover('sync'));
        A::same(true, $h->resumeEffect($old));
        A::same(1, $h->effectCount());
        A::same(true, $h->finish($old));
        A::same('succeeded', $h->record($old)->status);
        A::same(300, $h->record($old)->abandonedAt);
    }

    /**
     * @throws \Exception
     */
    private function deduplicatesStableBusinessOperationKeysAtReceiver(RecoveryHarness $h): void
    {
        A::same(true, $h->applyIdempotentEffect('payment-42'));
        A::same(false, $h->applyIdempotentEffect('payment-42'));
        A::same(1, $h->effectCount());
    }

    /**
     * @throws \Exception
     */
    private function demonstratesDifferentKeysDoNotDeduplicateEffects(RecoveryHarness $h): void
    {
        A::same(true, $h->applyIdempotentEffect('occurrence-10:00'));
        A::same(true, $h->applyIdempotentEffect('occurrence-10:01'));
        A::same(2, $h->effectCount());
    }

    /**
     * @throws \Exception
     */
    private function rejectsOlderGenerationAfterReceiverObservesNewOwner(RecoveryHarness $h): void
    {
        A::same(true, $h->applyFencedEffect('inventory', 8));
        A::same(false, $h->applyFencedEffect('inventory', 7));
        A::same(1, $h->effectCount());
    }

    /**
     * @throws \Exception
     */
    private function keepsFencingGenerationsIndependentForDifferentResources(RecoveryHarness $h): void
    {
        A::same(true, $h->applyFencedEffect('first', 8));
        A::same(true, $h->applyFencedEffect('second', 7));
        A::same(2, $h->effectCount());
    }

    /**
     * @throws \Exception
     */
    private function demonstratesFencingCannotUndoEarlierAcceptedWrites(RecoveryHarness $h): void
    {
        A::same(true, $h->applyFencedEffect('inventory', 7));
        A::same(true, $h->applyFencedEffect('inventory', 8));
        A::same(2, $h->effectCount());
    }
}
