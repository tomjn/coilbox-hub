<?php

namespace App\Domain\Gallery;

use App\Domain\Profile\Author;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use App\Support\HubCache;

/**
 * The gallery's cached reads, from the Next app's `lib/gallery/cached.ts`.
 *
 * Everything here is what the public may see, read with the anonymous client,
 * so one visitor's copy serves the next. Publishing invalidates `items`, so
 * the hour is only the floor under a write nobody made through the hub.
 */
final class Listings
{
    private const NO_CARDS = ['pictures' => [], 'shapes' => [], 'counts' => [], 'titles' => []];

    /** The newest six for the landing page: full rows at every grid width. */
    public static function newest(): array
    {
        return HubCache::remember('gallery:newest', [HubCache::ITEMS, HubCache::ASSETS], HubCache::HOURS, function () {
            if (! Supabase::configured()) {
                return ['items' => [], ...self::NO_CARDS];
            }
            $supabase = Supabase::anon();
            try {
                $items = $supabase->from('item')->select(GalleryQuery::ITEM_SUMMARY_COLUMNS)
                    ->order('created_at', ascending: false)->limit(6)->get();
            } catch (SupabaseException) {
                $items = [];
            }

            return ['items' => $items, ...Cards::forPage($supabase, $items)];
        });
    }

    /** The top three a moderator has featured (#399). */
    public static function featured(): array
    {
        return HubCache::remember('gallery:featured', [HubCache::ITEMS, HubCache::ASSETS], HubCache::HOURS, function () {
            if (! Supabase::configured()) {
                return ['items' => [], ...self::NO_CARDS];
            }
            $supabase = Supabase::anon();
            try {
                $items = $supabase->from('item')->select(GalleryQuery::ITEM_SUMMARY_COLUMNS)
                    ->not('featured_at', 'is', 'null')
                    ->order('featured_at', ascending: false)->limit(3)->get();
            } catch (SupabaseException) {
                $items = [];
            }

            return ['items' => $items, ...Cards::forPage($supabase, $items)];
        });
    }

    /** One page of the gallery for these filters. The filters are the cache key. */
    public static function page(Filters $filters): array
    {
        $key = 'gallery:page:'.json_encode($filters->toArray());

        return HubCache::remember($key, [HubCache::ITEMS, HubCache::ASSETS], HubCache::HOURS, function () use ($filters) {
            if (! Supabase::configured()) {
                return ['items' => [], 'count' => 0, 'error' => null, ...self::NO_CARDS];
            }
            $supabase = Supabase::anon();
            $page = GalleryQuery::fetchPage(
                fn () => GalleryQuery::applyFilters(
                    GalleryQuery::applyOrder($supabase->from('item')->select(GalleryQuery::ITEM_SUMMARY_COLUMNS, 'exact'), $filters->sort)
                        ->range(($filters->page - 1) * GalleryQuery::PAGE_SIZE, $filters->page * GalleryQuery::PAGE_SIZE - 1),
                    $filters,
                )->getWithCount(),
                fn () => GalleryQuery::applyFilters(
                    $supabase->from('item')->select(GalleryQuery::ITEM_SUMMARY_COLUMNS, 'exact')->range(0, 0),
                    $filters,
                )->getWithCount(),
            );
            $items = $page['data'];

            // Titles are worked out per page, because twins only matter side by side.
            return ['items' => $items, 'count' => $page['count'], 'error' => $page['error'], ...Cards::forPage($supabase, $items)];
        });
    }

    /**
     * The chips above the gallery: every game and map a visible item carries,
     * from `public.item_facet`. Game chips use `game_key`, never an archive name.
     *
     * @return array{games: list<string>, maps: list<string>}
     */
    public static function facets(): array
    {
        return HubCache::remember('gallery:facets', [HubCache::ITEMS], HubCache::HOURS, function () {
            if (! Supabase::configured()) {
                return ['games' => [], 'maps' => []];
            }
            try {
                $rows = Supabase::anon()->from('item_facet')->select('facet,value')->order('value')->get();
            } catch (SupabaseException) {
                $rows = null;
            }

            return self::facetsFromRows($rows);
        });
    }

    /**
     * @param  list<array{facet: string, value: string|null}>|null  $rows
     * @return array{games: list<string>, maps: list<string>}
     */
    public static function facetsFromRows(?array $rows): array
    {
        $valuesOf = fn (string $facet) => $rows === null ? null : array_map(
            fn ($row) => $row['value'],
            array_values(array_filter($rows, fn ($row) => $row['facet'] === $facet)),
        );

        return ['games' => self::distinct($valuesOf('game')), 'maps' => self::distinct($valuesOf('map'))];
    }

    /**
     * Present, unique, sorted, and few enough to be a row of chips.
     *
     * @param  list<string|null>|null  $values
     * @return list<string>
     */
    public static function distinct(?array $values): array
    {
        $kept = array_values(array_unique(array_filter($values ?? [], fn ($v) => is_string($v) && $v !== '')));
        // JavaScript's default sort compares UTF-16 code units, which for
        // these names is the same order as comparing bytes.
        usort($kept, strcmp(...));

        return array_slice($kept, 0, 20);
    }

    /** An account's current name, for the heading of a gallery filtered to it. */
    public static function accountName(string $userId): ?string
    {
        return HubCache::remember('gallery:account:'.$userId, [HubCache::ITEMS], HubCache::HOURS, function () use ($userId) {
            return Supabase::configured() ? Author::displayName(Supabase::anon(), $userId) : null;
        });
    }
}
