<?php

namespace App\Domain\Replays\Stream;

/** A start position message. Ready 3 means the player never readied. */
final readonly class StartPosition
{
    public const READY_FAILED = 3;

    public function __construct(
        public int $frame,
        public int $player,
        public int $team,
        public int $ready,
        public float $x,
        public float $y,
        public float $z,
    ) {}
}
