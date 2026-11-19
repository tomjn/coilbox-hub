<?php

namespace App\Supabase\Local;

use App\Domain\Games\ReadAll;
use Illuminate\Support\Facades\DB;

/**
 * `public.game_browse` (latest in 20261117120300_hub_mirror_downloads.sql),
 * worked out from the local tables. Two of its columns come from areas still
 * in Supabase and are asked there, as the same caller and only when selected:
 * `item_count` from published items, and the mirror half of `downloads` from
 * `public.game_mirror_sources`.
 */
final class GameBrowse
{
    private const COMPUTED = ['faction_count', 'unit_count', 'item_count', 'downloads'];

    /** @return array{rows: list<array<string, mixed>>, count: int|null} */
    public static function read(Caller $caller, Rest $rest, Request $request, bool $head): array
    {
        $select = Select::parse($request->select);
        if ($select->embeds() !== []) {
            throw new LocalException('game_browse has no relationships to embed', 400, 'PGRST200');
        }
        $query = DB::table('game as gb');
        Policies::read($caller, $query, 'game_browse', 'gb');
        foreach ($request->filters([]) as $filter) {
            self::refuseComputed($filter);
            Filter::apply($query, 'game', 'gb', $filter);
        }
        $count = $request->count !== null ? (clone $query)->count() : null;
        if ($head) {
            return ['rows' => [], 'count' => $count];
        }
        foreach ($request->order([]) as [$column]) {
            if (in_array($column, self::COMPUTED, true)) {
                throw new LocalException('The local store cannot order game_browse by '.$column, 400, 'PGRST100');
            }
        }
        $rest->orderBy($query, 'game', 'gb', $request->order([]));
        $query->offset($request->offset([]))->limit(min($request->limit([]) ?? Rest::MAX_ROWS, Rest::MAX_ROWS));
        $games = array_map(fn ($row) => (array) $row, $query->select('gb.*')->get()->all());

        $wanted = [];
        foreach ($select->items as $item) {
            if ($item->kind === 'star') {
                $wanted = array_keys(Tables::COLUMNS['game_browse']);
            } elseif (! isset(Tables::COLUMNS['game_browse'][$item->name])) {
                throw new LocalException('column game_browse.'.$item->name.' does not exist', 400, '42703');
            } else {
                $wanted[] = $item->name;
            }
        }
        $extra = self::computed($caller, $games, array_values(array_intersect(self::COMPUTED, $wanted)));

        $types = Tables::COLUMNS['game_browse'];
        $rows = [];
        foreach ($games as $game) {
            $row = [];
            foreach ($select->items as $item) {
                $names = $item->kind === 'star' ? array_keys($types) : [$item->name];
                foreach ($names as $name) {
                    $row[$item->alias ?? $name] = in_array($name, self::COMPUTED, true)
                        ? $extra[$name][$game['id']]
                        : Values::read($types[$name], $game[$name] ?? null);
                }
            }
            $rows[] = $row;
        }

        return ['rows' => $rows, 'count' => $count];
    }

    /**
     * The computed columns asked for, column => game id => value.
     *
     * @param  list<array<string, mixed>>  $games
     * @param  list<string>  $columns
     * @return array<string, array<string, mixed>>
     */
    private static function computed(Caller $caller, array $games, array $columns): array
    {
        $ids = array_column($games, 'id');
        $out = [];
        if (in_array('faction_count', $columns, true)) {
            // Random is a slot in the lobby, not a side.
            $counts = $ids === [] ? [] : DB::table('game_faction')->whereIn('game_id', $ids)
                ->whereRaw('lower(trim('.self::wrap('key').")) <> 'random'")
                ->whereRaw('lower(trim('.self::wrap('name').")) <> 'random'")
                ->groupBy('game_id')->selectRaw('game_id, count(*) as n')->pluck('n', 'game_id')->all();
            $out['faction_count'] = self::each($ids, fn ($id) => (int) ($counts[$id] ?? 0));
        }
        if (in_array('unit_count', $columns, true)) {
            $counts = $ids === [] ? [] : DB::table('game_unit')->whereIn('game_id', $ids)->whereNull('removed_at')
                ->groupBy('game_id')->selectRaw('game_id, count(*) as n')->pluck('n', 'game_id')->all();
            $out['unit_count'] = self::each($ids, fn ($id) => (int) ($counts[$id] ?? 0));
        }
        if (in_array('item_count', $columns, true)) {
            $out['item_count'] = self::itemCounts($caller, $games);
        }
        if (in_array('downloads', $columns, true)) {
            $out['downloads'] = self::downloads($caller, $ids);
        }

        return $out;
    }

    /**
     * Published items per game, read from Supabase's `item` as the caller.
     *
     * @param  list<array<string, mixed>>  $games
     * @return array<string, int>
     */
    private static function itemCounts(Caller $caller, array $games): array
    {
        $shortnames = array_values(array_unique(array_column($games, 'shortname')));
        $counts = [];
        if ($shortnames !== []) {
            $client = $caller->client->remote();
            $items = ReadAll::rows(fn () => $client->from('item')->select('game_key')
                ->in('game_key', $shortnames)->is('deleted_at', null)->order('id'));
            if ($items === null) {
                throw new LocalException('Could not count the published items', 503, null);
            }
            foreach ($items as $item) {
                $counts[$item['game_key']] = ($counts[$item['game_key']] ?? 0) + 1;
            }
        }
        $out = [];
        foreach ($games as $game) {
            $out[$game['id']] = $counts[$game['shortname']] ?? 0;
        }

        return $out;
    }

    /**
     * The owner's sources, best first, then the hub mirrors' copies.
     *
     * @param  list<string>  $ids
     * @return array<string, list<array<string, mixed>>>
     */
    private static function downloads(Caller $caller, array $ids): array
    {
        $sources = [];
        if ($ids !== []) {
            $rows = DB::table('game_download_source')->whereIn('game_id', $ids)->orderBy('sort_order')->orderBy('id')->get();
            foreach ($rows as $row) {
                // jsonb_strip_nulls(jsonb_build_object(...)), keys in jsonb's order.
                $sources[$row->game_id][] = array_filter(
                    ['kind' => $row->kind, 'asset' => $row->asset, 'value' => $row->value, 'filename' => $row->filename],
                    fn ($value) => $value !== null,
                );
            }
        }
        $mirrors = MirrorStore::local()
            ? array_values(MirrorSources::games($ids))
            : $caller->client->remote()->rpcEach('game_mirror_sources', array_map(fn ($id) => ['p_game_id' => $id], $ids));
        $out = [];
        foreach ($ids as $i => $id) {
            $out[$id] = [...($sources[$id] ?? []), ...(is_array($mirrors[$i] ?? null) ? $mirrors[$i] : [])];
        }

        return $out;
    }

    /** @param array<string, mixed> $filter */
    private static function refuseComputed(array $filter): void
    {
        if (isset($filter['logic'])) {
            foreach ($filter['items'] as $item) {
                self::refuseComputed($item);
            }

            return;
        }
        if (in_array($filter['column'], self::COMPUTED, true)) {
            throw new LocalException('The local store cannot filter game_browse on '.$filter['column'], 400, 'PGRST100');
        }
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, mixed>
     */
    private static function each(array $ids, callable $value): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $value($id);
        }

        return $out;
    }

    private static function wrap(string $column): string
    {
        return DB::connection()->getQueryGrammar()->wrap($column);
    }
}
