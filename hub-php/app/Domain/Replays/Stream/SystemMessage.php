<?php

namespace App\Domain\Replays\Stream;

/** A line the server or the game wrote to everyone's console. */
final readonly class SystemMessage
{
    public function __construct(
        public int $frame,
        public int $player,
        public string $text,
    ) {}
}
