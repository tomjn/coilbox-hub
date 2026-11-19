<?php

namespace App\Supabase\Local;

use Collator;
use Illuminate\Support\Facades\DB;

/**
 * The map views, worked out in PHP from the local tables as production's
 * schema defines them on 19 November 2026: `map_listing`, `map_browse`,
 * `map_pack_entry`, `author_spelling`, `author_display_name` and
 * `author_map_count`. The catalog is a few hundred maps, so each read works
 * out every row, then filters, orders and pages them here as PostgREST would.
 *
 * Two `map_browse` columns come from the hub mirrors, which are still in
 * Supabase (#600): `uploaded_at` and `mirror_urls`. {@see MapArchives} reads
 * them there in one request, and only when the read names them. `search` stands for the
 * tsvector and can only be searched, with {@see TextSearch}.
 */
final class MapViews
{
    /** `constant.solar_collector_energy` in `map_listing`. */
    private const SOLAR_COLLECTOR_ENERGY = 20;

    /** `constant.elmos_per_square` in `map_listing`. */
    private const ELMOS_PER_SQUARE = 512;

    /** Columns read from Supabase, worked out only when a read names them. */
    private const MIRRORED = ['uploaded_at', 'mirror_urls'];

    /** @return array{rows: list<array<string, mixed>>, count: int|null} */
    public static function read(Caller $caller, string $view, Request $request, bool $head): array
    {
        $types = Tables::columns($view);
        $select = Select::parse($request->select);
        if ($select->embeds() !== []) {
            throw new LocalException($view.' has no relationships to embed', 400, 'PGRST200');
        }
        $wanted = [];
        foreach ($select->items as $item) {
            if ($item->kind === 'star') {
                array_push($wanted, ...array_diff(array_keys($types), ['search']));
            } elseif (! isset($types[$item->name])) {
                throw new LocalException('column '.$view.'.'.$item->name.' does not exist', 400, '42703');
            } else {
                $wanted[] = $item->name;
            }
        }
        $filters = $request->filters([]);
        $order = $request->order([]);
        $used = [...$wanted, ...array_column($order, 0), ...self::filterColumns($filters)];

        $rows = array_values(array_filter(
            self::rows($view, $used),
            fn (array $row) => self::passesAll($row, $filters, $types, $view),
        ));
        $count = $request->count !== null ? count($rows) : null;
        $offset = $request->offset([]);
        if ($count !== null && $offset > $count) {
            throw new LocalException('Requested range not satisfiable', 416, 'PGRST103', 'An offset of '.$offset.' was requested, but there are only '.$count.' rows.');
        }
        if ($head) {
            return ['rows' => [], 'count' => $count];
        }
        self::sort($rows, $order, $types, $view);
        $rows = array_slice($rows, $offset, min($request->limit([]) ?? Rest::MAX_ROWS, Rest::MAX_ROWS));

        $out = [];
        foreach ($rows as $row) {
            $shaped = [];
            foreach ($select->items as $item) {
                $names = $item->kind === 'star' ? array_diff(array_keys($types), ['search']) : [$item->name];
                foreach ($names as $name) {
                    if ($name === 'search') {
                        throw new LocalException('The local store cannot return '.$view.'.search', 400, 'PGRST100');
                    }
                    $value = $types[$name] === 'json' ? $row[$name] : Values::read($types[$name], $row[$name]);
                    $shaped[$item->alias ?? $name] = $item->path === [] ? $value : $item->at($value);
                }
            }
            $out[] = $shaped;
        }

        return ['rows' => $out, 'count' => $count];
    }

    /**
     * Every row of a view, in stored form: timestamps as stored, arrays as
     * PHP lists.
     *
     * @param  list<string>  $used  the columns the read names
     * @return list<array<string, mixed>>
     */
    public static function rows(string $view, array $used): array
    {
        return match ($view) {
            'map_listing' => array_map(fn (array $map) => self::listingRow($map), self::maps()),
            'map_browse' => self::browse($used),
            'map_pack_entry' => self::packEntries(),
            'author_spelling' => self::spellings(),
            'author_display_name' => array_map(
                fn (string $key, string $name) => ['key' => $key, 'name' => $name],
                array_keys($names = self::displayNames()), $names,
            ),
            'author_map_count' => self::mapCounts(),
            default => throw new LocalException('relation "public.'.$view.'" does not exist', 404, '42P01'),
        };
    }

    /**
     * `map_listing`'s tags for one stored map row: the derived tags and the
     * curated ones, without blanks or repeats, in order.
     *
     * @param  array<string, mixed>  $map
     * @return list<string>
     */
    public static function tags(array $map): array
    {
        $f = fn (string $column) => $map[$column] === null ? null : self::real((float) $map[$column]);
        $voidWater = $map['void_water'] !== null && (bool) $map['void_water'];
        $minWind = $f('min_wind');
        $maxWind = $f('max_wind');
        $wind = $minWind === null || $maxWind === null ? null : self::real($minWind + $maxWind) / 2;
        $tidal = $f('tidal_strength');
        $longer = max((int) $map['width_elmos'], (int) $map['height_elmos']);
        $derived = [
            $voidWater ? 'void map' : null,
            ! $voidWater && $f('water_coverage') !== null && $f('water_coverage') > self::real(0.20) ? 'water map' : null,
            $wind !== null && $wind > self::SOLAR_COLLECTOR_ENERGY ? 'windy' : null,
            ! $voidWater && $f('world_height_min') < 0 && $tidal !== null && $tidal > self::SOLAR_COLLECTOR_ENERGY
                && $wind !== null && $tidal > $wind ? 'tidal' : null,
            match (true) {
                $longer <= 12 * self::ELMOS_PER_SQUARE => 'small',
                $longer <= 20 * self::ELMOS_PER_SQUARE => 'medium',
                default => 'large',
            },
        ];
        $curated = json_decode((string) $map['curated_tags'], true) ?: [];
        $tags = array_values(array_unique(array_filter([...$derived, ...$curated], fn ($tag) => $tag !== null)));
        usort($tags, self::compareText(...));

        return $tags;
    }

    /**
     * `author_display_name`: each resolved key's chosen name, or else its
     * most credited spelling.
     *
     * @return array<string, string>
     */
    public static function displayNames(): array
    {
        $chosen = DB::table('author_name')->pluck('name', 'key')->all();
        $best = [];
        foreach (self::spellings() as $spelling) {
            $held = $best[$spelling['key']] ?? null;
            if ($held === null || $spelling['credits'] > $held['credits']
                || ($spelling['credits'] === $held['credits'] && self::compareText($spelling['raw'], $held['raw']) < 0)) {
                $best[$spelling['key']] = $spelling;
            }
        }
        $names = [];
        foreach ($best as $key => $spelling) {
            $names[(string) $key] = (string) ($chosen[$key] ?? $spelling['raw']);
        }

        return $names;
    }

    /** `public.resolved_author_key` over every alias, read once. @return array<string, string> */
    public static function aliases(): array
    {
        return array_map('strval', DB::table('author_alias')->pluck('to_key', 'from_key')->all());
    }

    /** Text in the order Supabase's `en_US.UTF-8` collation gives. Only equal text compares equal. */
    public static function compareText(string $a, string $b): int
    {
        static $collator = null;
        $collator ??= new Collator('en_US');

        return $collator->compare($a, $b) ?: strcmp($a, $b);
    }

    /** @return list<array<string, mixed>> */
    private static function maps(): array
    {
        return array_map(fn ($row) => (array) $row, DB::table('map')->get()->all());
    }

    /** @param array<string, mixed> $map @return array<string, mixed> */
    private static function listingRow(array $map): array
    {
        return [
            'id' => $map['id'], 'map_name' => $map['map_name'], 'slug' => $map['slug'], 'display_name' => $map['display_name'],
            'width_elmos' => $map['width_elmos'], 'height_elmos' => $map['height_elmos'], 'tags' => self::tags($map),
        ];
    }

    /**
     * @param  list<string>  $used
     * @return list<array<string, mixed>>
     */
    private static function browse(array $used): array
    {
        $maps = self::maps();
        $aliases = self::aliases();
        $names = self::displayNames();
        $credits = [];
        foreach (DB::table('map_author')->orderBy('credit_index')->get() as $author) {
            $key = $aliases[$author->key] ?? (string) $author->key;
            // One entry per person per map, at their first credit.
            $credits[$author->map_id][$key] ??= (int) $author->credit_index;
        }
        $starts = DB::table('map_point')->where('kind', 'start')->groupBy('map_id')
            ->selectRaw('map_id, count(*) as n')->pluck('n', 'map_id')->all();
        $archives = array_intersect(self::MIRRORED, $used) !== [] ? MapArchives::listed(null) : [];
        $searched = in_array('search', $used, true);

        $rows = [];
        foreach ($maps as $map) {
            $listing = self::listingRow($map);
            $keys = array_keys($credits[$map['id']] ?? []);
            usort($keys, fn ($a, $b) => $credits[$map['id']][$a] <=> $credits[$map['id']][$b]);
            $authorNames = array_map(fn ($key) => $names[$key] ?? '', $keys);
            $positions = (int) ($starts[$map['id']] ?? 0);
            $oneVsOne = $positions === 2 || in_array('1v1', array_map(mb_strtolower(...), $listing['tags']), true);
            $archive = $archives[$map['map_name']] ?? null;
            $rows[] = $listing + [
                'created_at' => $map['created_at'],
                'longer_edge_elmos' => max((int) $map['width_elmos'], (int) $map['height_elmos']),
                'start_positions' => $positions,
                'author_keys' => array_map('strval', $keys),
                'author_names' => $authorNames,
                'search' => $searched
                    ? TextSearch::vector([$map['map_name'], $map['display_name'], $map['description'], implode(' ', $authorNames)])
                    : null,
                'featured_at' => $map['featured_at'],
                'uploaded_at' => $archive['uploaded_at'] ?? null,
                'play_types' => array_values(array_filter([$oneVsOne ? '1v1' : null, $positions >= 4 ? 'team' : null])),
                'mirror_urls' => $archive['urls'] ?? [],
                'map_version' => $map['map_version'],
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private static function packEntries(): array
    {
        return array_map(fn ($row) => (array) $row, DB::table('map_pack_map as entry')
            ->leftJoin('map', 'map.map_name', '=', 'entry.map_name')
            ->select('entry.pack_id', 'entry.map_name', 'entry.position', 'map.slug', 'map.display_name', 'map.archive_filename')
            ->get()->all());
    }

    /** `author_spelling`: each resolved key's spellings and how often each is credited. @return list<array{key: string, raw: string, credits: int}> */
    private static function spellings(): array
    {
        $aliases = self::aliases();
        $counts = [];
        foreach (DB::table('map_author')->select('key', 'raw')->get() as $author) {
            $key = $aliases[$author->key] ?? (string) $author->key;
            $counts[$key][(string) $author->raw] = ($counts[$key][(string) $author->raw] ?? 0) + 1;
        }
        $rows = [];
        foreach ($counts as $key => $raws) {
            foreach ($raws as $raw => $credits) {
                $rows[] = ['key' => (string) $key, 'raw' => (string) $raw, 'credits' => $credits];
            }
        }

        return $rows;
    }

    /** @return list<array{key: string, name: string, maps: int}> */
    private static function mapCounts(): array
    {
        $aliases = self::aliases();
        $maps = [];
        foreach (DB::table('map_author')->select('key', 'map_id')->get() as $author) {
            $maps[$aliases[$author->key] ?? (string) $author->key][(string) $author->map_id] = true;
        }
        $rows = [];
        foreach (self::displayNames() as $key => $name) {
            $rows[] = ['key' => (string) $key, 'name' => $name, 'maps' => count($maps[$key] ?? [])];
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $filters @return list<string> */
    private static function filterColumns(array $filters): array
    {
        $columns = [];
        foreach ($filters as $filter) {
            array_push($columns, ...(isset($filter['logic']) ? self::filterColumns($filter['items']) : [$filter['column']]));
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $filters
     * @param  array<string, string>  $types
     */
    private static function passesAll(array $row, array $filters, array $types, string $view): bool
    {
        foreach ($filters as $filter) {
            if (self::test($row, $filter, $types, $view) !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * One filter on one row, with SQL's three values: null when the answer
     * is unknown, which keeps no row and stays null under `not`.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $filter
     * @param  array<string, string>  $types
     */
    private static function test(array $row, array $filter, array $types, string $view): ?bool
    {
        if (isset($filter['logic'])) {
            $answers = array_map(fn (array $item) => self::test($row, $item, $types, $view), $filter['items']);
            $answer = $filter['logic'] === 'or'
                ? (in_array(true, $answers, true) ? true : (in_array(null, $answers, true) ? null : false))
                : (in_array(false, $answers, true) ? false : (in_array(null, $answers, true) ? null : true));
        } else {
            $answer = self::condition($row, $filter, $types, $view);
        }

        return $answer === null || ! $filter['negated'] ? $answer : ! $answer;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $filter
     * @param  array<string, string>  $types
     */
    private static function condition(array $row, array $filter, array $types, string $view): ?bool
    {
        $column = (string) $filter['column'];
        if (! isset($types[$column])) {
            throw new LocalException('column '.$view.'.'.$column.' does not exist', 400, '42703');
        }
        $type = $types[$column];
        $held = $row[$column];
        $value = (string) $filter['value'];
        $operator = (string) $filter['operator'];
        if ($column === 'search' && ! in_array($operator, ['fts', 'plfts', 'wfts'], true)) {
            throw new LocalException('The local store can only search '.$view.'.search', 400, 'PGRST100');
        }

        switch ($operator) {
            case 'is':
                return match (strtolower($value)) {
                    'null' => $held === null,
                    'true' => $held === null ? false : (bool) $held === true,
                    'false' => $held === null ? false : (bool) $held === false,
                    default => throw new LocalException('"failed to parse filter (is.'.$value.')"', 400, 'PGRST100'),
                };
            case 'in':
                if ($held === null) {
                    return null;
                }
                if (! str_starts_with($value, '(') || ! str_ends_with($value, ')')) {
                    throw new LocalException('"failed to parse filter (in.'.$value.')"', 400, 'PGRST100');
                }
                foreach (Select::split(substr($value, 1, -1)) as $item) {
                    if (self::compare($type, $held, Filter::unquote($item)) === 0) {
                        return true;
                    }
                }

                return false;
            case 'cs':
            case 'ov':
                if ($held === null) {
                    return null;
                }
                $given = self::arrayLiteral($value);
                $found = array_intersect($given, $held);

                return $operator === 'cs' ? count(array_unique($found)) === count(array_unique($given)) : $found !== [];
            case 'fts':
            case 'plfts':
            case 'wfts':
                return $held === null ? null : TextSearch::matches($held, $value);
            case 'like':
            case 'ilike':
                if ($held === null) {
                    return null;
                }
                $pattern = '/^'.strtr(preg_quote(str_replace('*', '%', $value), '/'), ['%' => '.*', '_' => '.']).'$/s'
                    .($operator === 'ilike' ? 'iu' : 'u');

                return preg_match($pattern, (string) $held) === 1;
            default:
                if ($held === null) {
                    return null;
                }
                $compared = self::compare($type, $held, $value);

                return match ($operator) {
                    'eq' => $compared === 0,
                    'neq' => $compared !== 0,
                    'gt' => $compared > 0,
                    'gte' => $compared >= 0,
                    'lt' => $compared < 0,
                    'lte' => $compared <= 0,
                    default => throw new LocalException('The local store does not support the "'.$operator.'" operator', 400, 'PGRST100'),
                };
        }
    }

    /** A stored value against a filter's text, as Postgres compares the column's type. */
    private static function compare(string $type, mixed $held, string $value): int
    {
        return match ($type) {
            'int' => (int) $held <=> (int) $value,
            'float' => (float) $held <=> (float) $value,
            'bool' => (bool) $held <=> in_array(strtolower($value), ['true', 't', '1'], true),
            'ts' => strcmp((string) $held, Values::stored($value)),
            'text' => self::compareText((string) $held, $value),
            default => strcmp((string) $held, $value),
        };
    }

    /** A Postgres array literal, `{a,"b c"}`, as a list. @return list<string> */
    public static function arrayLiteral(string $value): array
    {
        if (! str_starts_with($value, '{') || ! str_ends_with($value, '}')) {
            throw new LocalException('malformed array literal: "'.$value.'"', 400, '22P02');
        }
        $inner = substr($value, 1, -1);

        return $inner === '' ? [] : array_map(Filter::unquote(...), Select::split($inner));
    }

    /**
     * PostgREST's order: each column in turn, nulls last going up and first
     * going down unless asked, text by the database's collation.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{0: string, 1: bool, 2: ?bool}>  $order
     * @param  array<string, string>  $types
     */
    private static function sort(array &$rows, array $order, array $types, string $view): void
    {
        foreach ($order as [$column]) {
            if (! isset($types[$column]) || $types[$column] === 'json' || $column === 'search') {
                throw new LocalException('The local store cannot order '.$view.' by '.$column, 400, 'PGRST100');
            }
        }
        usort($rows, function (array $a, array $b) use ($order, $types) {
            foreach ($order as [$column, $ascending, $nullsFirst]) {
                $nullsFirst ??= ! $ascending;
                $x = $a[$column];
                $y = $b[$column];
                if ($x === null || $y === null) {
                    if ($x === null && $y === null) {
                        continue;
                    }

                    return ($x === null) === $nullsFirst ? -1 : 1;
                }
                $compared = $types[$column] === 'text' ? self::compareText((string) $x, (string) $y) : self::compare($types[$column], $x, (string) $y);
                if ($compared !== 0) {
                    return $ascending ? $compared : -$compared;
                }
            }

            return 0;
        });
    }

    /** A Postgres `real`: single precision. */
    private static function real(float $value): float
    {
        return unpack('g', pack('g', $value))[1];
    }
}
