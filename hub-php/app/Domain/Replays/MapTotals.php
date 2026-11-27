<?php

namespace App\Domain\Replays;

use App\Supabase\Local\Values;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * What listed replays say about each map version (#669), for the map's page.
 * Each listed replay has one share in `replay_map_share`: its length, where
 * each team started and whether it won, and its heat grids. The grids are also
 * added up in `replay_map_total`, so a page view does not add them again.
 * {@see refresh} is the only writer of both.
 */
final class MapTotals
{
    /** The heat layers, in the map card's order. */
    private const LAYERS = ['orders', 'deaths', 'damage'];

    /**
     * Makes the totals hold what this replay says now: adds a newly listed
     * one, swaps its share when its facts change, and takes it out once it is
     * no longer listed. Safe to call again. Call it after any change to a
     * replay's state, order heat or kept analysis.
     */
    public static function refresh(string $gameId): void
    {
        DB::transaction(function () use ($gameId) {
            // Locked first, so two changes to one replay cannot both add it.
            $replay = DB::table('replay')->where('game_id', $gameId)->lockForUpdate()->first();
            $old = DB::table('replay_map_share')->where('game_id', $gameId)->lockForUpdate()->first();
            $new = $replay === null || $replay->state !== 'listed' || $replay->map === null ? null : self::share($replay);

            $changes = [];
            if ($old !== null) {
                self::gather($changes, $old->map, json_decode($old->layers, true) ?: [], -1);
                DB::table('replay_map_share')->where('game_id', $gameId)->delete();
            }
            if ($new !== null) {
                self::gather($changes, $replay->map, $new['layers'], 1);
                DB::table('replay_map_share')->insert([
                    'game_id' => $gameId, 'map' => $replay->map, 'duration' => (int) $replay->duration,
                    'starts' => json_encode($new['starts']), 'layers' => json_encode((object) $new['layers']), 'counted_at' => Values::now(),
                ]);
            }
            // One order for every writer, so two replays on one map cannot deadlock.
            ksort($changes);
            foreach ($changes as $change) {
                self::apply($change);
            }
        });
    }

    /**
     * What the map's page shows, or null when no listed replay was played on
     * the map. Each figure comes with the number of replays behind it.
     *
     * @param  array<string, mixed>  $facts  the map catalogue's facts
     * @param  array<string, mixed>  $picture  its minimap
     * @return array<string, mixed>|null
     */
    public static function page(string $slug, string $mapName, array $facts, array $picture): ?array
    {
        try {
            $shares = DB::table('replay_map_share')->where('map', $mapName)->orderBy('game_id')->get(['game_id', 'duration', 'starts']);
        } catch (QueryException) {
            return null;
        }
        if ($shares->isEmpty()) {
            return null;
        }

        $starts = [];
        $withStarts = 0;
        foreach ($shares as $share) {
            $of = json_decode($share->starts, true) ?: [];
            $withStarts += $of === [] ? 0 : 1;
            foreach ($of as [$x, $z, $won]) {
                $starts[] = ['game_id' => $share->game_id, 'x' => (float) $x, 'z' => (float) $z, 'won' => $won];
            }
        }
        [$width, $height] = [$facts['width_elmos'], $facts['height_elmos']];
        $areas = StartAreas::of($starts, $facts['points']['start'] ?? [], $width, $height);

        $layers = [];
        foreach (self::grids($mapName, $facts) as $key => $grid) {
            $layer = ReplayPage::cardLayer($key, $grid['heat'], '/map/'.rawurlencode($slug).'/heat.png');
            $from = self::replays($grid['replays']);
            $layer['label'] .= ' ('.$from.')';
            $layer['legend'] .= ', from '.$from;
            $layers[$key] = $layer;
        }

        $lengths = $shares->pluck('duration')->map(fn ($d) => (int) $d)->sort()->values();

        return [
            'matches' => $shares->count(),
            // The two middle lengths of an even number, halved, as `lengthSummary` does.
            'median' => (int) round($lengths->median()),
            'shortest' => $lengths->first(),
            'longest' => $lengths->last(),
            'areas' => $areas,
            'areas_from' => $withStarts,
            'card' => [
                'map' => ['width' => $width, 'height' => $height],
                'picture' => $picture,
                'boxes' => [],
                // Most taken last, so its marker is the one on top.
                'starts' => array_map(fn ($a) => [
                    'left' => $a['x'] / $width * 100, 'top' => $a['z'] / $height * 100, 'colour' => '#ffffff', 'number' => $a['number'],
                    'name' => 'Start area '.$a['number'].', '.self::record($a),
                    'caption' => 'Start area '.$a['number'].' is '.round($a['x'] / $width * 100).'% across and '.round($a['z'] / $height * 100).'% down, '.self::record($a).'.',
                ], array_reverse($areas)),
                'starts_label' => 'Start areas ('.self::replays($withStarts).')',
                'layers' => $layers,
                'no_heat' => 'These replays have no heat for this map yet.',
                'no_boxes' => null,
            ],
        ];
    }

    /**
     * One layer's total for the picture route, with the ramp to draw it in, or
     * null when the map has none.
     *
     * @param  array<string, mixed>  $facts
     * @return array{heat: array<string, mixed>, ramp: list<string>}|null
     */
    public static function heat(string $mapName, array $facts, string $layer): ?array
    {
        $grid = self::grids($mapName, $facts)[$layer] ?? null;

        return $grid === null ? null : ['heat' => $grid['heat'], 'ramp' => ReplayPage::HEAT_LAYERS[$layer]['ramp']];
    }

    /** How a start area did: "won 3 of 5". A match nobody won is no loss. @param array{taken: int, known: int, won: int} $area */
    public static function record(array $area): string
    {
        $unknown = $area['taken'] - $area['known'];
        if ($area['known'] === 0) {
            return 'no result in '.($unknown === 1 ? '1 start' : $unknown.' starts');
        }

        return 'won '.$area['won'].' of '.$area['known'].($unknown > 0 ? ', and '.$unknown.' with no result' : '');
    }

    public static function replays(int $count): string
    {
        return $count === 1 ? '1 replay' : number_format($count).' replays';
    }

    /**
     * The totals on the grid of the map's catalogued size, by layer, in the
     * card's order.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, array{replays: int, heat: array<string, mixed>}>
     */
    private static function grids(string $mapName, array $facts): array
    {
        $grid = OrderHeat::grid((int) $facts['width_elmos'], (int) $facts['height_elmos']);
        if ($grid === null) {
            return [];
        }
        $rows = DB::table('replay_map_total')->where('map', $mapName)
            ->where('world_width', $grid['world_width'])->where('world_height', $grid['world_height'])->get()->keyBy('layer');
        $out = [];
        foreach (self::LAYERS as $layer) {
            if (isset($rows[$layer])) {
                $out[$layer] = ['replays' => (int) $rows[$layer]->replays, 'heat' => $grid + ['cells' => json_decode($rows[$layer]->cells, true)]];
            }
        }

        return $out;
    }

    /**
     * What one listed replay adds. Damage is rounded to whole points, so that
     * subtracting a share leaves no fraction behind.
     *
     * @return array{starts: list<array{0: float, 1: float, 2: ?bool}>, layers: array<string, array{world_width: int, world_height: int, cells: list<array{0: int, 1: int}>}>}
     */
    private static function share(object $replay): array
    {
        $winners = json_decode((string) $replay->winners, true) ?: [];
        $starts = DB::table('replay_team')->where('game_id', $replay->game_id)->whereNotNull('start_x')->whereNotNull('start_z')->orderBy('team')->get()
            ->map(fn ($t) => [(float) $t->start_x, (float) $t->start_z, $winners === [] ? null : (bool) $t->won])->all();

        $facts = ReplayPage::facts($replay->game_id);
        $layers = [];
        foreach (self::LAYERS as $layer) {
            $heat = $layer === 'orders' ? json_decode((string) $replay->order_heat, true) : ReplayPage::analysisHeat($facts, $layer);
            $cells = [];
            foreach ($heat['cells'] ?? [] as [$cell, $amount]) {
                if ((int) round($amount) > 0) {
                    $cells[] = [$cell, (int) round($amount)];
                }
            }
            if ($cells !== []) {
                $layers[$layer] = ['world_width' => $heat['world_width'], 'world_height' => $heat['world_height'], 'cells' => $cells];
            }
        }

        return ['starts' => $starts, 'layers' => $layers];
    }

    /** Adds one share's grids to the changes to make, `$sign` 1 to add and -1 to take out. */
    private static function gather(array &$changes, string $map, array $layers, int $sign): void
    {
        foreach ($layers as $layer => $grid) {
            $key = implode("\0", [$map, $layer, $grid['world_width'], $grid['world_height']]);
            $changes[$key] ??= [
                'row' => ['map' => $map, 'layer' => $layer, 'world_width' => $grid['world_width'], 'world_height' => $grid['world_height']],
                'replays' => 0, 'cells' => [],
            ];
            $changes[$key]['replays'] += $sign;
            foreach ($grid['cells'] as [$cell, $amount]) {
                $changes[$key]['cells'][$cell] = ($changes[$key]['cells'][$cell] ?? 0) + $sign * $amount;
            }
        }
    }

    /** @param array{row: array<string, mixed>, replays: int, cells: array<int, int>} $change */
    private static function apply(array $change): void
    {
        $delta = array_filter($change['cells']);
        if ($change['replays'] === 0 && $delta === []) {
            return;
        }
        // An upsert takes the row's write lock whether or not it was there, which an ignored insert does not.
        DB::table('replay_map_total')->upsert([$change['row'] + ['replays' => 0, 'cells' => '[]']], array_keys($change['row']), ['map']);
        $query = DB::table('replay_map_total')->where($change['row']);
        $total = (clone $query)->lockForUpdate()->first();
        $replays = (int) $total->replays + $change['replays'];
        if ($replays <= 0) {
            $query->delete();

            return;
        }
        $cells = array_column(json_decode($total->cells, true), 1, 0);
        foreach ($delta as $cell => $amount) {
            $cells[$cell] = ($cells[$cell] ?? 0) + $amount;
        }
        $cells = array_filter($cells, fn ($amount) => $amount > 0);
        ksort($cells);
        $query->update(['replays' => $replays, 'cells' => json_encode(array_map(null, array_keys($cells), array_values($cells)))]);
    }
}
