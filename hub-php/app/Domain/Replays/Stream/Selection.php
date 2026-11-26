<?php

namespace App\Domain\Replays\Stream;

final readonly class Selection
{
    public function __construct(
        public int $frame,
        public int $player,
        /** @var list<int> */
        public array $units,
    ) {}
}
