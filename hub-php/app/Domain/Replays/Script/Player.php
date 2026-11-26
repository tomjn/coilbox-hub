<?php

namespace App\Domain\Replays\Script;

/**
 * A `[playerN]` seat. Ally team, side and colour come from the player's team.
 * A spectator has no team.
 */
final readonly class Player
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $spectator,
        public ?int $team,
        public ?int $allyTeam,
        public ?string $side,
        /** @var array{0: float, 1: float, 2: float}|null red, green and blue from 0 to 1 */
        public ?array $colour,
        /** The lobby's rating text as written, such as `[23.22]`. */
        public ?string $skill,
        public ?float $skillUncertainty,
        public ?string $countryCode,
    ) {}

    /** The first number in the skill text, or null. */
    public function rating(): ?float
    {
        return $this->skill !== null && preg_match('/-?\d+(\.\d+)?/', $this->skill, $m) === 1 ? (float) $m[0] : null;
    }
}
