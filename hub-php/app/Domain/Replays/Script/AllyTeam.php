<?php

namespace App\Domain\Replays\Script;

/**
 * An `[allyteamN]` block. The start box is in fractions of the map from 0 to 1,
 * and null when the script gives none or gives one with no area.
 */
final readonly class AllyTeam
{
    public function __construct(
        public int $id,
        /** @var array{left: float, top: float, right: float, bottom: float}|null */
        public ?array $startBox,
    ) {}
}
