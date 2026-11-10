<?php

namespace App\Domain\Games;

use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * The two reads that tie a mutator to its base game on the game page (#547).
 *
 * `public.game.base_game` is a shortname with no foreign key, because the base
 * game may not be on the hub. So the link back is a lookup that can come up
 * empty, and the page names the base game without a link when it does.
 *
 * Both reads go through `public.game_browse`, which is security invoker over
 * tables with the hiding rules on them. A hidden game is therefore neither
 * listed as a mutator nor linked as a base game.
 *
 * A related game is `['shortname', 'title']`, the title being the display name
 * where one is held and the shortname otherwise.
 */
final class Mutators
{
    /** @param array<string, mixed> $row @return array{shortname: string, title: string} */
    private static function related(array $row): array
    {
        return ['shortname' => $row['shortname'], 'title' => Labels::gameTitle($row)];
    }

    /**
     * The base game as the hub holds it, or null when it is not there or
     * cannot be read.
     *
     * @return array{shortname: string, title: string}|null
     */
    public static function loadBaseGame(Client $supabase, string $baseGame): ?array
    {
        try {
            $data = $supabase
                ->from('game_browse')
                ->select('shortname,display_name')
                ->eq('shortname', $baseGame)
                ->maybeSingle();
        } catch (SupabaseException) {
            return null;
        }

        return $data === null ? null : self::related($data);
    }

    /**
     * Every game built on this one, alphabetical by the title a reader sees,
     * with the shortname as the tiebreak. Empty when there are none or the
     * read fails.
     *
     * @return list<array{shortname: string, title: string}>
     */
    public static function loadMutators(Client $supabase, string $shortname): array
    {
        try {
            $rows = $supabase
                ->from('game_browse')
                ->select('shortname,display_name')
                ->eq('base_game', $shortname)
                ->get();
        } catch (SupabaseException) {
            return [];
        }
        $games = array_map(self::related(...), $rows);
        usort($games, fn (array $left, array $right) => Js::localeCompare($left['title'], $right['title'])
            ?: Js::localeCompare($left['shortname'], $right['shortname']));

        return $games;
    }
}
