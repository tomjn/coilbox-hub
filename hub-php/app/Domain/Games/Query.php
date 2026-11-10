<?php

namespace App\Domain\Games;

use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * The games listing's read, `lib/games/query.ts`. One query, no pagination,
 * because a catalog of games is a shelf rather than a warehouse.
 */
final class Query
{
    /** Everything `public.game_browse` publishes, which is the whole of what a card shows. */
    public const GAME_SUMMARY_COLUMNS = 'shortname,display_name,description,logo_path,logo_hash,logo_staged_tier,featured_at,downloads,card_path,card_hash,card_staged_tier,banner_path,banner_hash,banner_staged_tier,faction_count,unit_count,item_count,base_game';

    /**
     * Featured first, then alphabetical by the title a reader scans, then by
     * shortname, which is unique, so the order never shuffles between requests.
     */
    public static function compareGames(array $left, array $right): int
    {
        $leftFeatured = ($left['featured_at'] ?? null) !== null;
        $rightFeatured = ($right['featured_at'] ?? null) !== null;
        if ($leftFeatured !== $rightFeatured) {
            return $leftFeatured ? -1 : 1;
        }
        $byTitle = Js::localeCompare(Labels::gameTitle($left), Labels::gameTitle($right));
        if ($byTitle !== 0) {
            return $byTitle;
        }

        return Js::localeCompare($left['shortname'], $right['shortname']);
    }

    /**
     * Every game that is not a mutator. A mutator is reached from its base
     * game's page and from the API, which keeps returning it.
     *
     * @param  list<array>  $games
     * @return list<array>
     */
    public static function withoutMutators(array $games): array
    {
        return array_values(array_filter($games, fn (array $game) => ($game['base_game'] ?? null) === null));
    }

    /** @return array{games: list<array>, error: string|null} */
    public static function fetchGames(Client $supabase): array
    {
        try {
            $rows = $supabase->from('game_browse')->select(self::GAME_SUMMARY_COLUMNS)->get();
        } catch (SupabaseException $e) {
            return ['games' => [], 'error' => $e->getMessage()];
        }
        usort($rows, self::compareGames(...));

        return ['games' => $rows, 'error' => null];
    }
}
