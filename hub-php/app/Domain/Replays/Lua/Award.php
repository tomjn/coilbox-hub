<?php

namespace App\Domain\Replays\Lua;

/** One place in one of Beyond All Reason's end of game awards. */
final readonly class Award
{
    public function __construct(
        /** The award's name as Beyond All Reason's replay site gives it, such as `fightingUnitsDestroyed`. */
        public string $name,
        /** The team that won this place, or null when nobody did. */
        public ?int $team,
        public ?float $value,
    ) {}
}
