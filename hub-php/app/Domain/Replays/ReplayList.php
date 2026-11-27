<?php

namespace App\Domain\Replays;

use App\Domain\Assets\AssetResolver;
use App\Domain\Gallery\GalleryQuery;
use App\Domain\Maps\Labels;
use App\Domain\Maps\Listing;
use App\Domain\Maps\Lookup;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use App\Support\HubCache;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The replays page (#666): every listed replay, newest match first, narrowed
 * by the query string. A replay in any other state appears nowhere here.
 *
 * Filters are an array with `game`, `map`, `player`, `min_players`,
 * `max_players`, `min_length` and `max_length` (minutes), `from` and `to`
 * (days in UTC, both included), `analysis` and `track` (`yes` or `no`) and
 * `page`.
 */
final class ReplayList
{
    public const PAGE_SIZE = GalleryQuery::PAGE_SIZE;

    /** What the `analysis` and `track` filters may say. */
    public const HAS = ['yes', 'no'];

    private const COUNTS = ['min_players', 'max_players', 'min_length', 'max_length'];

    private const KEYS = ['game', 'map', 'player', 'min_players', 'max_players', 'min_length', 'max_length', 'from', 'to', 'analysis', 'track', 'page'];

    /**
     * Reads filters out of the query string. A value this cannot make sense of
     * is not applied, so a shared link never breaks.
     *
     * @param  array<string, string|list<string>>  $params
     * @return array<string, string|int|null>
     */
    public static function parseFilters(array $params): array
    {
        $one = function (string $key) use ($params): ?string {
            $value = $params[$key] ?? null;
            $value = trim((string) (is_array($value) ? ($value[0] ?? '') : $value));

            return $value === '' ? null : $value;
        };
        $filters = ['game' => $one('game'), 'map' => $one('map'), 'player' => $one('player')];
        foreach (self::COUNTS as $key) {
            $value = $one($key);
            $filters[$key] = $value !== null && ctype_digit($value) ? (int) $value : null;
        }
        foreach (['from', 'to'] as $key) {
            $value = $one($key);
            $day = $value === null ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            $filters[$key] = $day !== false && $day->format('Y-m-d') === $value ? $value : null;
        }
        foreach (['analysis', 'track'] as $key) {
            $value = $one($key);
            $filters[$key] = in_array($value, self::HAS, true) ? $value : null;
        }
        $filters['page'] = GalleryQuery::parsePage($one('page'), self::PAGE_SIZE) ?? 1;

        return $filters;
    }

    /**
     * A link that keeps the current filters and changes some. A filter change
     * goes back to the first page.
     *
     * @param  array<string, string|int|null>  $current
     * @param  array<string, string|int|null>  $change
     */
    public static function href(array $current, array $change = []): string
    {
        $next = array_merge($current, ['page' => 1], $change);
        $pairs = [];
        foreach (self::KEYS as $key) {
            $value = $next[$key] ?? null;
            if ($value !== null && ! ($key === 'page' && $value === 1)) {
                $pairs[] = [$key, (string) $value];
            }
        }

        return $pairs === [] ? '/replays' : '/replays?'.GalleryQuery::queryString($pairs);
    }

    /** The replays of one player, by the name they played under. */
    public static function playerHref(string $name): string
    {
        return self::href([], ['player' => $name]);
    }

    /** @param array<string, string|int|null> $filters */
    public static function isFiltered(array $filters): bool
    {
        unset($filters['page']);

        return array_filter($filters, fn ($value) => $value !== null) !== [];
    }

    /**
     * One page of replays, each with its sides, and how many match in all.
     *
     * @param  array<string, string|int|null>  $filters
     * @return array{replays: list<array<string, mixed>>, count: int}
     */
    public static function page(array $filters): array
    {
        $query = self::query($filters);
        $count = $query->count();
        // The game id makes the order total, so paging never shows one replay twice or skips one.
        $rows = $query->orderByDesc('r.started_at')->orderBy('r.game_id')->forPage($filters['page'], self::PAGE_SIZE)
            ->get(['r.game_id', 'r.game', 'r.game_version', 'r.map', 'r.started_at', 'r.duration', 'r.winners', 'r.player_count'])->all();
        if ($rows === []) {
            return ['replays' => [], 'count' => $count];
        }

        $ids = array_column($rows, 'game_id');
        $seats = DB::table('replay_player')->whereIn('game_id', $ids)->whereIn('kind', ['player', 'ai'])
            ->orderBy('ally_team')->orderBy('team')->orderByDesc('kind')->orderBy('number')
            ->get(['game_id', 'kind', 'name', 'ally_team', 'faction'])->groupBy('game_id');
        $units = self::unitNames($rows, $seats->flatten(1)->pluck('faction')->filter()->unique()->values()->all());
        $analysed = DB::table('replay_analysis')->whereIn('game_id', $ids)->pluck('game_id')->flip();
        $tracked = DB::table('replay_track')->where('state', 'listed')->whereIn('game_id', $ids)->pluck('game_id')->flip();

        return ['count' => $count, 'replays' => array_map(function (object $row) use ($seats, $units, $analysed, $tracked) {
            $winners = json_decode((string) $row->winners, true) ?: [];
            $sides = [];
            foreach ($seats[$row->game_id] ?? [] as $seat) {
                $ally = $seat->ally_team === null ? null : (int) $seat->ally_team;
                $sides[$ally ?? ''] ??= ['number' => $ally === null ? null : $ally + 1, 'won' => in_array($ally, $winners, true), 'players' => []];
                $sides[$ally ?? '']['players'][] = [
                    'name' => $seat->name,
                    'ai' => $seat->kind === 'ai',
                    // A start unit the catalogue gives no faction reads better by the name the game shows for it.
                    'faction' => $units[$row->game][$seat->faction ?? ''] ?? ($seat->faction ?: null),
                ];
            }

            return [
                'game_id' => $row->game_id,
                'game' => $row->game,
                'game_version' => $row->game_version,
                'map' => $row->map,
                'started_at' => str_replace(' ', 'T', substr((string) $row->started_at, 0, 19)).'Z',
                'duration' => (int) $row->duration,
                'player_count' => (int) $row->player_count,
                'decided' => $winners !== [],
                'analysis' => isset($analysed[$row->game_id]),
                'track' => isset($tracked[$row->game_id]),
                'sides' => array_values($sides),
            ];
        }, $rows)];
    }

    /**
     * The games and maps that have a listed replay, for the filter lists.
     *
     * @return array{games: list<string>, maps: list<string>}
     */
    public static function options(): array
    {
        $held = function (string $column): array {
            $values = DB::table('replay')->where('state', 'listed')->distinct()->pluck($column)->filter()->all();
            natcasesort($values);

            return array_values($values);
        };

        return ['games' => $held('game'), 'maps' => $held('map')];
    }

    /**
     * How many listed replays a game or map has, for the link on its page.
     * None when they cannot be counted, so that page still renders.
     *
     * @param  'game'|'map'  $column
     */
    public static function countOf(string $column, string $value): int
    {
        try {
            return DB::table('replay')->where('state', 'listed')->where($column, $value)->count();
        } catch (QueryException) {
            return 0;
        }
    }

    /**
     * The title, size and minimap of each map the catalogue publishes, by map
     * name. A map it does not publish is left out, so a taken down map shows no
     * picture here either.
     *
     * @param  list<string>  $names
     * @return array<string, array{title: string, width: int|float, height: int|float, picture: array<string, mixed>}>
     */
    public static function maps(array $names): array
    {
        $names = array_values(array_unique($names));
        if ($names === []) {
            return [];
        }
        sort($names);
        try {
            // A failed read throws out of the loader, so an error answer is never cached.
            return HubCache::remember('replays:maps:'.md5(json_encode($names)), [HubCache::MAPS, HubCache::ASSETS], HubCache::WEEKS, function () use ($names) {
                $supabase = Supabase::anon();
                $published = Lookup::publishedNames(Supabase::admin(), $names);
                if ($published === null) {
                    throw new SupabaseException('The licence gate could not be read.');
                }
                $rows = $published === [] ? [] : $supabase->from('map_browse')
                    ->select('map_name,display_name,map_version,width_elmos,height_elmos')->in('map_name', $published)->get();
                $pictures = Listing::pictures($supabase, $rows);
                $maps = [];
                foreach ($rows as $row) {
                    $maps[$row['map_name']] = [
                        'title' => Labels::title($row['display_name'], $row['map_name'], $row['map_version'] ?? null),
                        'width' => $row['width_elmos'],
                        'height' => $row['height_elmos'],
                        'picture' => $pictures[$row['map_name']],
                    ];
                }

                return $maps;
            });
        } catch (SupabaseException) {
            return [];
        }
    }

    /** The drawing that stands in for a map with no picture here. @return array<string, mixed> */
    public static function placeholder(string $mapName): array
    {
        return AssetResolver::resolveAsset(AssetResolver::map($mapName), []);
    }

    /** @param array<string, string|int|null> $filters */
    private static function query(array $filters): Builder
    {
        $query = DB::table('replay as r')->where('r.state', 'listed');
        foreach (['game', 'map'] as $column) {
            if ($filters[$column] !== null) {
                $query->where('r.'.$column, $filters[$column]);
            }
        }
        // The whole name in any case. Spectators and AIs are not players.
        if ($filters['player'] !== null) {
            $query->whereExists(fn (Builder $q) => $q->from('replay_player as p')->whereColumn('p.game_id', 'r.game_id')
                ->where('p.kind', 'player')->whereRaw('lower(p.name) = ?', [mb_strtolower($filters['player'])]));
        }
        if ($filters['min_players'] !== null) {
            $query->where('r.player_count', '>=', $filters['min_players']);
        }
        if ($filters['max_players'] !== null) {
            $query->where('r.player_count', '<=', $filters['max_players']);
        }
        if ($filters['min_length'] !== null) {
            $query->where('r.duration', '>=', $filters['min_length'] * 60);
        }
        if ($filters['max_length'] !== null) {
            $query->where('r.duration', '<=', $filters['max_length'] * 60);
        }
        if ($filters['from'] !== null) {
            $query->where('r.started_at', '>=', $filters['from'].' 00:00:00');
        }
        if ($filters['to'] !== null) {
            $query->where('r.started_at', '<=', $filters['to'].' 23:59:59');
        }
        if ($filters['analysis'] !== null) {
            $query->whereExists(
                fn (Builder $q) => $q->from('replay_analysis as a')->whereColumn('a.game_id', 'r.game_id'),
                'and',
                $filters['analysis'] === 'no',
            );
        }
        // A track that is still on its way or being checked is not there to download.
        if ($filters['track'] !== null) {
            $query->whereExists(
                fn (Builder $q) => $q->from('replay_track as t')->whereColumn('t.game_id', 'r.game_id')->where('t.state', 'listed'),
                'and',
                $filters['track'] === 'no',
            );
        }

        return $query;
    }

    /**
     * The catalogue's full names for the factions that are unit names, by game
     * and unit name.
     *
     * @param  list<object>  $rows
     * @param  list<string>  $factions
     * @return array<string, array<string, string>>
     */
    private static function unitNames(array $rows, array $factions): array
    {
        if ($factions === []) {
            return [];
        }
        $found = DB::table('game as g')->join('game_unit as u', 'u.game_id', '=', 'g.id')
            ->whereNull('g.hidden_at')->whereIn('g.display_name', array_values(array_unique(array_column($rows, 'game'))))
            ->whereIn('u.unit_name', $factions)->whereNotNull('u.full_name')
            ->get(['g.display_name', 'u.unit_name', 'u.full_name']);
        $names = [];
        foreach ($found as $unit) {
            $names[$unit->display_name][$unit->unit_name] = $unit->full_name;
        }

        return $names;
    }
}
