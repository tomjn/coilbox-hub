<?php

namespace App\Domain\Replays;

use App\Domain\Replays\Stream\ChatMessage;
use App\Domain\Replays\Stream\Event;
use App\Domain\Replays\Stream\Stream;
use App\Support\HubCache;
use Illuminate\Support\Facades\DB;

/**
 * One listed replay's page (#667), laid out like Coilbox's replay page
 * (`src/content/pages/ReplayDetailPage.tsx`), so a player finds the same
 * things in the same places in both. Everything here is what the check
 * confirmed from the file.
 */
final class ReplayPage
{
    /** The panel's tabs, by the `tab` query value Coilbox uses too. */
    public const TABS = ['summary' => 'Summary', 'economy' => 'Economy', 'builds' => 'Build orders', 'events' => 'Events and chat'];

    /** What the event list can be narrowed to. */
    public const SHOW = ['all' => 'All', 'chat' => 'Chat', 'players' => 'Players'];

    /** The roster's figures after APM, in Coilbox's order: column => label. */
    public const ROSTER = [
        'metal_produced' => 'Metal produced', 'energy_produced' => 'Energy produced', 'damage_dealt' => 'Damage dealt',
        'damage_received' => 'Damage received', 'units_produced' => 'Units built', 'units_died' => 'Units lost', 'units_killed' => 'Units killed',
    ];

    /** The charts drawn when a replay holds a history: column => label. */
    public const CHARTS = [
        'metal_produced' => 'Metal produced', 'energy_produced' => 'Energy produced', 'damage_dealt' => 'Damage dealt',
        'damage_received' => 'Damage received', 'units_produced' => 'Units built', 'units_killed' => 'Units killed',
    ];

    /** Beyond All Reason's recorded awards, titled as Coilbox titles its own. */
    public const AWARDS = [
        'econDestroyed' => 'Most enemy economy destroyed',
        'fightingUnitsDestroyed' => 'Most enemy fighting units destroyed',
        'resourceEfficiency' => 'Most efficient',
        'cow' => 'Won every award',
        'mostResourcesProduced' => 'Most resources produced',
        'mostDamageTaken' => 'Most damage taken',
        'sleep' => 'Longest time without a kill',
    ];

    private const FRAMES_PER_SECOND = 30;

    /** `CHART_PALETTE` in Coilbox's `src/lib/chartPalette.ts`. */
    private const PALETTE = ['#b979c5', '#27a902', '#1c58fc', '#9a5835', '#067396', '#cf027e', '#4296fb', '#fa5e75'];

    /** A game id as the hub keeps it. */
    public static function isGameId(string $value): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $value) === 1;
    }

    /**
     * The page, or null when no listed replay has this game id. A replay being
     * checked, refused or removed has no page.
     *
     * @return array<string, mixed>|null
     */
    public static function load(string $gameId): ?array
    {
        if (! self::isGameId($gameId)) {
            return null;
        }
        $row = DB::table('replay')->where('game_id', $gameId)->where('state', 'listed')->first();
        if ($row === null) {
            return null;
        }
        $winners = json_decode((string) $row->winners, true) ?: [];
        $teams = self::coloured(DB::table('replay_team')->where('game_id', $gameId)->orderBy('team')->get()->keyBy('team'));
        $seats = DB::table('replay_player')->where('game_id', $gameId)
            ->orderBy('ally_team')->orderBy('team')->orderByDesc('kind')->orderBy('number')->get();
        $summary = json_decode((string) $row->summary, true);
        $units = self::units($row->game, is_array($summary) ? ($summary['units'] ?? []) : []);
        $factions = ReplayList::unitNames([$row], $seats->pluck('faction')->filter()->unique()->values()->all())[$row->game] ?? [];

        $players = $seats->where('kind', '!=', 'spectator');
        $byTeam = [];
        foreach ($players as $seat) {
            if ($seat->team !== null) {
                $byTeam[(int) $seat->team][] = $seat->kind === 'ai' ? $seat->name.' (AI)' : $seat->name;
            }
        }
        $teamName = fn (?int $team) => $team !== null && isset($byTeam[$team]) ? self::andList($byTeam[$team]) : 'Team '.(($team ?? -1) + 1);
        $map = ReplayList::maps([(string) $row->map])[$row->map] ?? null;

        return [
            'game_id' => $gameId,
            'game' => $row->game,
            'game_version' => $row->game_version,
            'map' => $row->map,
            'map_title' => $map['title'] ?? $row->map,
            'engine' => $row->engine,
            'started_at' => str_replace(' ', 'T', substr((string) $row->started_at, 0, 19)).'Z',
            'duration' => (int) $row->duration,
            'size' => (int) $row->size,
            'result' => self::result($winners),
            'sides' => self::sides($players, $teams, $winners, $factions, (int) $row->duration),
            'spectators' => $seats->where('kind', 'spectator')->pluck('name')->all(),
            'tiles' => self::tiles($teams, $players, (int) $row->duration, self::result($winners)),
            'awards' => self::awards(json_decode((string) $row->extra, true)['bar.awards'] ?? [], $teamName),
            'history' => self::history($teams, $teamName),
            'builds' => self::builds($teams, $teamName),
            'units' => $units,
            'timeline' => self::timeline($gameId, json_decode((string) $row->events, true) ?: [], $seats, $teamName),
            'map_card' => self::mapCard($row, $map, $teams, $teamName),
            'downloads' => [
                'replay' => self::replayUrl($row),
                'tracks' => array_values(array_filter(Tracks::listed($gameId), fn ($t) => $t['url'] !== null)),
            ],
        ];
    }

    /** `formatDuration`: m:ss, or h:mm:ss from an hour. */
    public static function duration(int $seconds): string
    {
        return $seconds >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60)
            : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /** A frame as match time, as the event list shows it. */
    public static function time(int $frame): string
    {
        return $frame <= Stream::PREGAME ? 'Pre-game' : self::duration(intdiv($frame, self::FRAMES_PER_SECOND));
    }

    /** `formatTotal`: whole numbers with separators below 10,000, then 12.3K and 1.2M. */
    public static function total(float $value): string
    {
        $v = round($value);
        if (abs($v) < 10_000) {
            return number_format($v);
        }
        foreach ([1e12 => 'T', 1e9 => 'B', 1e6 => 'M', 1e3 => 'K'] as $size => $suffix) {
            if (abs($v) >= $size) {
                $short = round($v / $size, 1);
                // 999,950 rounds to 1000K, which Intl writes as 1M.
                if (abs($short) >= 1000 && $suffix !== 'T') {
                    continue;
                }

                return rtrim(rtrim(number_format($short, 1, '.', ''), '0'), '.').$suffix;
            }
        }

        return number_format($v);
    }

    /**
     * Each team's colour: the start script's, or Coilbox's chart palette by
     * team order when the script gives none or gives every team the same one
     * (`colorSeries`), so teams can be told apart.
     */
    private static function coloured($teams)
    {
        $useGame = $teams->every(fn ($t) => $t->colour !== null) && ($teams->count() === 1 || $teams->pluck('colour')->unique()->count() > 1);
        $slot = 0;
        foreach ($teams as $team) {
            if (! $useGame) {
                $team->colour = self::PALETTE[$slot % count(self::PALETTE)];
            }
            $slot++;
        }

        return $teams;
    }

    /** `teamResultLabel`. */
    private static function result(array $winners): string
    {
        sort($winners);

        return $winners === [] ? 'Nobody won' : self::andList(array_map(fn ($a) => 'Team '.($a + 1), $winners), ', ').' won';
    }

    /** @param list<string> $names */
    private static function andList(array $names, ?string $glue = null): string
    {
        if ($glue !== null || count($names) < 2) {
            return implode($glue ?? '', $names);
        }

        return implode(', ', array_slice($names, 0, -1)).' and '.$names[array_key_last($names)];
    }

    /**
     * The roster: one side per ally team, each seat with its team's figures.
     *
     * @return list<array<string, mixed>>
     */
    private static function sides($players, $teams, array $winners, array $factions, int $duration): array
    {
        $sides = [];
        foreach ($players as $seat) {
            $ally = $seat->ally_team === null ? null : (int) $seat->ally_team;
            $key = $ally ?? -1;
            $sides[$key] ??= ['label' => $ally === null ? 'Unassigned' : 'Team '.($ally + 1), 'won' => in_array($ally, $winners, true), 'seats' => []];
            $team = $seat->team === null ? null : ($teams[(int) $seat->team] ?? null);
            $figures = [];
            foreach (array_keys(self::ROSTER) as $column) {
                $figures[$column] = $team?->{$column} === null ? null : (float) $team->{$column};
            }
            $sides[$key]['seats'][] = [
                'name' => $seat->name,
                'ai' => $seat->kind === 'ai',
                'colour' => $team?->colour,
                // A start unit the catalogue gives no faction reads better by the name the game shows for it.
                'faction' => $factions[$seat->faction ?? ''] ?? ($seat->faction ?: null),
                'won' => (bool) $seat->won,
                // Coilbox's `apm`: commands a minute over the match.
                'apm' => $seat->commands === null || $duration <= 0 ? null : (int) round($seat->commands * 60 / $duration),
                'figures' => $figures,
            ];
        }
        ksort($sides);

        return array_values($sides);
    }

    /**
     * `MatchStatsSection`'s headline tiles. The damage and units tiles need the
     * teams' final totals.
     *
     * @return list<array{label: string, icon: string, value: string, sub: ?string}>
     */
    private static function tiles($teams, $players, int $duration, string $result): array
    {
        $bots = $players->where('kind', 'ai')->count();
        $tiles = [
            ['label' => 'Duration', 'icon' => 'clock', 'value' => self::duration($duration), 'sub' => null],
            ['label' => 'Result', 'icon' => 'trophy', 'value' => $result, 'sub' => null],
            ['label' => 'Players', 'icon' => 'users', 'value' => (string) $players->count(), 'sub' => $bots === 0 ? null : ($bots === 1 ? '1 of them bots' : $bots.' of them bots')],
        ];
        $measured = $teams->filter(fn ($t) => $t->damage_dealt !== null);
        if ($measured->isNotEmpty()) {
            $across = $measured->count() === 1 ? 'one team' : 'across '.$measured->count().' teams';
            $tiles[] = ['label' => 'Damage dealt', 'icon' => 'swords', 'value' => self::total($measured->sum(fn ($t) => (float) $t->damage_dealt)), 'sub' => $across];
            $tiles[] = ['label' => 'Units built', 'icon' => 'factory', 'value' => self::total($measured->sum(fn ($t) => (float) $t->units_produced)), 'sub' => $across];
        }

        return $tiles;
    }

    /**
     * Beyond All Reason's recorded awards, places in the order the game gave
     * them. An award nobody won is left out.
     *
     * @return list<array{title: string, places: list<array{place: int, team: string, value: ?string}>}>
     */
    private static function awards(array $recorded, callable $teamName): array
    {
        $out = [];
        foreach ($recorded as $award) {
            if (($award['team'] ?? null) === null) {
                continue;
            }
            $name = (string) $award['name'];
            $out[$name] ??= ['title' => self::AWARDS[$name] ?? $name, 'places' => []];
            $value = $award['value'];
            $out[$name]['places'][] = [
                'place' => count($out[$name]['places']) + 1,
                'team' => $teamName((int) $award['team']),
                'value' => $value === null || $name === 'cow' ? null : ($name === 'resourceEfficiency' ? number_format((float) $value, 2) : self::total((float) $value)),
            ];
        }

        return array_values($out);
    }

    /**
     * Each team's statistics over time for the Economy tab, or null when the
     * replay holds only final totals.
     *
     * @return array{frames: int, teams: list<array{name: string, colour: ?string, series: array<string, list<array{0: int, 1: float}>>}>}|null
     */
    private static function history($teams, callable $teamName): ?array
    {
        $out = [];
        $last = 0;
        foreach ($teams as $team) {
            $history = json_decode((string) $team->history, true);
            if (! is_array($history)) {
                continue;
            }
            $series = [];
            foreach (array_keys(self::CHARTS) as $column) {
                $series[$column] = array_map(null, $history['frame'], $history[$column]);
            }
            $last = max($last, (int) end($history['frame']));
            $out[] = ['name' => $teamName((int) $team->team), 'colour' => $team->colour, 'series' => $series];
        }

        return $out === [] ? null : ['frames' => $last, 'teams' => $out];
    }

    /**
     * The summary's units by unit def id, each with the name to show and its
     * cost. A unit with neither cost is not priced.
     *
     * @return array<int, array{label: string, metal: float, energy: float, priced: bool, category: ?string}>
     */
    private static function units(?string $game, array $units): array
    {
        $names = array_values(array_filter(array_map(fn ($u) => $u['name'] ?? null, $units)));
        $full = $game === null ? [] : (ReplayList::unitNames([(object) ['game' => $game]], $names)[$game] ?? []);
        $out = [];
        foreach ($units as $u) {
            $metal = (float) ($u['metal'] ?? 0);
            $energy = (float) ($u['energy'] ?? 0);
            $out[(int) $u['unit_def_id']] = [
                'label' => $u['display_name'] ?? $full[$u['name']] ?? $u['name'],
                'metal' => $metal, 'energy' => $energy, 'priced' => $metal > 0 || $energy > 0,
                'category' => $u['category'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Each team's build orders, with repeats of one unit folded into one entry
     * as `collapseOrders` folds them.
     *
     * @return list<array{team: int, name: string, colour: ?string, orders: list<array{frame: int, unit_def_id: int}>}>
     */
    private static function builds($teams, callable $teamName): array
    {
        $out = [];
        foreach ($teams as $team) {
            $orders = json_decode((string) $team->build_orders, true) ?: [];
            if ($orders === []) {
                continue;
            }
            $out[] = ['team' => (int) $team->team, 'name' => $teamName((int) $team->team), 'colour' => $team->colour, 'orders' => $orders];
        }

        return $out;
    }

    /**
     * Orders up to a minute of match time, folded into entries, with what they
     * cost by kind of unit (`ReplayOpeningSplit`). Null minutes is every order.
     *
     * @param  list<array{frame: int, unit_def_id: int}>  $orders
     * @param  array<int, array{label: string, metal: float, energy: float, priced: bool, category: ?string}>  $units
     * @return array{entries: list<array{time: string, label: string, count: int}>, metal: float, energy: float, unpriced: int, split: array<string, array{metal: float, energy: float}>}
     */
    public static function opening(array $orders, array $units, ?float $minutes): array
    {
        if ($minutes !== null) {
            $last = $minutes * 60 * self::FRAMES_PER_SECOND;
            $orders = array_values(array_filter($orders, fn ($o) => $o['frame'] <= $last));
        }
        $entries = [];
        $split = array_fill_keys(['economy', 'defence', 'offence', 'other', 'unclassified'], ['metal' => 0.0, 'energy' => 0.0]);
        $metal = $energy = 0.0;
        $unpriced = 0;
        $previous = null;
        foreach ($orders as $order) {
            $id = (int) $order['unit_def_id'];
            if ($previous === $id) {
                $entries[array_key_last($entries)]['count']++;
            } else {
                $entries[] = ['time' => self::time((int) $order['frame']), 'label' => $units[$id]['label'] ?? 'Unit '.$id, 'count' => 1];
            }
            $previous = $id;
            $unit = $units[$id] ?? null;
            if ($unit === null || ! $unit['priced']) {
                $unpriced++;

                continue;
            }
            $bucket = in_array($unit['category'], ['economy', 'defence', 'offence', 'unclassified'], true) ? $unit['category']
                : ($unit['category'] === null ? 'unclassified' : 'other');
            $split[$bucket]['metal'] += $unit['metal'];
            $split[$bucket]['energy'] += $unit['energy'];
            $metal += $unit['metal'];
            $energy += $unit['energy'];
        }

        return ['entries' => $entries, 'metal' => $metal, 'energy' => $energy, 'unpriced' => $unpriced, 'split' => $split];
    }

    /**
     * Events and chat in match order, as `ReplayEventList` lists them. Chat a
     * moderator hid is left out, and private messages were never kept.
     *
     * @return list<array{frame: int, time: string, kind: string, text: string}>
     */
    private static function timeline(string $gameId, array $events, $seats, callable $teamName): array
    {
        $names = [];
        foreach ($seats as $seat) {
            if ($seat->kind !== 'ai') {
                $names[(int) $seat->number] = $seat->name;
            }
        }
        $who = fn (int $player) => $names[$player] ?? 'Player '.$player;
        $rows = [];
        $over = false;
        foreach ($events as $i => $e) {
            // Every player's engine sends its own game over. One says it.
            if ($e['type'] === Event::GAME_OVER && $over) {
                continue;
            }
            $over = $over || $e['type'] === Event::GAME_OVER;
            $name = $who((int) $e['player']);
            $text = match ($e['type']) {
                Event::PAUSE => $name.(($e['data']['paused'] ?? true) ? ' paused the game' : ' unpaused the game'),
                Event::PLAYER_LEFT => $name.match ($e['data']['reason'] ?? null) {
                    'lost_connection' => ' lost connection', 'kicked' => ' was kicked', default => ' left',
                },
                Event::RESIGN => $name.' resigned',
                Event::GIVE_AWAY => $name.' gave units to '.$teamName((int) ($e['data']['to_team'] ?? -1)),
                Event::TEAM_DIED => $teamName((int) ($e['data']['team'] ?? -1)).' died',
                Event::GAME_OVER => 'Game over. '.self::result($e['data']['winning_ally_teams'] ?? []),
                default => null,
            };
            if ($text !== null) {
                $rows[] = ['frame' => (int) $e['frame'], 'order' => [0, $i], 'kind' => 'Player', 'text' => $text];
            }
        }
        $chat = DB::table('replay_chat')->where('game_id', $gameId)->whereNull('hidden_at')->orderBy('seq')->get();
        foreach ($chat as $m) {
            $to = match ($m->audience) {
                ChatMessage::ALLIES => ' to allies', ChatMessage::SPECTATORS => ' to spectators', default => '',
            };
            $rows[] = ['frame' => (int) $m->frame, 'order' => [1, (int) $m->seq], 'kind' => 'Chat', 'text' => ($m->name !== '' ? $m->name : 'Player '.$m->player).$to.': '.$m->text];
        }
        usort($rows, fn ($a, $b) => [$a['frame'], $a['order']] <=> [$b['frame'], $b['order']]);

        return array_map(fn ($r) => ['frame' => $r['frame'], 'time' => self::time($r['frame']), 'kind' => $r['kind'], 'text' => $r['text']], $rows);
    }

    /**
     * `ReplayMapCard` in its heat mode: the minimap with start boxes, start
     * positions and order heat. Start positions need the map's size, so a map
     * the catalogue does not publish shows the boxes only.
     *
     * @return array<string, mixed>
     */
    private static function mapCard(object $row, ?array $map, $teams, callable $teamName): array
    {
        $boxes = [];
        $allyColours = [];
        foreach ($teams as $team) {
            if ($team->ally_team !== null) {
                $allyColours[(int) $team->ally_team] ??= $team->colour;
            }
        }
        foreach (json_decode((string) $row->start_boxes, true) ?: [] as $box) {
            $boxes[] = $box + ['colour' => $allyColours[$box['ally_team']] ?? '#94a3b8', 'label' => (string) ($box['ally_team'] + 1)];
        }
        $starts = [];
        if ($map !== null) {
            foreach ($teams as $team) {
                if ($team->start_x !== null && $team->start_z !== null) {
                    $starts[] = [
                        'left' => (float) $team->start_x / $map['width'] * 100, 'top' => (float) $team->start_z / $map['height'] * 100,
                        'colour' => $team->colour ?? '#94a3b8', 'name' => $teamName((int) $team->team),
                    ];
                }
            }
        }
        $heat = json_decode((string) $row->order_heat, true);
        $legend = null;
        if (is_array($heat) && $heat['cells'] !== []) {
            // The blur is the slow part of a page view, and the heat never changes.
            $legend = HubCache::remember('replays:heat-legend:'.md5((string) $row->order_heat), [], HubCache::WEEKS, function () use ($heat) {
                $field = HeatPicture::field($heat);

                return ['within' => $field['peak_within_radius'], 'radius' => (int) round($field['radius'])];
            });
        }

        return [
            'map' => $map,
            'picture' => $map['picture'] ?? ReplayList::placeholder((string) $row->map),
            'boxes' => $boxes,
            'starts' => $starts,
            'heat' => $legend === null ? null : '/replays/'.$row->game_id.'/heat.png?v='.substr(md5((string) $row->order_heat), 0, 8),
            'legend' => $legend,
        ];
    }

    /**
     * Where to download the replay. Every mirror copies a listed replay, but
     * the hub only knows the one it was uploaded to holds it, so this is null
     * while that mirror is off or unhealthy, as for tracks.
     */
    private static function replayUrl(object $row): ?string
    {
        $base = DB::table('mirror')->where('id', $row->upload_mirror_id)->where('enabled', true)->where('healthy', true)->value('base_url');

        return $base === null ? null : rtrim((string) $base, '/').'/replays/'.$row->game_id.'.sdfz';
    }

    /**
     * The order heat for the picture route, or null when the replay has none.
     *
     * @return array<string, mixed>|null
     */
    public static function heat(string $gameId): ?array
    {
        if (! self::isGameId($gameId)) {
            return null;
        }
        $heat = json_decode((string) DB::table('replay')->where('game_id', $gameId)->where('state', 'listed')->value('order_heat'), true);

        return is_array($heat) && $heat['cells'] !== [] ? $heat : null;
    }
}
