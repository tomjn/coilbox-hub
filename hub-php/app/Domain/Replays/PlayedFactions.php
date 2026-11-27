<?php

namespace App\Domain\Replays;

use App\Domain\Replays\Lua\FactionPick;
use App\Domain\Replays\Stream\Stream;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The faction each player played (#679). The lobby writes a `side` into the
 * start script, and a game with its own faction picker lets the player change
 * it. So the last pick the game accepts counts, and the start script's side
 * when there is none.
 *
 * Splinter Faction and TA Recoil set a faction for each team, so a player
 * takes the last pick that counts from anybody on their team. With their
 * faction choice on, the game gives a team with no pick a random faction and
 * the file does not say which (#684), so the faction is null and not checked.
 * With it off, the game takes no picks and every team plays its side.
 *
 * Most games pick by start unit. The catalogue says which faction a start unit
 * belongs to. Where it does not, the faction is the unit's name, or null when
 * nothing names the unit. Neither is known well enough to check a summary
 * against.
 */
final class PlayedFactions
{
    /**
     * Games that take picks into the match, in a faction phase, by how their
     * game type starts. Other games take them only before the first frame.
     */
    private const FACTION_PHASE_GAMES = ['SplinterFaction ', 'Total Annihilation: Recoil '];

    /** The frame the faction phase ends at, from both games' `game_spawn.lua`. */
    private const FACTION_PHASE_FRAMES = 900;

    /** The frames the phase stays open for once every team has picked. */
    private const FRAMES_AFTER_LAST_TEAM = 90;

    /**
     * @param  list<array{unit_def_id: int, name: string}>  $summaryUnits  names the start units of a replay with no unit list
     * @return array<int, array{faction: ?string, known: bool}> by player number, spectators left out.
     *                                                          `known` is false when a summary's faction cannot be checked.
     */
    public static function of(Replay $replay, array $summaryUnits = []): array
    {
        $phase = Str::startsWith($replay->script->gameType, self::FACTION_PHASE_GAMES);
        $choice = $phase && self::factionChoice($replay->script->modOptions, $replay->script->gameType);
        $picks = $phase && ! $choice ? [] : self::picks($replay, $phase);

        $list = ReplayCheck::unitList($replay);
        $named = array_column($summaryUnits, 'name', 'unit_def_id');
        $units = [];
        foreach ($picks as $player => $pick) {
            if ($pick->unitDefId !== null) {
                $units[$player] = $list === null ? $named[$pick->unitDefId] ?? null : $list->name($pick->unitDefId);
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
                $out[$id] = $choice ? ['faction' => null, 'known' => false] : ['faction' => $player->side, 'known' => true];
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
     * Whether the faction phase is on, read the way each game's
     * `game_spawn.lua` reads its mod options.
     *
     * @param  array<string, string>  $modOptions
     */
    private static function factionChoice(array $modOptions, string $gameType): bool
    {
        if (str_starts_with($gameType, 'SplinterFaction ')) {
            return ! in_array($modOptions['skipfactionchooser'] ?? null, ['1', 'true'], true);
        }
        $skip = $modOptions['skippregame'] ?? null;
        if (is_numeric($skip) && (float) $skip != 0) {
            return false;
        }

        return ! in_array($modOptions['factionchoice'] ?? null, ['0', 'false'], true);
    }

    /**
     * Each player's last pick the game accepted, by player number. In a game
     * with a faction phase it is the last pick by anybody on the player's team.
     *
     * @return array<int, FactionPick>
     */
    private static function picks(Replay $replay, bool $phase): array
    {
        // The first frame at which a pick is too late.
        $closes = $phase ? self::FACTION_PHASE_FRAMES : Stream::PREGAME + 1;
        $waiting = [];
        foreach ($replay->script->players as $player) {
            if (! $player->spectator) {
                $waiting[$player->team] = true;
            }
        }

        $picks = $teams = [];
        foreach ($replay->stream->luaMessagesOf('faction_pick') as $message) {
            if ($message->frame >= $closes) {
                continue;
            }
            $picks[$message->player] = $message->value;
            $sender = $replay->script->players[$message->player] ?? null;
            $team = $sender?->spectator ? null : $sender?->team;
            if ($team !== null) {
                $teams[$team] = $message->value;
            }
            if ($phase && isset($waiting[$team])) {
                unset($waiting[$team]);
                if ($waiting === []) {
                    // The game shortens the phase on the next frame.
                    $closes = min($closes, $message->frame + 1 + self::FRAMES_AFTER_LAST_TEAM);
                }
            }
        }
        if (! $phase) {
            return $picks;
        }
        $out = [];
        foreach ($replay->script->players as $id => $player) {
            if (! $player->spectator && isset($teams[$player->team])) {
                $out[$id] = $teams[$player->team];
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
