<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use LogicException;

/**
 * Line protocol used only inside the child; parent kills a paused process for crash scenarios.
 */
final readonly class RecoveryChannel
{
    /**
     * @throws \JsonException
     */
    public static function send(string $event, int|string|bool|null $value = null): void
    {
        echo json_encode([
            'event' => $event,
            'value' => $value,
        ], JSON_THROW_ON_ERROR) . PHP_EOL;
        flush();
    }

    /**
     * @throws \JsonException
     */
    public static function pause(string $event): void
    {
        self::send($event);
        if (fgets(STDIN) !== "continue\n") {
            throw new LogicException('Recovery barrier was not released: ' . $event);
        }
    }
}
