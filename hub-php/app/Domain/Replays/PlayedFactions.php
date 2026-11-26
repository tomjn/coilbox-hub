<?php

namespace App\Domain\Replays;

use App\Domain\Replays\Stream\Stream;
use Illuminate\Support\Facades\DB;

/**
 * The faction each player played (#679). The lobby writes a `side` into the
 * start script, and a game with its own faction picker lets the player change
 * it before the match starts. So the last pick before the first frame counts,
 * and the start script's side when there is none.
 *
 * Most games pick by start unit. The catalogue says which faction a start unit
 * belongs to. Where it does not, the faction is the unit's name, or null when
 * the replay cannot name the unit either. Neither is known well enough to check
 * a summary against.
 */
final class PlayedFactions
{
    /**
     * @return array<int, array{faction: ?string, known: bool}> by player number, spectators left out.
     *                                                          `known` is false when a summary's faction cannot be checked.
     */
    public static function of(Replay $replay): array
    {
        $picks = [];
        foreach ($replay->stream->luaMessagesOf('faction_pick') as $message) {
            if ($message->frame === Stream::PREGAME) {
                $picks[$message->player] = $message->value;
            }
        }

        $list = ReplayCheck::unitList($replay);
        $units = [];
        foreach ($picks as $player => $pick) {
            if ($pick->unitDefId !== null) {
                $units[$player] = $list?->name($pick->unitDefId);
            }
        }
        $factions = self::catalogueFactions($replay->script->gameType, array_values(array_filter($units)));

        $out = [];
        foreach ($replay->script->players as $id => $player) {
            if ($player->spectator) {
                continue;
            }
            $pick = $picks[$id] ?? null;
            if ($pick === null) {
                $out[$id] = ['faction' => $player->side, 'known' => true];
            } elseif ($pick->faction !== null) {
                $out[$id] = ['faction' => $pick->faction, 'known' => true];
            } else {
                $faction = $factions[$units[$id] ?? ''] ?? null;
                $out[$id] = ['faction' => $faction ?? $units[$id], 'known' => $faction !== null];
            }
        }

        return $out;
    }

    /**
     * The faction of each of these units that the catalogue has as one of the
     * game's start units. A unit that two games of this name put in different
     * factions is left out.
     *
     * @param  list<string>  $units
     * @return array<string, string> faction names by unit name
     */
    private static function catalogueFactions(string $gameType, array $units): array
    {
        // The game type is the game's name, a space and the release, and either may hold spaces.
        $games = [];
        for ($at = strpos($gameType, ' '); $at !== false; $at = strpos($gameType, ' ', $at + 1)) {
            $games[] = substr($gameType, 0, $at);
        }
        if ($games === [] || $units === []) {
            return [];
        }
        $rows = DB::table('game as g')
            ->join('game_unit as u', 'u.game_id', '=', 'g.id')
            ->join('game_faction as f', fn ($join) => $join->on('f.game_id', '=', 'g.id')->on('f.key', '=', 'u.faction_key'))
            ->whereNull('g.hidden_at')->whereIn('g.display_name', $games)->whereIn('u.unit_name', $units)
            ->get(['g.start_units', 'u.unit_name', 'f.name']);

        $found = [];
        foreach ($rows as $row) {
            // Start units are spelled as the game's side data has them, which need not match the unit's own case.
            $starts = array_map('strtolower', json_decode((string) $row->start_units, true) ?? []);
            if (in_array(strtolower($row->unit_name), $starts, true)) {
                $found[$row->unit_name][$row->name] = $row->name;
            }
        }

        return array_map(fn ($names) => array_values($names)[0], array_filter($found, fn ($names) => count($names) === 1));
    }
}
