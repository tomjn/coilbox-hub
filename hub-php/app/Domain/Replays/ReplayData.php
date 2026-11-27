<?php

namespace App\Domain\Replays;

use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * The listed replays as CSV files anyone may download and analyse (#672):
 * one file of matches, one of the people and AIs in each match, and one of
 * build orders. `replays:data` writes a new set each week into a folder named
 * by its date, and `/replays/data` links them and describes each column from
 * {@see columns()}, so the page and the files cannot drift apart.
 *
 * Chat is left out, and so is every replay not in state `listed`, such as
 * those a moderator removed (#671).
 */
final class ReplayData
{
    /** How many weekly sets are kept. A choice: a month of sets lets somebody repeat a recent analysis. */
    public const KEEP = 4;

    /** Each file's name without `.csv`, with what one row is. */
    public const FILES = [
        'matches' => 'One row for each match.',
        'players' => 'One row for each player, spectator and AI in a match.',
        'build_orders' => 'One row for each build order a team gave, in the order given.',
    ];

    /** A week's folder name, the date the set was written. */
    public const WEEK = '/^\d{4}-\d{2}-\d{2}$/';

    /** `GAME_SPEED` in the engine. */
    private const FRAMES_PER_SECOND = 30;

    /** Replays read at a time, to keep memory flat as the list grows. */
    private const CHUNK = 200;

    /** What each team total is, for the players file. */
    private const TOTALS = [
        'metal_used' => 'Metal the team spent',
        'energy_used' => 'Energy the team spent',
        'metal_produced' => 'Metal the team made',
        'energy_produced' => 'Energy the team made',
        'metal_excess' => 'Metal the team lost to full storage',
        'energy_excess' => 'Energy the team lost to full storage',
        'metal_received' => 'Metal allies gave the team',
        'energy_received' => 'Energy allies gave the team',
        'metal_sent' => 'Metal the team gave allies',
        'energy_sent' => 'Energy the team gave allies',
        'damage_dealt' => 'Damage the team dealt',
        'damage_received' => 'Damage the team took',
        'units_produced' => 'Units the team built',
        'units_died' => 'Units the team lost',
        'units_received' => 'Units allies gave the team',
        'units_sent' => 'Units the team gave allies',
        'units_captured' => 'Units the team captured',
        'units_out_captured' => 'Units captured from the team',
        'units_killed' => 'Enemy units the team killed',
    ];

    /**
     * Every column of each file, in order, with what it holds.
     *
     * @return array<string, array<string, string>>
     */
    public static function columns(): array
    {
        $totals = [];
        foreach (self::TOTALS as $key => $what) {
            $totals['team_'.$key] = $what.' over the whole match, from the engine\'s last statistics sample. Empty when the engine took none.';
        }

        return [
            'matches' => [
                'game_id' => 'The match\'s id from the replay file, as 32 hexadecimal digits. The other files use it too. The replay\'s page is /replays/ followed by it.',
                'game' => 'The game\'s name, such as Beyond All Reason.',
                'game_version' => 'The game\'s version. Empty when the hub could not split it from the name.',
                'map' => 'The map\'s name, as the start script gives it.',
                'engine' => 'The engine version.',
                'started_at' => 'When the match started, in UTC, as YYYY-MM-DD HH:MM:SS.',
                'duration_seconds' => 'How long the match lasted, in game seconds. 0 when it had no game over.',
                'player_count' => 'How many players took part, not counting spectators or AIs.',
                'spectator_count' => 'How many spectators watched.',
                'ai_count' => 'How many AIs played.',
                'winning_ally_teams' => 'The ally team numbers that won, separated by spaces. Empty when nobody won.',
                'analysed' => 'true when an analysis run of the replay passed the hub\'s check and the hub has worked out its figures, else false. The analysis columns in players.csv are empty when this is false.',
                'analysis_logger_version' => 'The version of Coilbox\'s replay logger that made the analysis. Empty when there is none.',
            ],
            'players' => [
                'game_id' => 'The match, as in matches.csv.',
                'kind' => 'player, spectator or ai.',
                'number' => 'The start script\'s number for them. Players and spectators share one set of numbers, AIs have their own.',
                'name' => 'Their name, as they typed it.',
                'team' => 'The team they played on. Empty for a spectator.',
                'ally_team' => 'The ally team their team was in. Empty for a spectator.',
                'faction' => 'What they played. Usually the faction\'s name or its starting unit\'s name, as the game records it. Empty when the replay does not say.',
                'won' => 'true when their ally team won, else false. Empty for a spectator.',
                'commands' => 'Commands they gave, from the replay\'s end of game figures. Empty for AIs, spectators and when the engine took no figures.',
                'unit_commands' => 'Commands they gave to units. Empty as for commands.',
                'mouse_pixels' => 'How far their mouse moved, in screen pixels. Empty as for commands.',
                'mouse_clicks' => 'How many times they clicked. Empty as for commands.',
                'key_presses' => 'How many keys they pressed. Empty as for commands.',
                'team_start_x' => 'Where their team started, in the engine\'s map units from the map\'s left edge. Empty when the replay does not say. Players on one team share the team columns.',
                'team_start_z' => 'Where their team started, in map units from the map\'s top edge.',
            ] + $totals + [
                'team_buildings_finished' => 'Buildings the team finished, from the analysis. Empty when the match has no analysis.',
                'team_units_finished' => 'Mobile units the team finished, from the analysis.',
                'team_other_finished' => 'Things the team finished that the analysis could not tell were buildings or units.',
                'team_kills' => 'Enemy units the team destroyed, from the analysis.',
                'team_losses' => 'Units the team lost, from the analysis.',
            ],
            'build_orders' => [
                'game_id' => 'The match, as in matches.csv.',
                'team' => 'The team that gave the order, as in players.csv.',
                'position' => 'The order\'s place in the team\'s list, from 1.',
                'frame' => 'The game frame when the order was given. The game runs 30 frames each second.',
                'seconds' => 'The same moment in game seconds.',
                'unit_def_id' => 'The engine\'s number for the unit ordered, which is only the same within one match.',
                'unit_name' => 'The unit\'s internal name, such as armcom. Empty when the replay does not list it.',
            ],
        ];
    }

    /** Where the weekly folders live, under the storage each release shares. */
    public static function root(): string
    {
        return storage_path('app/replay-data');
    }

    /**
     * Writes a set into the folder for $week, replacing one written earlier
     * that day, then deletes all but the newest {@see KEEP} sets.
     *
     * @return array<string, int> rows in each file
     */
    public static function write(string $week): array
    {
        if (! preg_match(self::WEEK, $week)) {
            throw new LogicException("Not a week: {$week}");
        }
        self::folder(self::root());
        // Written aside, then renamed, so a download never gets half a file.
        $partial = self::root().'/.'.$week.'.partial';
        self::remove($partial);
        self::folder($partial);

        $columns = self::columns();
        $out = [];
        foreach (array_keys(self::FILES) as $file) {
            $handle = fopen("{$partial}/{$file}.csv", 'w');
            if ($handle === false) {
                throw new RuntimeException("Cannot write {$partial}/{$file}.csv");
            }
            self::line($handle, array_keys($columns[$file]));
            $out[$file] = $handle;
        }
        $rows = array_fill_keys(array_keys(self::FILES), 0);

        DB::table('replay')->where('state', 'listed')->orderBy('game_id')
            ->select(['game_id', 'game', 'game_version', 'map', 'engine', 'started_at', 'duration', 'winners', 'summary'])
            ->chunk(self::CHUNK, function ($replays) use ($out, $columns, &$rows) {
                foreach (self::chunkRows($replays) as $file => $list) {
                    foreach ($list as $row) {
                        if (array_keys($row) !== array_keys($columns[$file])) {
                            throw new LogicException("A {$file} row does not match its columns.");
                        }
                        self::line($out[$file], array_values($row));
                        $rows[$file]++;
                    }
                }
            });

        $files = [];
        foreach ($out as $file => $handle) {
            fclose($handle);
            chmod("{$partial}/{$file}.csv", 0664);
            $files[$file] = ['rows' => $rows[$file], 'bytes' => filesize("{$partial}/{$file}.csv")];
        }
        file_put_contents("{$partial}/manifest.json", json_encode(['written_at' => gmdate('Y-m-d\TH:i:s\Z'), 'files' => $files], JSON_PRETTY_PRINT));
        chmod("{$partial}/manifest.json", 0664);

        $final = self::root().'/'.$week;
        $old = self::root().'/.'.$week.'.old';
        self::remove($old);
        if (is_dir($final)) {
            rename($final, $old);
        }
        rename($partial, $final);
        self::remove($old);

        foreach (array_slice(array_column(self::weeks(), 'week'), self::KEEP) as $stale) {
            self::remove(self::root().'/'.$stale);
        }

        return $rows;
    }

    /**
     * The sets on disk, newest first, with each file's rows and size.
     *
     * @return list<array{week: string, written_at: ?string, files: array<string, array{rows: int, bytes: int}>}>
     */
    public static function weeks(): array
    {
        $weeks = [];
        foreach (is_dir(self::root()) ? scandir(self::root()) : [] as $name) {
            $manifest = self::root()."/{$name}/manifest.json";
            if (! preg_match(self::WEEK, $name) || ! is_file($manifest)) {
                continue;
            }
            $read = json_decode((string) file_get_contents($manifest), true);
            $weeks[] = ['week' => $name, 'written_at' => $read['written_at'] ?? null, 'files' => $read['files'] ?? []];
        }
        usort($weeks, fn ($a, $b) => strcmp($b['week'], $a['week']));

        return $weeks;
    }

    /** The file to send for a download, `latest` meaning the newest set, or null when there is none. */
    public static function path(string $week, string $file): ?string
    {
        if (! isset(self::FILES[$file])) {
            return null;
        }
        if ($week === 'latest') {
            $week = self::weeks()[0]['week'] ?? '';
        }
        if (! preg_match(self::WEEK, $week)) {
            return null;
        }
        $path = self::root()."/{$week}/{$file}.csv";

        return is_file($path) ? $path : null;
    }

    /**
     * The three files' rows for one chunk of replays.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function chunkRows($replays): array
    {
        $ids = $replays->pluck('game_id')->all();
        $seats = DB::table('replay_player')->whereIn('game_id', $ids)
            ->orderBy('game_id')->orderByRaw("case kind when 'player' then 0 when 'ai' then 1 else 2 end")->orderBy('number')
            ->get()->groupBy('game_id');
        $teams = DB::table('replay_team')->whereIn('game_id', $ids)->orderBy('team')
            ->get(array_merge(['game_id', 'team', 'start_x', 'start_z', 'build_orders'], array_keys(self::TOTALS)))
            ->groupBy('game_id');
        $analyses = DB::table('replay_analysis')->whereIn('game_id', $ids)->get(['game_id', 'logger_version', 'facts'])->keyBy('game_id');

        $out = ['matches' => [], 'players' => [], 'build_orders' => []];
        foreach ($replays as $replay) {
            $id = $replay->game_id;
            $mySeats = $seats->get($id, collect());
            $myTeams = $teams->get($id, collect())->keyBy(fn ($t) => (int) $t->team);
            $analysis = $analyses->get($id);
            $facts = $analysis === null ? null : json_decode((string) $analysis->facts, true);
            // Facts in another shape are read as none, as the replay page reads them.
            $facts = is_array($facts) && ($facts['v'] ?? null) === AnalysisFacts::VERSION ? $facts : null;
            $counted = [];
            foreach ($facts['teams'] ?? [] as $t) {
                $counted[(int) $t['team']] = $t;
            }
            $winners = json_decode((string) $replay->winners, true);

            $out['matches'][] = [
                'game_id' => $id,
                'game' => $replay->game,
                'game_version' => $replay->game_version,
                'map' => $replay->map,
                'engine' => $replay->engine,
                'started_at' => $replay->started_at === null ? null : substr((string) $replay->started_at, 0, 19),
                'duration_seconds' => $replay->duration,
                'player_count' => $mySeats->where('kind', 'player')->count(),
                'spectator_count' => $mySeats->where('kind', 'spectator')->count(),
                'ai_count' => $mySeats->where('kind', 'ai')->count(),
                'winning_ally_teams' => implode(' ', is_array($winners) ? $winners : []),
                'analysed' => $facts !== null,
                'analysis_logger_version' => $facts === null ? null : $analysis->logger_version,
            ];

            foreach ($mySeats as $seat) {
                $team = $seat->kind === 'spectator' || $seat->team === null ? null : $myTeams->get((int) $seat->team);
                $count = $team === null || $facts === null ? null : ($counted[(int) $team->team] ?? null);
                $row = [
                    'game_id' => $id,
                    'kind' => $seat->kind,
                    'number' => $seat->number,
                    'name' => $seat->name,
                    'team' => $seat->kind === 'spectator' ? null : $seat->team,
                    'ally_team' => $seat->kind === 'spectator' ? null : $seat->ally_team,
                    'faction' => $seat->faction,
                    'won' => $seat->won === null ? null : (bool) $seat->won,
                    'commands' => $seat->commands,
                    'unit_commands' => $seat->unit_commands,
                    'mouse_pixels' => $seat->mouse_pixels,
                    'mouse_clicks' => $seat->mouse_clicks,
                    'key_presses' => $seat->key_presses,
                    'team_start_x' => $team?->start_x,
                    'team_start_z' => $team?->start_z,
                ];
                foreach (array_keys(self::TOTALS) as $key) {
                    $row['team_'.$key] = $team?->{$key};
                }
                $row += [
                    'team_buildings_finished' => $count['buildings'] ?? null,
                    'team_units_finished' => $count['units'] ?? null,
                    'team_other_finished' => $count['unknown'] ?? null,
                    'team_kills' => $count['kills'] ?? null,
                    'team_losses' => $count['losses'] ?? null,
                ];
                $out['players'][] = $row;
            }

            $summary = json_decode((string) $replay->summary, true);
            $names = [];
            foreach (is_array($summary) && is_array($summary['units'] ?? null) ? $summary['units'] : [] as $unit) {
                if (is_int($unit['unit_def_id'] ?? null) && is_string($unit['name'] ?? null)) {
                    $names[$unit['unit_def_id']] = $unit['name'];
                }
            }
            foreach ($myTeams as $number => $team) {
                $orders = json_decode((string) $team->build_orders, true);
                foreach (is_array($orders) ? array_values($orders) : [] as $i => $order) {
                    $frame = (int) ($order['frame'] ?? 0);
                    $def = (int) ($order['unit_def_id'] ?? 0);
                    $out['build_orders'][] = [
                        'game_id' => $id,
                        'team' => $number,
                        'position' => $i + 1,
                        'frame' => $frame,
                        'seconds' => round($frame / self::FRAMES_PER_SECOND, 2),
                        'unit_def_id' => $def,
                        'unit_name' => $names[$def] ?? null,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * One CSV line as RFC 4180 has it: no backslash escapes, quotes doubled.
     * Null is an empty field, and true and false are written as words, which
     * DuckDB reads as booleans.
     *
     * @param  resource  $handle
     * @param  list<mixed>  $values
     */
    private static function line($handle, array $values): void
    {
        $fields = array_map(fn ($v) => match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'true' : 'false',
            // Whole numbers without a fraction, as MariaDB and SQLite give them differently.
            is_float($v) && floor($v) === $v && abs($v) < 1e15 => (string) (int) $v,
            // The shortest form that reads back as the same number.
            is_float($v) => json_encode($v),
            default => (string) $v,
        }, $values);
        if (fputcsv($handle, $fields, ',', '"', '', "\n") === false) {
            throw new RuntimeException('Cannot write a CSV line.');
        }
    }

    /** Made group writable: the scheduler runs as www-data, an owner by hand as the deploy user. */
    private static function folder(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException("Cannot make {$path}");
        }
        @chmod($path, 0775);
    }

    private static function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                unlink("{$path}/{$name}");
            }
        }
        rmdir($path);
    }
}
