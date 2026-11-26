<?php

namespace App\Domain\Replays\Script;

/** A `[teamN]` block. The end of game samples are by this number. */
final readonly class Team
{
    public function __construct(
        public int $id,
        public ?int $allyTeam,
        public ?int $leader,
        public ?string $side,
        /** @var array{0: float, 1: float, 2: float}|null */
        public ?array $colour,
    ) {}
}
