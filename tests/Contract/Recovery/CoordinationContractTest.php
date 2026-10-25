<?php

declare(strict_types=1);

namespace Tests\Contract\Recovery;

use Tests\Support\Recovery\RecoveryAssertions as A;
use Tests\Support\Recovery\RecoveryCase;
use Tests\Support\Recovery\RecoveryHarness;

final readonly class CoordinationContractTest
{
    /**
     * @return list<RecoveryCase>
     */
    public function cases(): array
    {
        return [
            new RecoveryCase($this->rejectsSameOccurrenceAfterTerminalStates(...)),
            new RecoveryCase($this->blocksLaterOccurrenceWhileProtectedRunIsActive(...)),
            new RecoveryCase($this->allowsLaterOccurrenceWithOnlySingleServerProtection(...)),
            new RecoveryCase($this->allowsSameOccurrenceAfterOverlapOnlyCompletion(...)),
            new RecoveryCase($this->allowsDuplicatesWhenBothProtectionsAreDisabled(...)),
            new RecoveryCase($this->keepsScheduleIdentifiersIndependent(...)),
            new RecoveryCase($this->choosesOneWinnerAcrossConcurrentClaims(...)),
            new RecoveryCase($this->assignsFreshOwnerTokens(...)),
        ];
    }

    /**
     * @throws \Exception
     */
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

    /**
     * @throws \Exception
     */
    private function blocksLaterOccurrenceWhileProtectedRunIsActive(RecoveryHarness $h): void
    {
        A::claimed($h->claim('sync', 0));
        $h->at(60);
        A::same(null, $h->claim('sync', 60));
        A::same(1, count($h->runs('sync')));
    }

    /**
     * @throws \Exception
     */
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

    /**
     * @throws \Exception
     */
    private function allowsSameOccurrenceAfterOverlapOnlyCompletion(RecoveryHarness $h): void
    {
        $claim = A::claimed($h->claim('sync', 0, onOneServer: false));
        A::same(true, $h->finish($claim));
        A::claimed($h->claim('sync', 0, onOneServer: false));
    }

    /**
     * @throws \Exception
     */
    private function allowsDuplicatesWhenBothProtectionsAreDisabled(RecoveryHarness $h): void
    {
        A::claimed($h->claim('sync', 0, false, false));
        A::claimed($h->claim('sync', 0, false, false));
        A::same(2, count($h->runs('sync')));
    }

    /**
     * @throws \Exception
     */
    private function keepsScheduleIdentifiersIndependent(RecoveryHarness $h): void
    {
        A::claimed($h->claim('first', 0));
        A::claimed($h->claim('second', 0));
        A::same(1, count($h->runs('first')));
        A::same(1, count($h->runs('second')));
    }

    /**
     * @throws \Exception
     */
    private function choosesOneWinnerAcrossConcurrentClaims(RecoveryHarness $h): void
    {
        A::same(1, count($h->raceClaims('sync', 0)));
        A::same(1, count($h->runs('sync')));
    }

    /**
     * @throws \Exception
     */
    private function assignsFreshOwnerTokens(RecoveryHarness $h): void
    {
        $first = A::claimed($h->claim('first', 0));
        A::same(true, $h->finish($first));
        $second = A::claimed($h->claim('first', 60));
        A::same(false, $first->ownerToken === $second->ownerToken);
        A::same(false, $first->id === $second->id);
    }
}
