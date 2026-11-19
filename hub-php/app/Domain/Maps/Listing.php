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
 * Filters are an array with `q`, `tag` (a list), `author`, `size`, `players`,
 * `play` (a list of play types), `featured`, `sort` and `page`.
 */
final class Listing
{
    /** Cards per page. */
    public const PAGE_SIZE = 60;

    /** Cards in each row above the first page, the Featured and Newest uploads sections. */
    public const SECTION_SIZE = 12;

    public const SUMMARY_COLUMNS = 'id,map_name,slug,display_name,map_version,width_elmos,height_elmos,tags,start_positions,author_keys,author_names,featured_at,mirror_urls';

    /** The three size bands, which are derived tags rather than a column. */
    public const SIZES = ['small', 'medium', 'large'];

    public const SORTS = ['name', 'size', 'players', 'added', 'uploaded', 'popular-month', 'popular-6-months'];

    /** The play types a map can be marked with, the values of `play_types` on the view. */
    public const PLAY_TYPES = ['1v1', 'team'];

    /** The query keys that make a listing filtered, `MAP_FILTER_KEYS` in `lib/filterKeys.ts`. */
    public const FILTER_KEYS = ['q', 'tag', 'author', 'size', 'players', 'play', 'featured', 'sort', 'page'];

    /** Alphabetical: the catalog is a reference list, mostly seeded in one pass. */
    private const DEFAULT_SORT = 'name';

    /** Which column each order reads, and which way. */
    private const SORT_COLUMNS = [
        'name' => ['map_name', true],
        'size' => ['longer_edge_elmos', false],
        'players' => ['start_positions', false],
        'added' => ['created_at', false],
        'uploaded' => ['uploaded_at', false],
        'popular-month' => ['downloads_30d', false],
        'popular-6-months' => ['downloads_182d', false],
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
     * @return array{q: string|null, tag: list<string>, author: string|null, size: string|null, players: int|null, play: list<string>, featured: bool, sort: string, page: int}
     */
    public static function parseFilters(array $params): array
    {
        $sort = self::one($params['sort'] ?? null);
        // Lower cased, because the derived tags are and a chip carries whatever case it was written in.
        $tags = array_values(array_unique(array_map(mb_strtolower(...), self::many($params['tag'] ?? null))));
        $sizeParam = self::one($params['size'] ?? null);
        $size = self::isSize($sizeParam) ? $sizeParam : null;
        // `tag=small` from a card's chip is the size filter, so the form shows it in the Size box.
        if ($size === null) {
            foreach ($tags as $index => $tag) {
                if (self::isSize($tag)) {
                    $size = $tag;
                    array_splice($tags, $index, 1);
                    break;
                }
            }
        }

        return [
            'q' => self::one($params['q'] ?? null),
            'tag' => $tags,
            'author' => self::one($params['author'] ?? null),
            'size' => $size,
            'players' => self::counted(self::one($params['players'] ?? null)),
            'play' => array_values(array_unique(array_filter(self::many($params['play'] ?? null), fn (string $type) => in_array($type, self::PLAY_TYPES, true)))),
            'featured' => self::one($params['featured'] ?? null) === '1',
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
            $query->contains('tags', $filters['tag']);
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
        if ($filters['featured']) {
            $query->not('featured_at', 'is', 'null');
        }
        // A map that plays either type matches, so ticking both widens the list.
        if ($filters['play']) {
            $query->overlaps('play_types', $filters['play']);
        }
        // Newest upload first only makes sense for maps that have an upload date.
        if ($filters['sort'] === 'uploaded') {
            $query->not('uploaded_at', 'is', 'null');
        }
        // websearch_to_tsquery takes what a person types and never throws on punctuation.
        if ($filters['q']) {
            $query->textSearch('search', $filters['q'], null, 'websearch');
        }

        return $query;
    }

    /**
     * The reader's order, then the name. The name makes every order total, so
     * paging never shows one map twice or skips one.
     */
    public static function applyOrder(Query $query, string $sort): Query
    {
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
        foreach (self::FILTER_KEYS as $key) {
            $value = $next[$key];
            if ($key === 'tag' || $key === 'play') {
                // A change may give one value or null for the list, as a link to a single tag does.
                foreach (is_array($value) ? $value : array_filter([$value], fn ($item) => $item !== null && $item !== '') as $item) {
                    $pairs[] = [$key, $item];
                }
            } elseif ($key === 'featured') {
                if ($value) {
                    $pairs[] = [$key, '1'];
                }
            } elseif ($key === 'sort') {
                if ($value !== self::DEFAULT_SORT) {
                    $pairs[] = [$key, $value];
                }
            } elseif ($key === 'page') {
                if ($value > 1 && array_key_exists('page', $change)) {
                    $pairs[] = [$key, (string) $value];
                }
            } elseif ($value !== null && $value !== '') {
                $pairs[] = [$key, (string) $value];
            }
        }
        $query = GalleryQuery::queryString($pairs);

        return $query !== '' ? '/maps?'.$query : '/maps';
    }

    /** Whether the reader has narrowed the catalog. The uploaded sort counts, because it hides maps never uploaded. Other sorts and the page do not. @param array<string, mixed> $filters */
    public static function isFiltered(array $filters): bool
    {
        return (bool) ($filters['q'] || $filters['tag'] || $filters['author'] || $filters['size'] || $filters['players'] !== null
            || $filters['play'] || $filters['featured'] || $filters['sort'] === 'uploaded');
    }

    /** Whether this is the unfiltered first page in the default order, the page that shows the sections. @param array<string, mixed> $filters */
    public static function isLanding(array $filters): bool
    {
        return ! self::isFiltered($filters) && $filters['page'] === 1 && $filters['sort'] === self::DEFAULT_SORT;
    }

    /**
     * The change that adds a tag to the current filters, for `filterHref`. A
     * tag already there is not added twice.
     *
     * @param  array<string, mixed>  $filters
     * @return array{tag: list<string>}
     */
    public static function withTag(array $filters, string $tag): array
    {
        return ['tag' => array_values(array_unique([...$filters['tag'], $tag]))];
    }

    /**
     * The quick filter chips. Each link turns its filter on, or off when it is
     * already on, and goes back to the first page.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{label: string, href: string, active: bool}>
     */
    public static function chips(array $filters): array
    {
        $chips = [
            ['Featured', $filters['featured'], ['featured' => ! $filters['featured']]],
            ['New uploads', $filters['sort'] === 'uploaded', ['sort' => $filters['sort'] === 'uploaded' ? self::DEFAULT_SORT : 'uploaded']],
        ];
        foreach (self::PLAY_TYPES as $type) {
            $on = in_array($type, $filters['play'], true);
            $play = $on ? array_values(array_diff($filters['play'], [$type])) : [...$filters['play'], $type];
            $chips[] = [$type === 'team' ? 'Team' : $type, $on, ['play' => $play]];
        }
        foreach (['Water' => 'water map', 'Tidal' => 'tidal', 'Windy' => 'windy'] as $label => $tag) {
            $on = in_array($tag, $filters['tag'], true);
            $chips[] = [$label, $on, $on ? ['tag' => array_values(array_diff($filters['tag'], [$tag]))] : self::withTag($filters, $tag)];
        }

        return array_map(fn (array $chip) => ['label' => $chip[0], 'href' => self::filterHref($filters, $chip[2]), 'active' => $chip[1]], $chips);
    }

    /**
     * A listing cache key that carries the columns read. A deploy that changes
     * them reads afresh rather than serving cards cached without the new ones,
     * because the cache outlives a release.
     */
    public static function cacheKey(string $what): string
    {
        return 'maps:'.substr(md5(self::SUMMARY_COLUMNS), 0, 8).':'.$what;
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
        try {
            // A failed read throws out of the loader, so an error answer is never cached.
            return HubCache::remember(self::cacheKey('page:'.json_encode($filters)), [HubCache::MAPS, HubCache::ASSETS], HubCache::WEEKS, function () use ($filters) {
                $supabase = Supabase::anon();
                $authorKey = self::resolveAuthorKey($supabase, $filters['author']);

                try {
                    $rule = Lookup::listingRule(Supabase::admin());
                } catch (SupabaseException) {
                    $rule = null;
                }
                if ($rule === null) {
                    throw new SupabaseException('The licence gate could not be read.');
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
                if ($page['error'] !== null) {
                    throw new SupabaseException($page['error']);
                }

                return ['maps' => $page['data'], 'count' => $page['count'], 'error' => null, 'pictures' => self::pictures($supabase, $page['data'])];
            });
        } catch (SupabaseException $e) {
            return ['maps' => [], 'count' => 0, 'error' => $e->getMessage(), 'pictures' => []];
        }
    }

    /**
     * One of the rows shown above the first page, `featured` or `uploaded`,
     * with the same licence gate as the grid. A failed read leaves the row
     * empty and is not cached, so the page still renders everything else and the
     * row comes back once the read works. That matters while the `uploaded_at`
     * column is not yet in production.
     *
     * @return array{maps: list<array<string, mixed>>, pictures: array<string, array<string, mixed>>}
     */
    public static function section(string $which): array
    {
        try {
            // A failed read throws out of the loader, so the empty answer below is never cached.
            return HubCache::remember(self::cacheKey('section:'.$which), [HubCache::MAPS, HubCache::ASSETS], HubCache::WEEKS, function () use ($which) {
                $supabase = Supabase::anon();
                $rule = Lookup::listingRule(Supabase::admin());
                if ($rule === null) {
                    throw new SupabaseException('The licence gate could not be read.');
                }
                $column = $which === 'featured' ? 'featured_at' : 'uploaded_at';
                $query = $supabase->from('map_browse')->select(self::SUMMARY_COLUMNS)->not($column, 'is', 'null');
                $maps = Lookup::applyListingRule($query, $rule)->order($column, false)->order('map_name', true)->limit(self::SECTION_SIZE)->get();

                return ['maps' => $maps, 'pictures' => self::pictures($supabase, $maps)];
            });
        } catch (SupabaseException) {
            return ['maps' => [], 'pictures' => []];
        }
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

    /** Every value, trimmed, with blanks dropped and repeats removed. @param string|list<string>|null $value @return list<string> */
    private static function many(string|array|null $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $kept = [];
        foreach ($values as $item) {
            $trimmed = $item === null ? '' : Js::trim($item);
            if ($trimmed !== '') {
                $kept[] = $trimmed;
            }
        }

        return array_values(array_unique($kept));
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
