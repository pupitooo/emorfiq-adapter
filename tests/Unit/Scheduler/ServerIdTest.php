<?php

declare(strict_types=1);

namespace Tests\Unit\Scheduler;

use App\Scheduler\Infrastructure\ServerId;
use LogicException;
use RuntimeException;

final readonly class ServerIdTest
{
    /**
     * @throws RuntimeException
     */
    public function run(): void
    {
        $original = getenv('SERVER_ID');
        try {
            $this->keepsConfiguredIdentityAcrossReads();
            $this->rejectsMissingOrInvalidIdentity();
        } finally {
            $this->setEnvironment($original);
        }
    }

    /**
     * @throws RuntimeException
     */
    private function keepsConfiguredIdentityAcrossReads(): void
    {
        foreach (['scheduler-prod-01', '0'] as $serverId) {
            $this->setEnvironment($serverId);
            if (ServerId::fromEnvironment() !== $serverId || ServerId::fromEnvironment() !== $serverId) {
                throw new LogicException('Server identity must match configuration across repeated reads.');
            }
        }
    }

    private function rejectsMissingOrInvalidIdentity(): void
    {
        foreach ([false, '', " \t\n", ' scheduler-prod-01', "scheduler-prod-01\n"] as $serverId) {
            $this->setEnvironment($serverId);
            $failure = $this->identityFailure();
            if (! str_contains($failure->getMessage(), 'SERVER_ID')) {
                throw new LogicException('Invalid server identity must identify its setting.', previous: $failure);
            }
        }
    }

    private function identityFailure(): RuntimeException
    {
        try {
            ServerId::fromEnvironment();
        } catch (RuntimeException $failure) {
            return $failure;
        }

        throw new LogicException('Missing or invalid server identity must stop startup.');
    }

    private function setEnvironment(string|false $serverId): void
    {
        if (! putenv($serverId === false ? 'SERVER_ID' : 'SERVER_ID=' . $serverId)) {
            throw new LogicException('Unable to set server identity for tests.');
        }
    }
}
