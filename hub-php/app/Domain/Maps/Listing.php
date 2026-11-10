<?php

namespace App\Domain\Maps;

use App\Domain\Assets\AssetResolver;
use App\Domain\Gallery\GalleryQuery;
use App\Supabase\Client;
use App\Supabase\Query;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use App\Support\HubCache;

/**
 * How a reader narrows the catalog down, `lib/maps/query.ts` and the listing
 * half of `lib/maps/cached.ts`.
 *
 * Filters come out of the query string, turn into one query on
 * `public.map_browse`, and turn back into a link that keeps them. Every filter
 * is a column on that view, so nothing here computes. An unknown parameter is
 * dropped rather than refused, so a stale bookmark still works.
 *
 * Filters are an array with `q`, `tag`, `author`, `size`, `players`, `sort` and `page`.
 */
final class Listing
{
    /** Ten rows of five. A map card is scanned five to a row, so the page size follows the columns. */
    public const PAGE_SIZE = 50;

    public const SUMMARY_COLUMNS = 'id,map_name,slug,display_name,width_elmos,height_elmos,tags,start_positions,author_keys,author_names,featured_at';

    /** The three size bands, which are derived tags rather than a column. */
    public const SIZES = ['small', 'medium', 'large'];

    public const SORTS = ['name', 'size', 'players', 'added'];

    /** The query keys that make a listing filtered, `MAP_FILTER_KEYS` in `lib/filterKeys.ts`. */
    public const FILTER_KEYS = ['q', 'tag', 'author', 'size', 'players', 'sort', 'page'];

    /** Alphabetical: the catalog is a reference list, mostly seeded in one pass. */
    private const DEFAULT_SORT = 'name';

    /** Which column each order reads, and which way. */
    private const SORT_COLUMNS = [
        'name' => ['map_name', true],
        'size' => ['longer_edge_elmos', false],
        'players' => ['start_positions', false],
        'added' => ['created_at', false],
    ];

    public static function isSize(?string $value): bool
    {
        return $value !== null && in_array($value, self::SIZES, true);
    }

    /**
     * Read filters out of the query string. A value this cannot make sense of
     * becomes null and is not applied, so a shared link never breaks.
     *
     * @param  array<string, string|list<string>>  $params
     * @return array{q: string|null, tag: string|null, author: string|null, size: string|null, players: int|null, sort: string, page: int}
     */
    public static function parseFilters(array $params): array
    {
        $sort = self::one($params['sort'] ?? null);
        // Lower cased, because the derived tags are and a chip carries whatever case it was written in.
        $tag = self::one($params['tag'] ?? null);
        $tag = $tag === null ? null : mb_strtolower($tag);
        $sizeParam = self::one($params['size'] ?? null);
        $size = self::isSize($sizeParam) ? $sizeParam : null;
        // `tag=small` from a card's chip is the size filter, so the form shows it in the Size box.
        if ($size === null && self::isSize($tag)) {
            $size = $tag;
            $tag = null;
        }

        return [
            'q' => self::one($params['q'] ?? null),
            'tag' => $tag,
            'author' => self::one($params['author'] ?? null),
            'size' => $size,
            'players' => self::counted(self::one($params['players'] ?? null)),
            'sort' => $sort !== null && in_array($sort, self::SORTS, true) ? $sort : self::DEFAULT_SORT,
            'page' => GalleryQuery::parsePage(self::one($params['page'] ?? null), self::PAGE_SIZE) ?? 1,
        ];
    }

    /**
     * The key an author filter matches on, worked out by the database: the
     * credit folded the way ingest folds it, then any merge a maintainer has
     * recorded. One copy of that rule, in Postgres. A credit that folds to
     * nothing gives an empty key, which matches no maps.
     */
    public static function resolveAuthorKey(Client $supabase, ?string $author): ?string
    {
        if ($author === null) {
            return null;
        }
        try {
            $folded = $supabase->rpc('author_key', ['credit' => $author]);
        } catch (SupabaseException) {
            $folded = null;
        }
        $key = is_string($folded) ? $folded : '';
        try {
            $resolved = $supabase->rpc('resolved_author_key', ['credit_key' => $key]);
        } catch (SupabaseException) {
            $resolved = null;
        }

        return is_string($resolved) ? $resolved : $key;
    }

    /** @param array<string, mixed> $filters */
    public static function applyFilters(Query $query, array $filters, ?string $authorKey): Query
    {
        // A size band is a derived tag, so `size=small` and `tag=small` are one question.
        if ($filters['tag']) {
            $query->contains('tags', [$filters['tag']]);
        }
        if ($filters['size']) {
            $query->contains('tags', [$filters['size']]);
        }
        if ($authorKey !== null) {
            $query->contains('author_keys', [$authorKey]);
        }
        // A minimum: somebody after an eight player map will take a twelve.
        if ($filters['players'] !== null) {
            $query->gte('start_positions', $filters['players']);
        }
        // websearch_to_tsquery takes what a person types and never throws on punctuation.
        if ($filters['q']) {
            $query->textSearch('search', $filters['q'], null, 'websearch');
        }

        return $query;
    }

    /**
     * Featured first, then the reader's order, then the name. The name makes
     * every order total, so paging never shows one map twice or skips one.
     */
    public static function applyOrder(Query $query, string $sort): Query
    {
        $query->order('featured_at', false, false);
        [$column, $ascending] = self::SORT_COLUMNS[$sort];
        $query->order($column, $ascending);
        if ($column !== 'map_name') {
            $query->order('map_name', true);
        }

        return $query;
    }

    /**
     * A link that keeps the current filters and changes some. A filter change
     * goes back to the first page.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $change
     */
    public static function filterHref(array $current, array $change): string
    {
        $next = array_merge($current, $change);
        $pairs = [];
        foreach (['q', 'tag', 'author', 'size'] as $key) {
            if ($next[$key] !== null && $next[$key] !== '') {
                $pairs[] = [$key, (string) $next[$key]];
            }
        }
        if ($next['players'] !== null) {
            $pairs[] = ['players', (string) $next['players']];
        }
        if ($next['sort'] !== self::DEFAULT_SORT) {
            $pairs[] = ['sort', $next['sort']];
        }
        if ($next['page'] > 1 && array_key_exists('page', $change)) {
            $pairs[] = ['page', (string) $next['page']];
        }
        $query = GalleryQuery::queryString($pairs);

        return $query !== '' ? '/maps?'.$query : '/maps';
    }

    /** Whether the reader has narrowed the catalog. Sort and page are not narrowing. @param array<string, mixed> $filters */
    public static function isFiltered(array $filters): bool
    {
        return (bool) ($filters['q'] || $filters['tag'] || $filters['author'] || $filters['size'] || $filters['players'] !== null);
    }

    /**
     * One page of the catalog with a minimap per card, held between requests.
     * The licence gate the map page applies is applied here too, so no card
     * links to a page that says not found. An unreadable gate lists nothing.
     *
     * @param  array<string, mixed>  $filters
     * @return array{maps: list<array<string, mixed>>, count: int, error: string|null, pictures: array<string, array<string, mixed>>}
     */
    public static function page(array $filters): array
    {
        return HubCache::remember('maps:page:'.json_encode($filters), [HubCache::MAPS, HubCache::ASSETS], HubCache::WEEKS, function () use ($filters) {
            $supabase = Supabase::anon();
            $authorKey = self::resolveAuthorKey($supabase, $filters['author']);

            try {
                $rule = Lookup::listingRule(Supabase::admin());
            } catch (SupabaseException) {
                $rule = null;
            }
            if ($rule === null) {
                return ['maps' => [], 'count' => 0, 'error' => 'The licence gate could not be read.', 'pictures' => []];
            }

            $listing = fn () => self::applyOrder(
                Lookup::applyListingRule(
                    self::applyFilters($supabase->from('map_browse')->select(self::SUMMARY_COLUMNS, 'exact'), $filters, $authorKey),
                    $rule,
                ),
                $filters['sort'],
            );

            $page = GalleryQuery::fetchPage(
                fn () => $listing()->range(($filters['page'] - 1) * self::PAGE_SIZE, $filters['page'] * self::PAGE_SIZE - 1)->getWithCount(),
                fn () => $listing()->range(0, 0)->getWithCount(),
            );

            return ['maps' => $page['data'], 'count' => $page['count'], 'error' => $page['error'], 'pictures' => self::pictures($supabase, $page['data'])];
        });
    }

    /**
     * The minimap for every map on the page, in one lookup. Every map gets an
     * answer, a drawing at the catalog's own proportions when nothing is held.
     *
     * @param  list<array<string, mixed>>  $maps
     * @return array<string, array<string, mixed>>
     */
    public static function pictures(Client $supabase, array $maps): array
    {
        $identities = array_map(fn (array $map) => AssetResolver::map($map['map_name']), $maps);
        $held = AssetResolver::fetchHeldAssets($supabase, $identities);
        $pictures = [];
        foreach ($maps as $index => $map) {
            $pictures[$map['map_name']] = AssetResolver::resolveAsset($identities[$index], $held, Labels::footprint($map['width_elmos'], $map['height_elmos']));
        }

        return $pictures;
    }

    /** The first value, trimmed, with an empty one treated as absent. @param string|list<string>|null $value */
    private static function one(string|array|null $value): ?string
    {
        $first = is_array($value) ? ($value[0] ?? null) : $value;
        if ($first === null) {
            return null;
        }
        $trimmed = Js::trim($first);

        return $trimmed !== '' ? $trimmed : null;
    }

    /** `Number.parseInt` of at least one, or null. */
    private static function counted(?string $value): ?int
    {
        if ($value === null || ! preg_match('/^\s*([+-]?\d+)/', $value, $m)) {
            return null;
        }
        $parsed = (float) $m[1];

        return $parsed > 0 && $parsed <= PHP_INT_MAX ? (int) $parsed : null;
    }
}
