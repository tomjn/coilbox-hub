<?php

namespace App\Domain\Games;

use App\Supabase\Client;
use App\Supabase\Local\GameStore;
use App\Supabase\Local\MirrorSources;
use App\Supabase\Local\MirrorStore;
use App\Supabase\SupabaseException;

/**
 * Everything one game's page shows, in one place a test can reach (#226).
 *
 * Nothing here needs the secret key. There is no licence gate in front of a
 * game, so the whole read runs through an anonymous client and row level
 * security.
 */
final class Page
{
    /**
     * Null when the game is not there or a read fails.
     *
     * The page is an array with the keys `shortname`, `display_name`,
     * `description`, `links`, `conquest_factions`, `faction_count`,
     * `unit_count`, `item_count`, `factions` (alphabetical, Random left out,
     * each `key, name, logo_path, tagline, description`), `release` (the version most recently
     * reported, or null), `owner_user_id`, `hidden_at`, the `logo_`, `banner_`
     * and `card_` columns (`_path`, `_hash`, `_staged_tier`), `downloads`,
     * `base_game`, `base` (a related game or null) and `mutators`.
     *
     * @return array<string, mixed>|null
     */
    public static function loadGamePage(Client $supabase, string $shortname): ?array
    {
        // One query for the row and everything hanging off it, one for the two
        // aggregates. The counts live in public.game_browse rather than on
        // public.game because they are queries and not stored values, which
        // 20260821120000_game_browse.sql argues at length. The embeds ride the
        // foreign keys the catalog tables already declare.
        // Supabase's copy of the catalogue has no faction text, and asking it
        // for a column it lacks fails the whole read.
        $factionColumns = GameStore::local() ? 'key,name,logo_path,tagline,description' : 'key,name,logo_path';
        try {
            $held = $supabase
                ->from('game')
                ->select(
                    'id,shortname,display_name,description,links,conquest_factions,owner_user_id,hidden_at,base_game,'.
                    'logo_path,logo_hash,logo_staged_tier,banner_path,banner_hash,banner_staged_tier,'.
                    'card_path,card_hash,card_staged_tier,'.
                    'game_download_source(kind,value,asset,filename,sort_order),'.
                    'game_faction('.$factionColumns.'),'.
                    'game_version(version,last_seen_at)',
                )
                ->eq('shortname', $shortname)
                ->order('sort_order', ascending: true, foreignTable: 'game_download_source')
                ->order('id', ascending: true, foreignTable: 'game_download_source')
                ->order('name', ascending: true, foreignTable: 'game_faction')
                ->order('last_seen_at', ascending: false, foreignTable: 'game_version')
                ->limit(1, foreignTable: 'game_version')
                ->maybeSingle();
            $counts = $supabase
                ->from('game_browse')
                ->select('faction_count,unit_count,item_count')
                ->eq('shortname', $shortname)
                ->maybeSingle();
        } catch (SupabaseException) {
            return null;
        }
        if ($held === null || $counts === null) {
            return null;
        }

        $base = ($held['base_game'] ?? null) ? Mutators::loadBaseGame($supabase, $held['base_game']) : null;
        $mutators = Mutators::loadMutators($supabase, $held['shortname']);

        $factions = array_values(array_filter(
            $held['game_faction'] ?? [],
            fn (array $faction) => ! Factions::isRandomFaction($faction),
        ));
        $factions = array_map(fn (array $faction) => $faction + ['tagline' => null, 'description' => null], $factions);
        $factions = self::withLiveLogos($supabase, $held['shortname'], $factions);

        return [
            'id' => $held['id'] ?? null,
            'shortname' => $held['shortname'] ?? null,
            'display_name' => $held['display_name'] ?? null,
            'description' => $held['description'] ?? null,
            'links' => Catalog::parseGameLinks($held['links'] ?? null),
            'conquest_factions' => Catalog::parseConquestFactions($held['conquest_factions'] ?? null),
            'faction_count' => $counts['faction_count'],
            'unit_count' => $counts['unit_count'],
            'item_count' => $counts['item_count'],
            'factions' => $factions,
            'release' => $held['game_version'][0]['version'] ?? null,
            'owner_user_id' => $held['owner_user_id'] ?? null,
            'hidden_at' => $held['hidden_at'] ?? null,
            'logo_path' => $held['logo_path'] ?? null,
            'logo_hash' => $held['logo_hash'] ?? null,
            'logo_staged_tier' => $held['logo_staged_tier'] ?? null,
            'banner_path' => $held['banner_path'] ?? null,
            'banner_hash' => $held['banner_hash'] ?? null,
            'banner_staged_tier' => $held['banner_staged_tier'] ?? null,
            'card_path' => $held['card_path'] ?? null,
            'card_hash' => $held['card_hash'] ?? null,
            'card_staged_tier' => $held['card_staged_tier'] ?? null,
            'downloads' => [...Download::readDownloads($held['game_download_source'] ?? null), ...self::mirrorCopies($supabase, $held['id'] ?? null)],
            'base_game' => $held['base_game'] ?? null,
            'base' => $base,
            'mutators' => $mutators,
        ];
    }

    /**
     * Each side's logo, read from the approved logo pictures. In Supabase a
     * trigger on `asset` copies the newest approved logo onto `game_faction`.
     * Pictures stay in Supabase until #598 while sides moved to MariaDB, so
     * no trigger reaches them, and the page asks the pictures directly. The
     * stored column stays as it was copied.
     *
     * @param  list<array<string, mixed>>  $factions
     * @return list<array<string, mixed>>
     */
    private static function withLiveLogos(Client $supabase, string $shortname, array $factions): array
    {
        if ($factions === []) {
            return $factions;
        }
        try {
            $logos = $supabase->from('asset')
                ->select('faction_key,path')
                ->eq('game', $shortname)
                ->eq('variant', 'logo')
                ->eq('moderation', 'approved')
                ->eq('tier', 'static')
                ->not('faction_key', 'is', null)
                ->order('updated_at', ascending: false)
                ->order('id')
                ->get();
        } catch (SupabaseException) {
            // The copied logos are the best left to show.
            return $factions;
        }
        $newest = [];
        foreach ($logos as $logo) {
            $newest[$logo['faction_key']] ??= $logo['path'];
        }

        return array_map(fn (array $faction) => ['logo_path' => $newest[$faction['key']] ?? null] + $faction, $factions);
    }

    /**
     * The game's uploads on the healthy hub mirrors, newest upload first.
     * Supabase's function knows only the newest upload. A failed read leaves
     * the page without them rather than without the page.
     *
     * @return list<array<string, mixed>>
     */
    private static function mirrorCopies(Client $supabase, ?string $gameId): array
    {
        if ($gameId === null) {
            return [];
        }
        try {
            $copies = MirrorStore::local()
                ? MirrorSources::gameUploads($gameId)
                : $supabase->remote()->rpc('game_mirror_sources', ['p_game_id' => $gameId]);
        } catch (SupabaseException) {
            return [];
        }

        return is_array($copies) ? array_values(array_filter(
            $copies,
            fn ($copy) => is_array($copy) && ($copy['kind'] ?? null) === 'url' && is_string($copy['value'] ?? null) && is_string($copy['filename'] ?? null),
        )) : [];
    }

    /**
     * The download list split for the page, keyed by each entry's place in
     * `$downloads`, which the counted redirect needs. `current` holds the
     * owner's sources and the newest upload's copies. `older` holds each
     * older upload with its copies.
     *
     * @param  list<array<string, mixed>>  $downloads
     * @return array{current: array<int, array<string, mixed>>, older: list<array{version: string, filename: string, copies: array<int, array<string, mixed>>}>}
     */
    public static function byUpload(array $downloads): array
    {
        $current = [];
        $older = [];
        $newest = null;
        foreach ($downloads as $i => $download) {
            $archive = $download['archive'] ?? null;
            $newest ??= $archive;
            if ($archive === null || $archive === $newest) {
                $current[$i] = $download;

                continue;
            }
            $older[$archive] ??= ['version' => (string) ($download['version'] ?? ''), 'filename' => (string) $download['filename'], 'copies' => []];
            $older[$archive]['copies'][$i] = $download;
        }

        return ['current' => $current, 'older' => array_values($older)];
    }
}
