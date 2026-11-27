<?php

namespace App\Domain\Replays;

use App\Supabase\Local\Values;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Illuminate\Support\Facades\DB;

/**
 * What the replay page (#667) draws from the file beyond the check's facts:
 * start boxes, team colours, the statistics history and where orders were
 * aimed. The check writes them, and `replays:page-facts` writes them for
 * replays checked before they were kept.
 */
final class ReplayPageFacts
{
    /**
     * The map's size in elmos from the map catalogue, or null when it does not
     * have the map or cannot be read. Without it there is no order heat.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function mapSize(string $map): ?array
    {
        try {
            $row = Supabase::anon()->from('map')->select('width_elmos,height_elmos')->eq('map_name', $map)->maybeSingle();
        } catch (SupabaseException) {
            return null;
        }
        $width = (int) ($row['width_elmos'] ?? 0);
        $height = (int) ($row['height_elmos'] ?? 0);

        return $width > 0 && $height > 0 ? [$width, $height] : null;
    }

    /**
     * The `replay` columns.
     *
     * @param  array{0: int, 1: int}|null  $mapSize
     * @return array{start_boxes: string, order_heat: ?string, page_facts_at: string}
     */
    public static function replay(Replay $replay, ?array $mapSize): array
    {
        $boxes = [];
        foreach ($replay->script->allyTeams as $id => $ally) {
            if ($ally->startBox !== null) {
                $boxes[] = ['ally_team' => $id] + $ally->startBox;
            }
        }
        $grid = $mapSize === null ? null : OrderHeat::grid($mapSize[0], $mapSize[1]);

        return [
            'start_boxes' => json_encode($boxes, JSON_PRESERVE_ZERO_FRACTION),
            'order_heat' => $grid === null ? null : json_encode(OrderHeat::of($replay, $grid)),
            'page_facts_at' => Values::now(),
        ];
    }

    /**
     * The `replay_team` columns for one team.
     *
     * @return array{colour: ?string, history: ?string}
     */
    public static function team(Replay $replay, int $team): array
    {
        $colour = $replay->script->teams[$team]->colour ?? null;
        $samples = $replay->endOfGame?->teams[$team] ?? [];
        $history = null;
        if (count($samples) > 1) {
            $history = ['frame' => array_map(fn (TeamSample $s) => $s->frame, $samples)];
            foreach (ReplaySummary::TOTALS as $key) {
                $property = lcfirst(str_replace('_', '', ucwords($key, '_')));
                $history[$key] = array_map(fn (TeamSample $s) => $s->{$property}, $samples);
            }
        }

        return [
            'colour' => $colour === null ? null : self::hex($colour),
            'history' => $history === null ? null : json_encode($history, JSON_PRESERVE_ZERO_FRACTION),
        ];
    }

    /** Writes every page fact for a replay already stored. */
    public static function write(string $gameId, Replay $replay, ?array $mapSize): void
    {
        DB::transaction(function () use ($gameId, $replay, $mapSize) {
            DB::table('replay')->where('game_id', $gameId)->update(self::replay($replay, $mapSize));
            foreach (DB::table('replay_team')->where('game_id', $gameId)->pluck('team') as $team) {
                DB::table('replay_team')->where('game_id', $gameId)->where('team', $team)->update(self::team($replay, (int) $team));
            }
            MapTotals::refresh($gameId);
            GameTotals::refresh($gameId);
        });
    }

    /** @param array{0: float, 1: float, 2: float} $rgb 0 to 1 each */
    private static function hex(array $rgb): string
    {
        return '#'.implode('', array_map(fn ($c) => sprintf('%02x', (int) round(max(0, min(1, $c)) * 255)), $rgb));
    }
}
