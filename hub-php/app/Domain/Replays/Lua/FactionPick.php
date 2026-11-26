<?php

namespace App\Domain\Replays\Lua;

/**
 * A player's faction pick before the game. Games that pick by start unit give
 * a unit def id. Metal Factions gives the faction's name.
 */
final readonly class FactionPick
{
    public function __construct(
        public ?int $unitDefId,
        public ?string $faction,
    ) {}
}
