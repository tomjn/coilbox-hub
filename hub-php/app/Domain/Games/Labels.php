<?php

namespace App\Domain\Games;

/**
 * What a game's row says, in the words a page prints, `lib/games/labels.ts`.
 * The listing and the game's own page are two views of one row, and sentences
 * written twice drift the first time somebody edits one.
 */
final class Labels
{
    /** The name a reader sees. A backfilled game is a shortname alone until anybody names it. */
    public static function gameTitle(array $game): string
    {
        return $game['display_name'] ?? $game['shortname'];
    }

    /** "2 factions, 340 units". Zero is printed as zero rather than hidden. */
    public static function gameCountLabel(array $game): string
    {
        return implode(', ', array_map(
            fn (array $part) => $part['count'].' '.$part['noun'],
            self::gameCountParts($game),
        ));
    }

    /** @return list<array{count: int, noun: string}> */
    public static function gameCountParts(array $game): array
    {
        return [
            ['count' => $game['faction_count'], 'noun' => $game['faction_count'] === 1 ? 'faction' : 'factions'],
            ['count' => $game['unit_count'], 'noun' => 'units'],
        ];
    }

    /**
     * Whether a description says anything beyond the game's name. Some games
     * describe themselves with their own shortname, which reads as a placeholder.
     */
    public static function saysMoreThanName(array $game, ?string $plainDescription): bool
    {
        $said = Js::lower(Js::trim($plainDescription ?? ''));
        if ($said === '') {
            return false;
        }
        $names = [];
        foreach ([$game['shortname'] ?? null, $game['display_name'] ?? null] as $name) {
            if ($name !== null) {
                $names[] = Js::lower(Js::trim($name));
            }
        }

        return ! in_array($said, $names, true);
    }

    /** "Play as ARM or CORE." Null for a game with no sides reported. @param list<string> $factionNames */
    public static function playAsLabel(array $factionNames): ?string
    {
        if ($factionNames === []) {
            return null;
        }

        return 'Play as '.Js::listFormat($factionNames, 'disjunction').'.';
    }

    /** "0 community items" under a link reads as a dead end, so nothing held says so. */
    public static function itemCardLabel(int $count): string
    {
        return $count === 0 ? 'Nothing published yet' : self::itemCountLabel($count);
    }

    public static function itemCountLabel(int $count): string
    {
        return $count === 1 ? '1 community item' : $count.' community items';
    }
}
