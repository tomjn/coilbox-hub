<?php

namespace App\Domain\Replays;

/**
 * One player's input figures from the end of game block, in the order they are
 * on disk. The engine counts a command each time the player's selection is
 * given an order, and adds the selection's size to the unit commands.
 */
final readonly class PlayerFigures
{
    public function __construct(
        public int $commands,
        public int $unitCommands,
        public int $mousePixels,
        public int $mouseClicks,
        public int $keyPresses,
    ) {}

    public static function unpack(string $bytes, int $at): self
    {
        $v = unpack('l5', $bytes, $at);

        return new self($v[1], $v[2], $v[3], $v[4], $v[5]);
    }
}
