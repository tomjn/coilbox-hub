<?php

namespace App\Domain\Games;

/**
 * Whether a side is the die roll rather than an army, as `lib/games/factions.ts`
 * (#280).
 *
 * Games report a faction called Random so a lobby can pick one for you. It has
 * no units of its own worth a strip, a filter or a tree block. The games
 * listing view leaves it out of its faction count by the same rule, so the
 * count and the sides a page draws agree.
 */
final class Factions
{
    /** @param array{key: string, name: string} $faction */
    public static function isRandomFaction(array $faction): bool
    {
        return Js::lower(Js::trim($faction['key'])) === 'random'
            || Js::lower(Js::trim($faction['name'])) === 'random';
    }
}
