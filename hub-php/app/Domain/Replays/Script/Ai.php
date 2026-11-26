<?php

namespace App\Domain\Replays\Script;

/** An `[aiN]` seat: a skirmish or Lua AI playing a team, hosted by a player. */
final readonly class Ai
{
    public function __construct(
        public int $id,
        public string $name,
        public string $shortName,
        public ?string $version,
        public ?int $team,
        public ?int $allyTeam,
        public ?string $side,
        /** @var array{0: float, 1: float, 2: float}|null */
        public ?array $colour,
        public ?int $host,
    ) {}
}
