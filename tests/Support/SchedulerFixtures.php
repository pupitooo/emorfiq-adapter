<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Scheduler\Contract\SchedulerConfiguration;
use App\Scheduler\Domain\Configuration;
use App\Scheduler\Domain\ScheduleDefinition;
use LogicException;
use Override;

final readonly class SchedulerFixtures
{
    /**
     * @param list<ScheduleDefinition> $definitions
     */
    public static function configuration(array $definitions): SchedulerConfiguration
    {
        return new class(new Configuration(1, $definitions)) implements SchedulerConfiguration {
            public function __construct(
                private readonly Configuration $configuration,
            ) {}

            /**
             * @throws void
             */
            #[Override]
            public function getConfiguration(): Configuration
            {
                return $this->configuration;
            }
        };
    }

    public static function read(string $fixture): string
    {
        $json = file_get_contents(__DIR__ . '/../Fixtures/Scheduler/' . $fixture);
        if ($json === false) {
            throw new LogicException('Unable to read scheduler test fixture: ' . $fixture);
        }

        return $json;
    }
}
