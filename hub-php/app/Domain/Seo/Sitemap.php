<?php

namespace App\Domain\Seo;

use App\Domain\Gallery\Feed;
use App\Domain\Games\ReadAll;
use App\Domain\Maps\Lookup;
use App\Supabase\Supabase;
use App\Support\HubCache;
use RuntimeException;

/**
 * Every public page, as one file, from `app/sitemap.ts` and `lib/sitemap`.
 *
 * One loader per tag, so a write clears only what it touched. Each reads every
 * row, and a failed read throws, because a cached short list would drop pages
 * from the sitemap until it was read again.
 */
final class Sitemap
{
    /** The most URLs the sitemap protocol allows in one file. */
    public const LIMIT = 50000;

    /**
     * Every URL the sitemap lists. Throws past {@see LIMIT}: a search engine
     * ignores a sitemap over the limit, so cutting the list short would drop
     * pages without anybody being told. The answer then is a sitemap index.
     *
     * @param  array{items: list<array{id: string, updated_at: string}>, maps: list<array{slug: string}>, games: list<array{shortname: string, units: list<string>, releases: int}>}  $source
     * @return list<array{url: string, lastModified?: string}>
     */
    public static function entries(string $origin, array $source): array
    {
        $entries = [
            ['url' => $origin.'/'],
            ['url' => $origin.'/gallery'],
            ['url' => $origin.'/maps'],
            ['url' => $origin.'/games'],
            ['url' => $origin.'/developers'],
        ];
        foreach ($source['items'] as $item) {
            $entries[] = ['url' => $origin.'/item/'.self::part($item['id']), 'lastModified' => Feed::rfc3339($item['updated_at'])];
        }
        foreach ($source['maps'] as $map) {
            $entries[] = ['url' => $origin.'/map/'.self::part($map['slug'])];
        }
        foreach ($source['games'] as $game) {
            $base = $origin.'/games/'.self::part($game['shortname']);
            $entries[] = ['url' => $base];
            $entries[] = ['url' => $base.'/units'];
            // The page compares two releases, so a game with one has nothing on it.
            if ($game['releases'] >= 2) {
                $entries[] = ['url' => $base.'/changes'];
            }
            foreach ($game['units'] as $unit) {
                $entries[] = ['url' => $base.'/units/'.self::part($unit)];
            }
        }

        if (count($entries) > self::LIMIT) {
            throw new RuntimeException('The sitemap has '.count($entries).' URLs and one file may hold '.self::LIMIT.'. Split it with a sitemap index.');
        }

        return $entries;
    }

    /** The XML as Next writes a `MetadataRoute.Sitemap`. @param list<array{url: string, lastModified?: string}> $entries */
    public static function xml(array $entries): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($entries as $entry) {
            $out .= "<url>\n<loc>".Feed::escapeText($entry['url'])."</loc>\n";
            if (isset($entry['lastModified'])) {
                $out .= '<lastmod>'.$entry['lastModified']."</lastmod>\n";
            }
            $out .= "</url>\n";
        }

        return $out."</urlset>\n";
    }

    public static function build(string $origin): string
    {
        return self::xml(self::entries($origin, [
            'items' => self::items(),
            'maps' => self::maps(),
            'games' => self::games(),
        ]));
    }

    /**
     * Items the public can see. The anonymous client means row level security
     * leaves out a withdrawn item, the same as the gallery.
     *
     * Every loader holds its read for weeks. Every write clears it by tag, so
     * this only bounds an edit made straight in the database, and a crawler
     * walking every page would otherwise cost a render each.
     *
     * @return list<array{id: string, updated_at: string}>
     */
    public static function items(): array
    {
        return HubCache::remember('sitemap:items', [HubCache::ITEMS], HubCache::WEEKS, function () {
            $supabase = Supabase::anon();
            $rows = self::all(ReadAll::rows(fn () => $supabase->from('item')->select('id,updated_at')->order('created_at', false)->order('id')), 'the items');

            return array_map(fn ($row) => ['id' => (string) $row['id'], 'updated_at' => (string) $row['updated_at']], $rows);
        });
    }

    /**
     * Maps the hub publishes a page for, by the licence gate the map page
     * applies, so a taken down map is not listed.
     *
     * @return list<array{slug: string}>
     */
    public static function maps(): array
    {
        return HubCache::remember('sitemap:maps', [HubCache::MAPS], HubCache::WEEKS, function () {
            $supabase = Supabase::anon();
            $rows = self::all(ReadAll::rows(fn () => $supabase->from('map')->select('map_name,slug')->order('slug')), 'the maps');
            $published = Lookup::publishedNames(Supabase::admin(), array_values(array_unique(array_map(fn ($row) => (string) $row['map_name'], $rows))));
            if ($published === null) {
                throw new RuntimeException('Could not read the map licences for the sitemap.');
            }
            $published = array_flip($published);
            $out = [];
            foreach ($rows as $row) {
                if (isset($published[$row['map_name']])) {
                    $out[] = ['slug' => (string) $row['slug']];
                }
            }

            return $out;
        });
    }

    /**
     * Games that are not hidden, with their live units and how many releases
     * each holds. Row level security leaves out a hidden game and its units.
     * A unit a release retired is left out, as it is from the units grid.
     *
     * @return list<array{shortname: string, units: list<string>, releases: int}>
     */
    public static function games(): array
    {
        return HubCache::remember('sitemap:games', [HubCache::GAMES], HubCache::WEEKS, function () {
            $supabase = Supabase::anon();
            $games = self::all(ReadAll::rows(fn () => $supabase->from('game')->select('shortname')->order('shortname')), 'the games');
            $units = self::all(ReadAll::rows(fn () => $supabase->from('game_unit')->select('unit_name,game!inner(shortname)')->is('removed_at', null)->order('game_id')->order('unit_name')), 'the units');
            $versions = self::all(ReadAll::rows(fn () => $supabase->from('game_version')->select('game!inner(shortname)')->order('game_id')->order('id')), 'the releases');

            $releases = [];
            foreach ($versions as $version) {
                $name = $version['game']['shortname'];
                $releases[$name] = ($releases[$name] ?? 0) + 1;
            }
            $byGame = [];
            foreach ($games as $game) {
                $byGame[$game['shortname']] = [];
            }
            foreach ($units as $unit) {
                $name = $unit['game']['shortname'];
                if (isset($byGame[$name])) {
                    $byGame[$name][] = (string) $unit['unit_name'];
                }
            }
            $out = [];
            foreach ($byGame as $shortname => $held) {
                $out[] = ['shortname' => (string) $shortname, 'units' => $held, 'releases' => $releases[$shortname] ?? 0];
            }

            return $out;
        });
    }

    /** `encodeURIComponent`, which leaves `!'()*` alone where `rawurlencode` does not. */
    private static function part(string $value): string
    {
        return strtr(rawurlencode($value), ['%21' => '!', '%27' => "'", '%28' => '(', '%29' => ')', '%2A' => '*']);
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     * @return list<array<string, mixed>>
     */
    private static function all(?array $rows, string $what): array
    {
        if ($rows === null) {
            throw new RuntimeException("Could not read {$what} for the sitemap.");
        }

        return $rows;
    }
}
