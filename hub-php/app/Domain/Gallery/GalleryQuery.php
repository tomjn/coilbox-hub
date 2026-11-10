<?php

namespace App\Domain\Gallery;

use App\Domain\Container\Container;
use App\Supabase\Query;
use App\Supabase\SupabaseException;
use Closure;

/**
 * How a gallery listing reads the database, the PHP side of the Next app's
 * `lib/gallery/query.ts`. The `/gallery` page and `GET /api/v1/items` both
 * build their query through {@see applyFilters()} and {@see applyOrder()} so
 * the two never filter or sort differently.
 */
final class GalleryQuery
{
    /** How many fit on a page. */
    public const PAGE_SIZE = 24;

    /** A break in a pager's run of numbers, drawn as an ellipsis. */
    public const PAGE_GAP = 'gap';

    /** How many pages either side of the current one a pager always offers. */
    private const AROUND = 2;

    /** JavaScript's `Number.MAX_SAFE_INTEGER`, the bound the Next app pages within. */
    private const MAX_SAFE_INTEGER = 9007199254740991;

    /** The container is left out on purpose: it is the largest column and no card reads it. */
    public const ITEM_SUMMARY_COLUMNS = 'id,kind,mode,title,description,game_name,game_key,map_name,tags,author_name,author_id,created_at,featured_at';

    public const SORT_ORDERS = ['newest', 'title'];

    /** PostgREST's answer to a range whose offset is past the last row. */
    private const OFFSET_PAST_END = 'PGRST103';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * The highest page whose first row number is still a safe integer in
     * JavaScript, so a page number the Next app refused is refused here too.
     */
    public static function maxPage(int $pageSize): int
    {
        return intdiv(self::MAX_SAFE_INTEGER, $pageSize);
    }

    /** A page number from a query string, or null when it is not one: not a number, below one, or past {@see maxPage()}. */
    public static function parsePage(?string $value, int $pageSize): ?int
    {
        $page = self::parseInt($value);

        return $page !== null && $page > 0 && $page <= self::maxPage($pageSize) ? (int) $page : null;
    }

    /** A positive number that {@see parsePage()} refuses only for being too large. */
    public static function isPageTooLarge(?string $value, int $pageSize): bool
    {
        $page = self::parseInt($value);

        return $page !== null && $page > 0 && self::parsePage($value, $pageSize) === null;
    }

    /**
     * The pages a numbered pager offers: the first, the last, and a short run
     * around the current one, with a gap only where pages are really missing.
     *
     * @return list<int|string>
     */
    public static function pageNumbers(int $current, int $lastPage): array
    {
        $last = max(1, $lastPage);
        $page = min($last, max(1, $current));

        $wanted = [1 => true, $last => true];
        for ($near = $page - self::AROUND; $near <= $page + self::AROUND; $near++) {
            if ($near >= 1 && $near <= $last) {
                $wanted[$near] = true;
            }
        }
        $numbers = array_keys($wanted);
        sort($numbers);

        $steps = [];
        $previous = 0;
        foreach ($numbers as $number) {
            if ($previous && $number - $previous > 1) {
                $steps[] = self::PAGE_GAP;
            }
            $steps[] = $number;
            $previous = $number;
        }

        return $steps;
    }

    /**
     * Follow a ranged query to the end rather than trusting one request to
     * return everything. `max_rows` can cap a single request below what was
     * asked for, silently, so the loop runs until the reported count is met.
     *
     * @param  Closure(int, int): array{rows: list<mixed>, count: int|null}  $fetchPage  Throws {@see SupabaseException} on failure.
     * @return array{data: list<mixed>, error: string|null}
     */
    public static function fetchAllPages(Closure $fetchPage, int $pageSize): array
    {
        $all = [];
        $total = null;

        while ($total === null || count($all) < $total) {
            try {
                $page = $fetchPage(count($all), count($all) + $pageSize - 1);
            } catch (SupabaseException $e) {
                return ['data' => $all, 'error' => $e->getMessage()];
            }
            $rows = $page['rows'];
            $total ??= $page['count'] ?? count($rows);
            if ($rows === []) {
                break;
            }
            array_push($all, ...$rows);
        }

        return ['data' => $all, 'error' => null];
    }

    /**
     * Run a ranged, counted query and turn "the offset is past the last row"
     * into the empty page it should have been. Paging one step past the end is
     * routine, since the last page can be gone by the time somebody clicks
     * next. Only then does `$countQuery` run, to learn the real total.
     *
     * @param  Closure(): array{rows: list<mixed>, count: int|null}  $runQuery
     * @param  Closure(): array{rows: list<mixed>, count: int|null}  $countQuery
     * @return array{data: list<mixed>, count: int, error: string|null}
     */
    public static function fetchPage(Closure $runQuery, Closure $countQuery): array
    {
        try {
            $result = $runQuery();

            return ['data' => $result['rows'], 'count' => $result['count'] ?? 0, 'error' => null];
        } catch (SupabaseException $e) {
            if ($e->errorCode !== self::OFFSET_PAST_END) {
                return ['data' => [], 'count' => 0, 'error' => $e->getMessage()];
            }
        }

        try {
            $total = $countQuery();
        } catch (SupabaseException $e) {
            return ['data' => [], 'count' => 0, 'error' => $e->getMessage()];
        }

        return ['data' => [], 'count' => $total['count'] ?? 0, 'error' => null];
    }

    /** @return array{column: string, ascending: bool} */
    public static function orderBy(string $sort): array
    {
        return $sort === 'title'
            ? ['column' => 'title', 'ascending' => true]
            : ['column' => 'created_at', 'ascending' => false];
    }

    /**
     * Featured first, then the reader's order. `nullsFirst: false` is the
     * whole rule: Postgres puts nulls first on a descending column, which would
     * lift every unfeatured item above the featured ones.
     */
    public static function applyOrder(Query $query, string $sort): Query
    {
        $order = self::orderBy($sort);

        return $query
            ->order('featured_at', ascending: false, nullsFirst: false)
            ->order($order['column'], ascending: $order['ascending']);
    }

    /**
     * Read filters out of the query string. Forgiving on purpose: anything
     * unrecognised is dropped, so a typo in a shared link shows an unfiltered
     * gallery rather than an error.
     *
     * @param  array<string, string|list<string>|null>  $params
     */
    public static function parseFilters(array $params): Filters
    {
        $sort = self::one($params['sort'] ?? null);

        return new Filters(
            kind: array_values(array_filter(self::many($params['kind'] ?? null), fn ($k) => in_array($k, Container::GALLERY_KINDS, true))),
            game: self::one($params['game'] ?? null),
            map: self::one($params['map'] ?? null),
            tag: array_map(mb_strtolower(...), self::many($params['tag'] ?? null)),
            author: self::many($params['author'] ?? null),
            // Anything that is not a uuid is dropped. Postgres would reject it
            // for a uuid column and fail the whole page.
            account: array_values(array_filter(
                array_map(strtolower(...), self::many($params['account'] ?? null)),
                fn ($id) => preg_match(self::UUID, $id) === 1,
            )),
            q: self::one($params['q'] ?? null),
            sort: $sort !== null && in_array($sort, self::SORT_ORDERS, true) ? $sort : 'newest',
            page: self::parsePage(self::one($params['page'] ?? null), self::PAGE_SIZE) ?? 1,
        );
    }

    /**
     * The same filter chain everywhere a listing is built. `kind`, `author` and
     * `account` use `in` even for one value, and `tags` uses `overlaps`, since
     * more than one value always means "any of these".
     */
    public static function applyFilters(Query $query, Filters $filters): Query
    {
        if ($filters->kind !== []) {
            $query->in('kind', $filters->kind);
        }
        // The grouping key, not the display name (#50). A display name can be
        // an archive name no other row shares.
        if ($filters->game !== null) {
            $query->eq('game_key', $filters->game);
        }
        if ($filters->map !== null) {
            $query->eq('map_name', $filters->map);
        }
        if ($filters->tag !== []) {
            $query->overlaps('tags', $filters->tag);
        }
        if ($filters->author !== []) {
            $query->in('author_name', $filters->author);
        }
        if ($filters->account !== []) {
            $query->in('author_id', $filters->account);
        }
        // websearch_to_tsquery takes what a person types and never throws on punctuation.
        if ($filters->q !== null) {
            $query->textSearch('search', $filters->q, type: 'websearch');
        }

        return $query;
    }

    /**
     * A gallery link that keeps the current filters and changes some. A
     * filter change goes back to the first page, a page change keeps the rest.
     *
     * @param  array<string, mixed>  $change
     */
    public static function filterHref(Filters $current, array $change): string
    {
        $next = $current->with($change);
        $pairs = [];
        foreach ($next->kind as $kind) {
            $pairs[] = ['kind', $kind];
        }
        if ($next->game !== null && $next->game !== '') {
            $pairs[] = ['game', $next->game];
        }
        if ($next->map !== null && $next->map !== '') {
            $pairs[] = ['map', $next->map];
        }
        foreach ($next->tag as $tag) {
            $pairs[] = ['tag', $tag];
        }
        foreach ($next->author as $author) {
            $pairs[] = ['author', $author];
        }
        foreach ($next->account as $account) {
            $pairs[] = ['account', $account];
        }
        if ($next->q !== null && $next->q !== '') {
            $pairs[] = ['q', $next->q];
        }
        if ($next->sort !== 'newest') {
            $pairs[] = ['sort', $next->sort];
        }
        if ($next->page > 1 && array_key_exists('page', $change)) {
            $pairs[] = ['page', (string) $next->page];
        }

        $query = self::queryString($pairs);

        return $query !== '' ? '/gallery?'.$query : '/gallery';
    }

    /**
     * `URLSearchParams.toString()`: form encoding, spaces as `+`, and `*`
     * left alone where PHP's `urlencode` would escape it.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    public static function queryString(array $pairs): string
    {
        $encode = fn (string $s) => str_replace('%2A', '*', urlencode($s));

        return implode('&', array_map(fn ($pair) => $encode($pair[0]).'='.$encode($pair[1]), $pairs));
    }

    /** JavaScript's `Number.parseInt(value, 10)` as far as a page number needs: the leading integer, or null. */
    private static function parseInt(?string $value): ?float
    {
        if ($value === null || ! preg_match('/^\s*([+-]?)(\d+)/u', $value, $m)) {
            return null;
        }

        return (float) ($m[1].$m[2]);
    }

    /** @param string|list<string>|null $value */
    private static function one(string|array|null $value): ?string
    {
        $first = is_array($value) ? ($value[0] ?? null) : $value;
        if ($first === null) {
            return null;
        }
        $trimmed = Container::trim($first);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Every value given for a repeatable filter, trimmed, without blanks, each once.
     *
     * @param  string|list<string>|null  $value
     * @return list<string>
     */
    private static function many(string|array|null $value): array
    {
        $values = is_array($value) ? $value : ($value === null ? [] : [$value]);
        $seen = [];
        foreach ($values as $raw) {
            $trimmed = Container::trim($raw);
            if ($trimmed !== '' && ! in_array($trimmed, $seen, true)) {
                $seen[] = $trimmed;
            }
        }

        return $seen;
    }
}
