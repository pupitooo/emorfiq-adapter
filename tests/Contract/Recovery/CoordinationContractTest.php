<?php

declare(strict_types=1);

namespace Tests\Contract\Recovery;

use Closure;
use Tests\Support\Recovery\RecoveryAssertions as A;
use Tests\Support\Recovery\RecoveryHarness;

final readonly class CoordinationContractTest
{
    /**
     * @return list<Closure(RecoveryHarness): void>
     */
    public function cases(): array
    {
        return [
            $this->rejectsSameOccurrenceAfterTerminalStates(...),
            $this->blocksLaterOccurrenceWhileProtectedRunIsActive(...),
            $this->allowsLaterOccurrenceWithOnlySingleServerProtection(...),
            $this->allowsSameOccurrenceAfterOverlapOnlyCompletion(...),
            $this->allowsDuplicatesWhenBothProtectionsAreDisabled(...),
            $this->keepsScheduleIdentifiersIndependent(...),
            $this->choosesOneWinnerAcrossConcurrentClaims(...),
            $this->assignsFreshOwnerTokens(...),
        ];
    }

    private function rejectsSameOccurrenceAfterTerminalStates(RecoveryHarness $h): void
    {
        foreach ([true, false] as $success) {
            $id = $success ? 'success' : 'failure';
            $claim = A::claimed($h->claim($id, 0));
            A::same(true, $h->finish($claim, $success));
            A::same(null, $h->claim($id, 0));
            A::claimed($h->claim($id, 60));
        }
    }

    private function blocksLaterOccurrenceWhileProtectedRunIsActive(RecoveryHarness $h): void
    {
        A::claimed($h->claim('sync', 0));
        $h->at(60);
        A::same(null, $h->claim('sync', 60));
        A::same(1, count($h->runs('sync')));
    }

    private function allowsLaterOccurrenceWithOnlySingleServerProtection(RecoveryHarness $h): void
    {
        $claim = A::claimed($h->claim('sync', 0, withoutOverlapping: false));
        A::same(null, $h->record($claim)->leaseExpiresAt);
        $h->at(600);
        A::same(0, $h->recover('sync'));
        A::same('in_progress', $h->record($claim)->status);
        A::claimed($h->claim('sync', 600, withoutOverlapping: false));
        A::same(null, $h->claim('sync', 0, withoutOverlapping: false));
        A::same(true, $h->finish($claim));
        A::same(2, count($h->runs('sync')));
    }

    private function allowsSameOccurrenceAfterOverlapOnlyCompletion(RecoveryHarness $h): void
    {
        $claim = A::claimed($h->claim('sync', 0, onOneServer: false));
        A::same(true, $h->finish($claim));
        A::claimed($h->claim('sync', 0, onOneServer: false));
    }

    private function allowsDuplicatesWhenBothProtectionsAreDisabled(RecoveryHarness $h): void
    {
        A::claimed($h->claim('sync', 0, false, false));
        A::claimed($h->claim('sync', 0, false, false));
        A::same(2, count($h->runs('sync')));
    }

    private function keepsScheduleIdentifiersIndependent(RecoveryHarness $h): void
    {
        A::claimed($h->claim('first', 0));
        A::claimed($h->claim('second', 0));
        A::same(1, count($h->runs('first')));
        A::same(1, count($h->runs('second')));
    }

    private function choosesOneWinnerAcrossConcurrentClaims(RecoveryHarness $h): void
    {
        A::same(1, count($h->raceClaims('sync', 0)));
        A::same(1, count($h->runs('sync')));
    }

    private function assignsFreshOwnerTokens(RecoveryHarness $h): void
    {
        $first = A::claimed($h->claim('first', 0));
        A::same(true, $h->finish($first));
        $second = A::claimed($h->claim('first', 60));
        A::same(false, $first->ownerToken === $second->ownerToken);
        A::same(false, $first->id === $second->id);
    }
}
