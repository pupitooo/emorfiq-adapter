<?php

declare(strict_types=1);

namespace Tests\Contract\Recovery;

use Closure;
use Tests\Support\Recovery\Claim;
use Tests\Support\Recovery\RecoveryAssertions as A;
use Tests\Support\Recovery\RecoveryHarness;

final readonly class OwnershipContractTest
{
    /**
     * @return list<Closure(RecoveryHarness): void>
     */
    public function cases(): array
    {
        return [
            $this->renewsLiveLeaseWithoutChangingOwner(...),
            $this->rejectsRenewalAndCompletionAtDeadline(...),
            $this->rejectsRenewalAndCompletionAfterDeadline(...),
            $this->rejectsWrongTokenWhileLeaseIsLive(...),
            $this->neverRevivesTerminalRuns(...),
            $this->keepsRenewedLeaseWhenRenewalWinsRecoveryRace(...),
            $this->neverRevivesRunWhenRecoveryWinsRenewalRace(...),
            $this->rechecksDeadlineAfterDatabaseLockWait(...),
            $this->signalsOwnershipLossWhenRenewalCannotReachDatabase(...),
        ];
    }

    private function renewsLiveLeaseWithoutChangingOwner(RecoveryHarness $h): void
    {
        $claim = A::claimed($h->claim('sync', 0));
        $h->at(240);
        A::same(true, $h->renew($claim));
        A::same(540, $h->record($claim)->leaseExpiresAt);
        A::same($claim->id, $h->record($claim)->claim->id);
        A::same($claim->ownerToken, $h->record($claim)->claim->ownerToken);
        $h->at(300);
        A::same(0, $h->recover('sync'));
        A::same(true, $h->finish($claim));
    }

    private function rejectsRenewalAndCompletionAtDeadline(RecoveryHarness $h): void
    {
        $claim = A::claimed($h->claim('sync', 0));
        $h->at(300);
        A::same(false, $h->renew($claim));
        A::same(false, $h->finish($claim));
        A::same('in_progress', $h->record($claim)->status);
        A::same(300, $h->record($claim)->leaseExpiresAt);
    }

    private function rejectsRenewalAndCompletionAfterDeadline(RecoveryHarness $h): void
    {
        $claim = A::claimed($h->claim('sync', 0));
        $h->at(301);
        A::same(false, $h->renew($claim));
        A::same(false, $h->finish($claim));
        A::same(1, $h->recover('sync'));
    }

    private function rejectsWrongTokenWhileLeaseIsLive(RecoveryHarness $h): void
    {
        $claim = A::claimed($h->claim('sync', 0));
        $forged = new Claim($claim->id, '00000000-0000-4000-8000-000000000000');
        A::same(false, $h->renew($forged));
        A::same(false, $h->finish($forged));
        A::same('in_progress', $h->record($claim)->status);
    }

    private function neverRevivesTerminalRuns(RecoveryHarness $h): void
    {
        foreach ([true, false] as $success) {
            $id = $success ? 'success' : 'failure';
            $claim = A::claimed($h->claim($id, 0));
            A::same(true, $h->finish($claim, $success));
            A::same(false, $h->renew($claim));
            A::same(false, $h->finish($claim, ! $success));
            A::same($success ? 'succeeded' : 'failed', $h->record($claim)->status);
        }
    }

    private function keepsRenewedLeaseWhenRenewalWinsRecoveryRace(RecoveryHarness $h): void
    {
        $claim = A::claimed($h->claim('sync', 0));
        $h->raceRenewalAndRecovery($claim, renewalWins: true);
        A::same('in_progress', $h->record($claim)->status);
        A::same(true, $h->record($claim)->leaseExpiresAt > 300);
    }

    private function neverRevivesRunWhenRecoveryWinsRenewalRace(RecoveryHarness $h): void
    {
        $claim = A::claimed($h->claim('sync', 0));
        $h->raceRenewalAndRecovery($claim, renewalWins: false);
        A::same('abandoned', $h->record($claim)->status);
        A::same(300, $h->record($claim)->leaseExpiresAt);
        A::same(false, $h->renew($claim));
    }

    private function rechecksDeadlineAfterDatabaseLockWait(RecoveryHarness $h): void
    {
        $claim = A::claimed($h->claim('sync', 0));
        A::same(false, $h->finishAfterWaitingPastDeadline($claim));
        A::same('in_progress', $h->record($claim)->status);
    }

    private function signalsOwnershipLossWhenRenewalCannotReachDatabase(RecoveryHarness $h): void
    {
        $claim = A::claimed($h->claim('sync', 0));
        $h->at(100);
        $h->disconnectOwner($claim);
        A::same(false, $h->renew($claim));
        A::same(true, $h->ownershipLossSignalled($claim));
        A::same(false, $h->resumeEffect($claim, cooperative: true));
        A::same(0, $h->effectCount());
    }
}
