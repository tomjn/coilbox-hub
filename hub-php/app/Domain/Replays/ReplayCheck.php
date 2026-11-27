<?php

namespace App\Domain\Replays;

use App\Domain\Replays\Lua\UnitList;
use App\Domain\Replays\Stream\Event;
use Illuminate\Support\Facades\DB;

/**
 * Compares the summary Coilbox sent with the replay file (#663). Answers the
 * first difference as a sentence a moderator can read, or null when the file
 * confirms everything the summary says.
 *
 * Numbers the engine keeps as 32 bit floats are compared as 32 bit floats, so
 * a summary that sends them widened or rounded to the shortest form both match.
 */
final class ReplayCheck
{
    /** @param  array<string, mixed>  $summary  already checked by {@see ReplaySummary::parse()} */
    public static function difference(Replay $replay, array $summary): ?string
    {
        $h = $replay->header;
        $s = $replay->script;
        foreach ([
            'engine' => $h->engineVersion, 'game' => $s->gameType, 'map' => $s->map,
            'started_at' => $h->startTime, 'duration' => $h->gameTime,
        ] as $key => $file) {
            if ($summary[$key] !== $file) {
                return "The summary's {$key} is ".json_encode($summary[$key]).' and the file says '.json_encode($file).'.';
            }
        }

        return self::roster($replay, $summary)
            ?? self::winners($replay, $summary)
            ?? self::totals($replay, $summary)
            ?? self::startPositions($replay, $summary)
            ?? self::buildOrders($replay, $summary)
            ?? self::units($replay, $summary);
    }

    /** Every name the file has for somebody in the match, players and spectators, including late joiners. */
    public static function names(Replay $replay): array
    {
        $names = array_map(fn ($p) => $p->name, $replay->script->players);
        foreach ($replay->stream->eventsOf(Event::NEW_PLAYER) as $e) {
            $names[] = $e->data['name'];
        }

        return array_values(array_unique($names));
    }

    /** The unit list the replay carries, read only from Beyond All Reason's. */
    public static function unitList(Replay $replay): ?UnitList
    {
        return $replay->stream->luaMessagesOf('unit_list')[0]->value ?? null;
    }

    private static function roster(Replay $replay, array $summary): ?string
    {
        $file = $players = [];
        $spectators = [];
        $unchecked = [];
        $played = PlayedFactions::of($replay, $summary['units']);
        foreach ($replay->script->players as $id => $p) {
            if ($p->spectator) {
                $spectators[] = $p->name;

                continue;
            }
            $seat = [$p->name, $p->team, $p->allyTeam];
            if (! $played[$id]['known']) {
                $unchecked[] = $seat;
            }
            // False stands for a faction the hub cannot check, on both sides.
            $file[] = [...$seat, $played[$id]['known'] ? $played[$id]['faction'] : false];
        }
        foreach ($summary['players'] as $p) {
            $seat = [$p['name'], $p['team'], $p['ally_team']];
            $players[] = [...$seat, in_array($seat, $unchecked, true) ? false : ($p['faction'] ?? null)];
        }
        if (self::sorted($players) !== self::sorted($file)) {
            return "The summary's players are not the file's: each player's name, team, ally team and faction have to match.";
        }
        if (self::sorted($summary['spectators']) !== self::sorted($spectators)) {
            return "The summary's spectators are not the file's.";
        }

        return null;
    }

    private static function winners(Replay $replay, array $summary): ?string
    {
        $file = $replay->endOfGame?->winningAllyTeams ?? [];
        if (self::sorted($summary['winners']) !== self::sorted($file)) {
            return 'The summary says ally teams '.json_encode($summary['winners']).' won and the file says '.json_encode($file).'.';
        }

        return null;
    }

    private static function totals(Replay $replay, array $summary): ?string
    {
        $file = [];
        foreach ($replay->endOfGame?->teams ?? [] as $team => $samples) {
            if ($samples !== []) {
                $file[$team] = $samples[array_key_last($samples)];
            }
        }
        $sent = self::byTeam($summary['totals']);
        if ($sent === null || array_keys($sent) !== array_keys($file)) {
            return "The summary's totals are not for the teams the file has totals for: ".json_encode(array_keys($file)).'.';
        }
        foreach ($file as $team => $sample) {
            foreach (ReplaySummary::TOTALS as $key) {
                $value = $sample->{lcfirst(str_replace('_', '', ucwords($key, '_')))};
                $same = is_float($value) ? self::f32($sent[$team][$key]) === self::f32($value) : $sent[$team][$key] == $value;
                if (! $same) {
                    return "The summary's {$key} for team {$team} is ".json_encode($sent[$team][$key]).' and the file says '.json_encode($value).'.';
                }
            }
        }

        return null;
    }

    private static function startPositions(Replay $replay, array $summary): ?string
    {
        $file = array_filter($replay->stream->startPositions(), fn ($p) => isset($replay->script->teams[$p->team]));
        $sent = self::byTeam($summary['start_positions']);
        if ($sent === null || array_keys($sent) !== array_keys($file)) {
            return "The summary's start positions are not for the teams the file has start positions for: ".json_encode(array_keys($file)).'.';
        }
        foreach ($file as $team => $p) {
            if (self::f32($sent[$team]['x']) !== self::f32($p->x) || self::f32($sent[$team]['z']) !== self::f32($p->z)) {
                return "The summary's start position for team {$team} is not the file's.";
            }
        }

        return null;
    }

    private static function buildOrders(Replay $replay, array $summary): ?string
    {
        $sent = [];
        foreach ($summary['build_orders'] as $entry) {
            if (isset($sent[$entry['team']])) {
                return "The summary's build orders list team {$entry['team']} twice.";
            }
            $sent[$entry['team']] = array_map(fn ($o) => [$o['frame'], $o['unit_def_id']], $entry['orders']);
        }
        $file = array_map(fn ($orders) => array_map(fn ($o) => [$o['frame'], $o['unit_def_id']], $orders), BuildOrders::of($replay));
        foreach (array_keys($sent + $file) as $team) {
            $a = $sent[$team] ?? [];
            $b = $file[$team] ?? [];
            if ($a !== $b) {
                $at = 0;
                while (($a[$at] ?? null) === ($b[$at] ?? null)) {
                    $at++;
                }

                return "The summary's build orders for team {$team} differ from the file's at order {$at}: the summary has "
                    .count($a).' orders and the file '.count($b).'.';
            }
        }

        return null;
    }

    /**
     * Unit names cannot be read from most replays. Beyond All Reason's carry
     * its unit list, and the name for each id has to match it. For another
     * game, when the catalogue has every unit of this exact release, each name
     * has to be one of them.
     */
    private static function units(Replay $replay, array $summary): ?string
    {
        $list = self::unitList($replay);
        if ($list !== null) {
            foreach ($summary['units'] as $unit) {
                if ($list->name($unit['unit_def_id']) !== $unit['name']) {
                    return "The summary names unit {$unit['unit_def_id']} \"{$unit['name']}\" and the replay's unit list says ".json_encode($list->name($unit['unit_def_id'])).'.';
                }
            }

            return null;
        }
        $known = self::catalogueUnits($replay->script->gameType);
        if ($known === null) {
            return null;
        }
        foreach ($summary['units'] as $unit) {
            if (! isset($known[$unit['name']])) {
                return "The summary names unit {$unit['unit_def_id']} \"{$unit['name']}\", which the catalogue does not have for this game.";
            }
        }

        return null;
    }

    /**
     * The unit names the catalogue holds for the game that is `$gameType`,
     * when it holds that release in full. Null when it does not.
     *
     * @return array<string, true>|null
     */
    private static function catalogueUnits(string $gameType): ?array
    {
        // The game type is the game's name, a space and the release, and either may hold spaces.
        $splits = [];
        for ($at = strpos($gameType, ' '); $at !== false; $at = strpos($gameType, ' ', $at + 1)) {
            $splits[substr($gameType, 0, $at)] = substr($gameType, $at + 1);
        }
        if ($splits === []) {
            return null;
        }
        $release = DB::table('game as g')->join('game_version as v', 'v.game_id', '=', 'g.id')
            ->where('v.reported_in_full', true)->whereIn('g.display_name', array_keys($splits))->whereIn('v.version', array_values($splits))
            ->get(['g.id', 'g.display_name', 'v.version'])
            ->first(fn ($r) => ($splits[$r->display_name] ?? null) === $r->version);
        if ($release === null) {
            return null;
        }

        return array_fill_keys(DB::table('game_unit')->where('game_id', $release->id)->pluck('unit_name')->all(), true);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>|null by team in team order, or null when a team is listed twice
     */
    private static function byTeam(array $entries): ?array
    {
        $out = [];
        foreach ($entries as $entry) {
            if (isset($out[$entry['team']])) {
                return null;
            }
            $out[$entry['team']] = $entry;
        }
        ksort($out);

        return $out;
    }

    /** The list's entries as JSON, sorted, so two lists compare without regard to order. */
    private static function sorted(array $list): array
    {
        $list = array_map(fn ($v) => json_encode($v, JSON_INVALID_UTF8_SUBSTITUTE), $list);
        sort($list, SORT_STRING);

        return $list;
    }

    private static function f32(int|float $v): string
    {
        return pack('g', $v);
    }
}
