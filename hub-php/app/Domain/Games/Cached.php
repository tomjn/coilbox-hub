<?php

namespace App\Domain\Games;

use App\Supabase\Supabase;
use App\Support\HubCache;

/**
 * The games catalog's reads, held between requests, `lib/games/cached.ts`.
 * Every write clears them by tag straight away, so the long life only bounds
 * an edit made straight in the database. Long, because a crawler walks every
 * unit page. Reads that draw pictures carry the assets tag too, because an
 * approved buildpic changes a cell.
 *
 * Every loader reads through the anonymous client, so what is cached is what
 * anybody may see.
 */
final class Cached
{
    private const LIFE = HubCache::WEEKS;

    /** @return array{games: list<array>, error: string|null} */
    public static function gamesListing(): array
    {
        return self::remember('games:listing', [HubCache::GAMES], function () {
            if (! Supabase::configured()) {
                return ['games' => [], 'error' => null];
            }

            return Query::fetchGames(Supabase::anon());
        });
    }

    /** @param list<string> $shortnames @return array<string, array> */
    public static function gameSides(array $shortnames): array
    {
        return self::remember('games:sides:'.implode(',', $shortnames), [HubCache::GAMES, HubCache::ASSETS], function () use ($shortnames) {
            if (! Supabase::configured()) {
                return [];
            }

            return Sides::loadGameSides(Supabase::anon(), $shortnames);
        });
    }

    /** One game's page, or null for a shortname nobody holds. */
    public static function gamePage(string $shortname): ?array
    {
        return self::remember('games:page:'.$shortname, [HubCache::GAMES], function () use ($shortname) {
            if (! Supabase::configured()) {
                return null;
            }

            return Page::loadGamePage(Supabase::anon(), $shortname);
        });
    }

    /** Every release a game has, newest report first. Empty when the read fails. @return list<string> */
    public static function gameReleases(string $shortname): array
    {
        return self::remember('games:releases:'.$shortname, [HubCache::GAMES], function () use ($shortname) {
            if (! Supabase::configured()) {
                return [];
            }

            return array_map(fn (array $release) => $release['version'], Changes::loadGameReleases(Supabase::anon(), $shortname) ?? []);
        });
    }

    /**
     * One page of a game's units, with a buildpic per cell. The shelf hides
     * what a player can never reach and every level of a morph chain but its
     * base, both inside the query, because filtering after paging would shift
     * every window and lie with the count.
     *
     * @return array{units: list<array{unit: array, picture: array}>, count: int, error: string|null}
     */
    public static function unitGrid(string $shortname, array $filters): array
    {
        return self::remember('games:grid:'.$shortname.':'.json_encode($filters), [HubCache::GAMES, HubCache::ASSETS], function () use ($shortname, $filters) {
            if (! Supabase::configured()) {
                return ['units' => [], 'count' => 0, 'error' => null];
            }
            $supabase = Supabase::anon();
            $exclude = [...Units::unbuildableUnits($supabase, $shortname), ...Units::morphedAwayUnits($supabase, $shortname)];
            $grid = Units::loadUnitGrid($supabase, $shortname, $filters, $exclude);
            $pictures = Units::unitBuildpics($supabase, $shortname, $grid['units']);

            return [
                'units' => array_map(fn (array $unit) => [
                    'unit' => $unit,
                    'picture' => $pictures[$unit['unit_name']] ?? ['from' => 'placeholder', 'keyedOn' => 'unit', 'name' => $unit['unit_name'], 'footprint' => null],
                ], $grid['units']),
                'count' => $grid['count'],
                'error' => $grid['error'],
            ];
        });
    }

    /** Whether this game has retired a unit, which is whether the grid draws the retired toggle at all. */
    public static function retiredUnitsHeld(string $shortname): bool
    {
        return self::remember('games:retired:'.$shortname, [HubCache::GAMES], function () use ($shortname) {
            return Supabase::configured() && Units::hasRetiredUnits(Supabase::anon(), $shortname);
        });
    }

    /**
     * One unit's page, its renders, its buildpic, and a buildpic for every
     * unit it builds, is built by, or turns into. Null when neither the game
     * nor the unit is held.
     *
     * @return array{page: array, renders: list<array>, buildpic: array, buildPictures: array<string, array>}|null
     */
    public static function unitPage(string $shortname, string $unitName, ?string $version = null): ?array
    {
        $key = 'games:unit:'.$shortname.':'.$unitName.':'.($version ?? '');

        return self::remember($key, [HubCache::GAMES, HubCache::ASSETS, HubCache::unit($shortname, $unitName)], function () use ($shortname, $unitName, $version) {
            if (! Supabase::configured()) {
                return null;
            }
            $supabase = Supabase::anon();
            $page = Units::loadUnitPage($supabase, $shortname, $unitName, $version);
            if ($page === null) {
                return null;
            }
            // Both directions of the edge and every stage draw as cells, one
            // buildpic each, deduped so a unit that builds its own builder is
            // asked for once.
            $cells = [];
            foreach ([...$page['built_by'], ...$page['builds']] as $entry) {
                $cells[$entry['name']] = ['unit_name' => $entry['name'], 'full_name' => $entry['label'], 'faction_key' => null];
            }
            foreach ($page['stages'] as $stage) {
                $cells[$stage['unit_name']] = ['unit_name' => $stage['unit_name'], 'full_name' => $stage['label'], 'faction_key' => null];
            }

            return [
                'page' => $page,
                'renders' => Units::unitRenders($supabase, $shortname, $unitName),
                'buildpic' => Units::unitBuildpic($supabase, $shortname, $unitName),
                'buildPictures' => Units::unitBuildpics($supabase, $shortname, array_values($cells)),
            ];
        });
    }

    /** Two releases of one unit, side by side. */
    public static function unitCompare(string $shortname, string $unitName, string $leftVersion, string $rightVersion): ?array
    {
        return self::remember('games:compare:'.json_encode([$shortname, $unitName, $leftVersion, $rightVersion]), [HubCache::GAMES], function () use ($shortname, $unitName, $leftVersion, $rightVersion) {
            return Supabase::configured() ? Units::loadUnitComparison(Supabase::anon(), $shortname, $unitName, $leftVersion, $rightVersion) : null;
        });
    }

    /**
     * Two different units of one game with all their revisions and their
     * buildpics. Null when either is not held.
     *
     * @return array{units: array{0: array, 1: array}, pictures: array<string, array>}|null
     */
    public static function unitPair(string $shortname, string $nameA, string $nameB): ?array
    {
        return self::remember('games:pair:'.json_encode([$shortname, $nameA, $nameB]), [HubCache::GAMES, HubCache::ASSETS], function () use ($shortname, $nameA, $nameB) {
            if (! Supabase::configured()) {
                return null;
            }
            $supabase = Supabase::anon();
            $units = CompareUnits::loadUnitPair($supabase, $shortname, $nameA, $nameB);
            if ($units === null) {
                return null;
            }
            $pictures = Units::unitBuildpics($supabase, $shortname, array_map(
                fn (array $unit) => ['unit_name' => $unit['unit_name'], 'full_name' => $unit['full_name'], 'faction_key' => null],
                $units,
            ));

            return ['units' => $units, 'pictures' => $pictures];
        });
    }

    /** What changed between two releases of a game. Null when the read fails. */
    public static function releaseChanges(string $shortname, string $from, string $to): ?array
    {
        return self::remember('games:changes:'.json_encode([$shortname, $from, $to]), [HubCache::GAMES], function () use ($shortname, $from, $to) {
            return Supabase::configured() ? Changes::loadReleaseChanges(Supabase::anon(), $shortname, $from, $to) : null;
        });
    }

    /** Every side a game has, as the filters offer them. @return list<array{key: string, name: string}> */
    public static function gameFactions(string $shortname): array
    {
        return self::remember('games:factions:'.$shortname, [HubCache::GAMES], function () use ($shortname) {
            return Supabase::configured() ? Units::gameFactions(Supabase::anon(), $shortname) : [];
        });
    }

    /** What the catalog calls every unit of a game, keyed on the lower cased def. */
    public static function unitNameLabels(string $shortname): array
    {
        return self::remember('games:labels:'.$shortname, [HubCache::GAMES], function () use ($shortname) {
            return Supabase::configured() ? Units::unitNameLabels(Supabase::anon(), $shortname) : [];
        });
    }

    /** What every unit of a game does, for colouring a blueprint's buildings. */
    public static function unitRoles(string $shortname): array
    {
        return self::remember('games:roles:'.$shortname, [HubCache::GAMES], function () use ($shortname) {
            return Supabase::configured() ? Units::unitRoles(Supabase::anon(), $shortname) : [];
        });
    }

    /** The buildpics for a tree's units, keyed by unit name, one batched read. @param list<string> $unitNames */
    public static function treeUnitPictures(string $shortname, array $unitNames): array
    {
        return self::remember('games:treepics:'.$shortname.':'.sha1(json_encode($unitNames)), [HubCache::GAMES, HubCache::ASSETS], function () use ($shortname, $unitNames) {
            if (! Supabase::configured()) {
                return [];
            }

            return Units::unitBuildpics(Supabase::anon(), $shortname, array_map(
                fn (string $name) => ['unit_name' => $name, 'full_name' => null, 'faction_key' => null],
                $unitNames,
            ));
        });
    }

    /** One game's build tree, current or as a release said, scoped to one faction when the reader picked one. */
    public static function tree(string $shortname, ?string $version = null, ?string $factionKey = null): ?array
    {
        return self::remember('games:tree:'.json_encode([$shortname, $version, $factionKey]), [HubCache::GAMES], function () use ($shortname, $version, $factionKey) {
            return Supabase::configured() ? Tree::loadTree(Supabase::anon(), $shortname, $version, $factionKey) : null;
        });
    }

    private static function remember(string $key, array $tags, \Closure $load): mixed
    {
        return HubCache::remember($key, $tags, self::LIFE, $load);
    }
}
