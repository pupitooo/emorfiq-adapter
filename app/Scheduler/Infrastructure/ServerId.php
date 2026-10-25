<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure;

use RuntimeException;

final class ServerId
{
    private function __construct() {}

    /**
     * @throws RuntimeException
     */
    public static function fromEnvironment(): string
    {
        $serverId = getenv('SERVER_ID');
        if ($serverId === false || trim($serverId) === '') {
            throw new RuntimeException('Missing server identity setting: SERVER_ID');
        }
        if (trim($serverId) !== $serverId) {
            throw new RuntimeException('SERVER_ID must not contain surrounding whitespace.');
        }

        return $serverId;
    }
}
