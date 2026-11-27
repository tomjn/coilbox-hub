<?php

namespace App\Domain\Replays;

use Illuminate\Support\Facades\DB;

/**
 * What the replay page (#668) draws from a kept analysis, worked out once from
 * its log when {@see ReplayAnalyses} keeps it. Each part follows Coilbox's own
 * reading of the log, in `src/content`:
 *
 * - deaths and damage on the heat grid, `replayEventLayers.ts` and `replayDamage.ts`
 * - each team's living unit value over time, `replayLivingValue.ts`
 * - finished buildings and units, kills and losses per team
 * - units finished and metal destroyed by each kind of unit, for the game's
 *   page (#670)
 * - awards for every game, `replayAwards.ts` (tomjn/coilbox#5492)
 *
 * Coilbox keeps the engine's unit list apart from the log it sends, so a unit
 * is priced from the replay's summary, which lists the units the match
 * ordered, and the game catalogue fills in what the summary leaves out. A
 * unit neither knows is counted as not priced.
 */
final class AnalysisFacts
{
    /** The shape stored. A page reading another treats the analysis as having none. */
    public const VERSION = 1;

    /** `GAME_SPEED` in the engine. */
    private const FRAMES_PER_SECOND = 30;

    /** The living value's sample period when the replay holds no statistics history. A choice for the chart, not the engine's. */
    private const PERIOD_SECONDS = 30;

    /** `SPLIT_BUCKETS`, in Coilbox's order. */
    public const BUCKETS = ['economy', 'defence', 'offence', 'other', 'unclassified'];

    /** How many places the three kill awards give. */
    private const PODIUM = 3;

    /** The order two events on one frame are read in for the awards, `AWARD_KINDS`. */
    private const AWARD_KINDS = ['game_start', 'unit_created', 'unit_finished', 'unit_given', 'unit_destroyed', 'game_over'];

    /**
     * @param  object  $replay  a `replay` row
     * @param  list<object>  $teams  its `replay_team` rows
     * @return array<string, mixed>
     */
    public static function of(AnalysisLog $log, object $replay, array $teams): array
    {
        $lines = $log->lines;
        $header = self::first($lines, 'header') ?? [];
        $over = self::first($lines, 'game_over') ?? [];
        $endFrame = self::num($over['frame'] ?? null) ?? 0;
        $units = self::units($replay);
        $allies = [];
        foreach ($teams as $team) {
            if ($team->ally_team !== null) {
                $allies[(int) $team->team] = (int) $team->ally_team;
            }
        }
        $grid = OrderHeat::grid((int) ($header['mapSizeX'] ?? 0), (int) ($header['mapSizeZ'] ?? 0));

        return [
            'v' => self::VERSION,
            'grid' => $grid,
            'deaths' => $grid === null ? [] : self::deaths($lines, $grid),
            'damage' => $grid === null ? [] : self::damage($lines, $header, $grid),
            'living' => self::living($lines, $units, self::period($teams), (int) $endFrame),
            'teams' => self::perTeam($lines, $units, $allies),
            'awards' => self::awards($lines, $units, $allies),
            // Added within version 1, so facts kept earlier lack it. `replays:game-totals` fills it in.
            'by_unit' => self::byUnit($lines, $units, $allies),
        ];
    }

    /**
     * Each unit the replay's summary lists, by unit def id: its cost, which
     * kind of unit it is and whether it moves, null when nothing says.
     *
     * @return array<int, array{name: ?string, metal: float, energy: float, priced: bool, bucket: string, category: ?string, mobile: ?bool}>
     */
    public static function units(object $replay): array
    {
        $summary = json_decode((string) $replay->summary, true);
        $listed = is_array($summary) && is_array($summary['units'] ?? null) ? $summary['units'] : [];
        $names = array_values(array_unique(array_filter(array_map(fn ($u) => is_string($u['name'] ?? null) ? $u['name'] : null, $listed))));
        $stats = [];
        if ($names !== [] && is_string($replay->game ?? null)) {
            $found = DB::table('game as g')->join('game_unit as u', 'u.game_id', '=', 'g.id')
                ->whereNull('g.hidden_at')->whereNull('u.removed_at')->where('g.display_name', $replay->game)->whereIn('u.unit_name', $names)
                ->get(['u.unit_name', 'u.stats']);
            foreach ($found as $unit) {
                $stats[$unit->unit_name] = json_decode((string) $unit->stats, true) ?: [];
            }
        }
        $out = [];
        foreach ($listed as $u) {
            if (! is_int($u['unit_def_id'] ?? null)) {
                continue;
            }
            $known = $stats[$u['name'] ?? ''] ?? [];
            $metal = (float) ($u['metal'] ?? 0);
            $energy = (float) ($u['energy'] ?? 0);
            if ($metal <= 0 && $energy <= 0) {
                $metal = max(0.0, (float) (self::num($known['metalCost'] ?? null) ?? 0));
                $energy = max(0.0, (float) (self::num($known['energyCost'] ?? null) ?? 0));
            }
            $category = is_string($u['category'] ?? null) ? $u['category'] : null;
            $speed = self::num($known['maxVelocity'] ?? null);
            $out[$u['unit_def_id']] = [
                'name' => is_string($u['name'] ?? null) ? $u['name'] : null,
                'metal' => $metal, 'energy' => $energy, 'priced' => $metal > 0 || $energy > 0,
                'bucket' => in_array($category, ['economy', 'defence', 'offence', 'unclassified'], true) ? $category : ($category === null ? 'unclassified' : 'other'),
                'category' => $category,
                // The catalogue's speed says best. Some kinds of unit only ever stand or only ever move.
                'mobile' => $speed !== null ? $speed > 0 : match ($category) {
                    'offence', 'transport' => true, 'defence', 'factory' => false, default => null,
                },
            ];
        }

        return $out;
    }

    /**
     * Where units died, one count a death, as `[[cell, count], ...]` in cell order.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array{width: int, height: int, world_width: int, world_height: int}  $grid
     * @return list<array{0: int, 1: int}>
     */
    private static function deaths(array $lines, array $grid): array
    {
        $counts = [];
        foreach ($lines as $line) {
            if ($line['kind'] !== 'unit_destroyed') {
                continue;
            }
            $x = self::num($line['x'] ?? null);
            $z = self::num($line['z'] ?? null);
            $cell = $x === null || $z === null ? null : OrderHeat::cell($grid, $x, $z);
            if ($cell !== null) {
                $counts[$cell] = ($counts[$cell] ?? 0) + 1;
            }
        }

        return self::cells($counts);
    }

    /**
     * Where damage landed, summed over the match. The logger adds damage up
     * on its own grid, so a log whose grid is not this map's has none.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $header
     * @param  array{width: int, height: int, world_width: int, world_height: int}  $grid
     * @return list<array{0: int, 1: float}>
     */
    private static function damage(array $lines, array $header, array $grid): array
    {
        if (($header['gridWidth'] ?? null) !== $grid['width'] || ($header['gridHeight'] ?? null) !== $grid['height']) {
            return [];
        }
        $size = $grid['width'] * $grid['height'];
        $sums = [];
        foreach ($lines as $line) {
            $at = $line['kind'] === 'damage' && is_array($line['at'] ?? null) ? $line['at'] : [];
            for ($i = 0; $i + 1 < count($at); $i += 2) {
                [$cell, $amount] = [$at[$i], $at[$i + 1]];
                if (is_int($cell) && $cell >= 0 && $cell < $size && self::num($amount) > 0) {
                    $sums[$cell] = ($sums[$cell] ?? 0.0) + (float) $amount;
                }
            }
        }

        return self::cells($sums);
    }

    /** @param array<int, int|float> $counts @return list<array{0: int, 1: int|float}> */
    private static function cells(array $counts): array
    {
        ksort($counts);
        $out = [];
        foreach ($counts as $cell => $count) {
            $out[] = [$cell, is_float($count) ? round($count, 2) : $count];
        }

        return $out;
    }

    /** The statistics history's sample period in seconds, as Coilbox's chart uses the trailer's. @param list<object> $teams */
    private static function period(array $teams): int
    {
        foreach ($teams as $team) {
            $frames = json_decode((string) ($team->history ?? ''), true)['frame'] ?? [];
            if (count($frames) >= 2 && $frames[1] > $frames[0]) {
                return max(1, (int) round(($frames[1] - $frames[0]) / self::FRAMES_PER_SECOND));
            }
        }

        return self::PERIOD_SECONDS;
    }

    /**
     * `livingValue`: what each team's finished units were worth, every period
     * from zero to the last whole period before the game ended. Each sample is
     * metal then energy for each of {@see BUCKETS}, rounded to whole numbers.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<int, array<string, mixed>>  $units
     * @return array{period: int, seconds: list<int>, teams: list<array{team: int, samples: list<list<int>>}>, finished: int, unpriced: int}
     */
    private static function living(array $lines, array $units, int $period, int $endFrame): array
    {
        $alive = [];
        $now = [];
        $series = [];
        $seconds = [];
        $finished = $unpriced = 0;
        $empty = array_fill(0, count(self::BUCKETS) * 2, 0.0);
        $slot = array_flip(self::BUCKETS);
        $add = function (array $unit, int $sign) use (&$now, &$series, &$seconds, $empty, $slot) {
            if (! isset($now[$unit['team']])) {
                $now[$unit['team']] = $empty;
                // A team first seen part way through was worth nothing before then.
                $series[$unit['team']] = array_fill(0, count($seconds), $empty);
            }
            $now[$unit['team']][$slot[$unit['bucket']] * 2] += $sign * $unit['metal'];
            $now[$unit['team']][$slot[$unit['bucket']] * 2 + 1] += $sign * $unit['energy'];
        };
        $snapshot = function (int $frame) use (&$now, &$series, &$seconds) {
            $seconds[] = intdiv($frame, self::FRAMES_PER_SECOND);
            foreach ($now as $team => $sample) {
                $series[$team][] = $sample;
            }
        };

        $step = $period * self::FRAMES_PER_SECOND;
        $next = 0;
        $ordered = $lines;
        usort($ordered, fn ($a, $b) => (self::num($a['frame'] ?? null) ?? 0) <=> (self::num($b['frame'] ?? null) ?? 0));
        foreach ($ordered as $line) {
            $frame = self::num($line['frame'] ?? null);
            $id = self::num($line['unit'] ?? null);
            if ($frame === null || $id === null) {
                continue;
            }
            while ($next < $frame && $next <= $endFrame) {
                $snapshot($next);
                $next += $step;
            }
            $team = self::num($line['team'] ?? null);
            if ($line['kind'] === 'unit_finished') {
                if ($team === null || isset($alive[$id])) {
                    continue;
                }
                $finished++;
                $unit = $units[(int) ($line['def'] ?? -1)] ?? null;
                if ($unit === null || ! $unit['priced']) {
                    $unpriced++;

                    continue;
                }
                $alive[$id] = ['team' => (int) $team, 'bucket' => $unit['bucket'], 'metal' => $unit['metal'], 'energy' => $unit['energy']];
                $add($alive[$id], 1);
            } elseif ($line['kind'] === 'unit_destroyed' && isset($alive[$id])) {
                $add($alive[$id], -1);
                unset($alive[$id]);
            } elseif ($line['kind'] === 'unit_given' && isset($alive[$id]) && $team !== null) {
                $add($alive[$id], -1);
                $alive[$id]['team'] = (int) $team;
                $add($alive[$id], 1);
            }
        }
        while ($next <= $endFrame) {
            $snapshot($next);
            $next += $step;
        }

        ksort($series);
        $teams = [];
        foreach ($series as $team => $samples) {
            // Sums of costs drift by a float's last digits as units come and go.
            $teams[] = ['team' => $team, 'samples' => array_map(fn ($s) => array_map(fn ($v) => max(0, (int) round($v)), $s), $samples)];
        }

        return ['period' => $period, 'seconds' => $seconds, 'teams' => $teams, 'finished' => $finished, 'unpriced' => $unpriced];
    }

    /**
     * Each team's finished buildings and units, kills and losses. A unit is
     * counted once when it is finished. A building is a unit that does not
     * move, and a unit nothing says moves or not is counted apart. A kill is
     * an enemy unit the team destroyed, and a loss is any of its own units
     * destroyed, finished or not.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<int, array<string, mixed>>  $units
     * @param  array<int, int>  $allies
     * @return list<array{team: int, buildings: int, units: int, unknown: int, kills: int, losses: int}>
     */
    private static function perTeam(array $lines, array $units, array $allies): array
    {
        $out = [];
        foreach (array_keys($allies) as $team) {
            $out[$team] = ['team' => $team, 'buildings' => 0, 'units' => 0, 'unknown' => 0, 'kills' => 0, 'losses' => 0];
        }
        $seen = [];
        foreach ($lines as $line) {
            $team = self::num($line['team'] ?? null);
            if ($team === null || ! isset($out[(int) $team])) {
                continue;
            }
            $team = (int) $team;
            if ($line['kind'] === 'unit_finished' && ! isset($seen[$line['unit'] ?? null])) {
                $seen[$line['unit'] ?? null] = true;
                $mobile = $units[(int) ($line['def'] ?? -1)]['mobile'] ?? null;
                $out[$team][$mobile === null ? 'unknown' : ($mobile ? 'units' : 'buildings')]++;
            } elseif ($line['kind'] === 'unit_destroyed') {
                unset($seen[$line['unit'] ?? null]);
                $out[$team]['losses']++;
                $by = self::num($line['attackerTeam'] ?? null);
                if ($by !== null && isset($out[(int) $by]) && $allies[(int) $by] !== $allies[$team]) {
                    $out[(int) $by]['kills']++;
                }
            }
        }
        ksort($out);

        return array_values($out);
    }

    /**
     * What each team's units did, by unit name: how many it finished, and the
     * metal each of its units destroyed. A kill counts as for the awards, a
     * finished enemy unit with a price. Start units were not built, so they are
     * not finished here. Only units the summary lists have a name, and the rest
     * are added up apart.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<int, array<string, mixed>>  $units
     * @param  array<int, int>  $allies
     * @return list<array{team: int, finished: list<array{0: string, 1: int}>, kills: list<array{0: string, 1: float}>, unnamed_finished: int, unnamed_kills: float}>
     */
    private static function byUnit(array $lines, array $units, array $allies): array
    {
        $out = [];
        foreach (array_keys($allies) as $team) {
            $out[$team] = ['team' => $team, 'finished' => [], 'kills' => [], 'unnamed_finished' => 0, 'unnamed_kills' => 0.0];
        }
        $finished = [];
        $rank = array_flip(self::AWARD_KINDS);
        usort($lines, fn ($a, $b) => [(self::num($a['frame'] ?? null) ?? 0), $rank[$a['kind']] ?? -1] <=> [(self::num($b['frame'] ?? null) ?? 0), $rank[$b['kind']] ?? -1]);
        foreach ($lines as $line) {
            $id = self::num($line['unit'] ?? null);
            $team = self::num($line['team'] ?? null);
            if ($id === null || $team === null || ! isset($out[(int) $team])) {
                continue;
            }
            [$id, $team] = [(int) $id, (int) $team];
            if ($line['kind'] === 'unit_created') {
                unset($finished[$id]);
            } elseif ($line['kind'] === 'unit_finished' && ! isset($finished[$id])) {
                $finished[$id] = true;
                if (($line['startUnit'] ?? false) === true) {
                    continue;
                }
                $name = $units[(int) ($line['def'] ?? -1)]['name'] ?? null;
                if ($name === null) {
                    $out[$team]['unnamed_finished']++;
                } else {
                    $out[$team]['finished'][$name] = ($out[$team]['finished'][$name] ?? 0) + 1;
                }
            } elseif ($line['kind'] === 'unit_destroyed') {
                $wasFinished = isset($finished[$id]);
                unset($finished[$id]);
                $by = self::num($line['attackerTeam'] ?? null);
                $metal = $units[(int) ($line['def'] ?? -1)]['metal'] ?? 0.0;
                if (! $wasFinished || $metal <= 0 || $by === null || ! isset($out[(int) $by]) || $allies[(int) $by] === $allies[$team]) {
                    continue;
                }
                $by = (int) $by;
                $name = $units[(int) ($line['attackerDef'] ?? -1)]['name'] ?? null;
                if ($name === null) {
                    $out[$by]['unnamed_kills'] += $metal;
                } else {
                    $out[$by]['kills'][$name] = ($out[$by]['kills'][$name] ?? 0.0) + $metal;
                }
            }
        }
        ksort($out);
        // Pairs, since a unit name made of digits would not stay a text key.
        $pairs = fn (array $by) => array_map(fn ($name, $amount) => [(string) $name, $amount], array_keys($by), array_values($by));

        return array_values(array_map(fn ($t) => ['finished' => $pairs($t['finished']), 'kills' => $pairs($t['kills'])] + $t, $out));
    }

    /**
     * `matchAwards`. Awards go to teams in the match, so Gaia wins nothing and
     * destroying its units is not a kill. Every amount is in metal.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<int, array<string, mixed>>  $units
     * @param  array<int, int>  $allies
     * @return array{awards: list<array{id: string, places: list<array{place: int, team: int, score: float|null}>}>, unpriced: int}
     */
    private static function awards(array $lines, array $units, array $allies): array
    {
        $over = self::first($lines, 'game_over');
        $endFrame = self::num($over['frame'] ?? null);
        if ($over === null || $endFrame === null) {
            return ['awards' => [], 'unpriced' => 0];
        }
        $startFrame = self::num(self::first($lines, 'game_start')['frame'] ?? null) ?? 0;

        $economy = $fighting = $killed = [];
        $watches = [];
        $watch = function (int $team) use (&$watches, $startFrame) {
            $watches[$team] ??= ['since' => $startFrame, 'longest' => 0, 'held' => 0, 'emptied' => null];
        };
        $owner = [];
        $finished = [];
        $release = function (int $unit, $frame) use (&$owner, &$watches, $watch) {
            if (! isset($owner[$unit])) {
                return;
            }
            $team = $owner[$unit];
            unset($owner[$unit]);
            $watch($team);
            if (--$watches[$team]['held'] === 0) {
                $watches[$team]['emptied'] = $frame;
            }
        };
        $hold = function (int $unit, int $team, $frame) use (&$owner, &$watches, $watch, $release) {
            if (($owner[$unit] ?? null) === $team) {
                return;
            }
            $release($unit, $frame);
            $owner[$unit] = $team;
            $watch($team);
            $watches[$team]['held']++;
            $watches[$team]['emptied'] = null;
        };

        $unpriced = 0;
        $rank = array_flip(self::AWARD_KINDS);
        $ordered = $lines;
        usort($ordered, fn ($a, $b) => [(self::num($a['frame'] ?? null) ?? 0), $rank[$a['kind']] ?? -1] <=> [(self::num($b['frame'] ?? null) ?? 0), $rank[$b['kind']] ?? -1]);
        foreach ($ordered as $line) {
            $frame = self::num($line['frame'] ?? null);
            $id = self::num($line['unit'] ?? null);
            $team = self::num($line['team'] ?? null);
            if ($frame === null || $id === null || $team === null) {
                continue;
            }
            [$id, $team] = [(int) $id, (int) $team];
            if ($line['kind'] === 'unit_created') {
                unset($finished[$id]);
                $hold($id, $team, $frame);
            } elseif ($line['kind'] === 'unit_finished') {
                $finished[$id] = true;
                $hold($id, $team, $frame);
            } elseif ($line['kind'] === 'unit_given') {
                $hold($id, $team, $frame);
            } elseif ($line['kind'] === 'unit_destroyed') {
                $wasFinished = isset($finished[$id]);
                unset($finished[$id]);
                $release($id, $frame);

                $by = self::num($line['attackerTeam'] ?? null);
                if ($by === null || ! isset($allies[$team], $allies[(int) $by]) || $allies[$team] === $allies[(int) $by]) {
                    continue;
                }
                $by = (int) $by;
                // A shot that lands after a team is knocked out ends no wait.
                $watch($by);
                if ($watches[$by]['emptied'] === null) {
                    $watches[$by]['longest'] = max($watches[$by]['longest'], $frame - $watches[$by]['since']);
                    $watches[$by]['since'] = $frame;
                }
                // A unit still being built has not cost its price yet.
                if (! $wasFinished) {
                    continue;
                }
                $unit = $units[(int) ($line['def'] ?? -1)] ?? null;
                if ($unit === null || ! $unit['priced']) {
                    $unpriced++;

                    continue;
                }
                $killed[$by] = ($killed[$by] ?? 0) + $unit['metal'];
                if ($unit['category'] === 'economy' && $unit['mobile'] !== true) {
                    $economy[$by] = ($economy[$by] ?? 0) + $unit['metal'];
                }
                if (in_array($unit['category'], ['offence', 'defence'], true)) {
                    $fighting[$by] = ($fighting[$by] ?? 0) + $unit['metal'];
                }
            }
        }

        $produced = $taken = $efficiency = [];
        foreach (is_array($over['teams'] ?? null) ? $over['teams'] : [] as $total) {
            $team = is_array($total) ? self::num($total['team'] ?? null) : null;
            if ($team === null || ! isset($allies[(int) $team])) {
                continue;
            }
            $team = (int) $team;
            $produced[$team] = self::num($total['metalProduced'] ?? null) ?? 0;
            $taken[$team] = self::num($total['damageReceived'] ?? null) ?? 0;
            $spent = self::num($total['metalUsed'] ?? null) ?? 0;
            if ($spent > 0) {
                $efficiency[$team] = ($killed[$team] ?? 0) / $spent;
            }
        }
        // A team that lost every unit stops waiting for a kill on that frame,
        // or the first team knocked out would win by sitting out the rest.
        $quiet = [];
        foreach ($watches as $team => $w) {
            if (isset($allies[$team])) {
                $quiet[$team] = max($w['longest'], ($w['emptied'] ?? $endFrame) - $w['since']) / self::FRAMES_PER_SECOND;
            }
        }

        $every = [
            ['id' => 'economyKilled', 'places' => self::ranked($economy, self::PODIUM)],
            ['id' => 'fightingKilled', 'places' => self::ranked($fighting, self::PODIUM)],
            ['id' => 'efficiency', 'places' => self::ranked($efficiency, self::PODIUM)],
            ['id' => 'produced', 'places' => self::ranked($produced, 1)],
            ['id' => 'damageTaken', 'places' => self::ranked($taken, 1)],
            ['id' => 'noKill', 'places' => self::ranked($quiet, 1)],
        ];
        $awards = array_values(array_filter($every, fn ($a) => $a['places'] !== []));
        // Winning everything takes first place alone in all six.
        $firsts = array_map(fn ($a) => array_values(array_filter($a['places'], fn ($p) => $p['place'] === 1)), $every);
        $sweeper = $firsts[0][0]['team'] ?? null;
        if ($sweeper !== null && array_filter($firsts, fn ($f) => count($f) !== 1 || $f[0]['team'] !== $sweeper) === []) {
            $awards[] = ['id' => 'everything', 'places' => [['place' => 1, 'team' => $sweeper, 'score' => null]]];
        }

        return ['awards' => $awards, 'unpriced' => $unpriced];
    }

    /**
     * The teams with a score above nothing, best first, down to `$places`.
     * Teams with the same score share a place.
     *
     * @param  array<int, int|float>  $scores
     * @return list<array{place: int, team: int, score: float}>
     */
    private static function ranked(array $scores, int $places): array
    {
        $earned = array_filter($scores, fn ($s) => $s > 0);
        uksort($earned, fn ($a, $b) => [$earned[$b], $a] <=> [$earned[$a], $b]);
        $out = [];
        foreach ($earned as $team => $score) {
            $place = 1 + count(array_filter($earned, fn ($other) => $other > $score));
            if ($place <= $places) {
                $out[] = ['place' => $place, 'team' => $team, 'score' => (float) $score];
            }
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $lines @return array<string, mixed>|null */
    private static function first(array $lines, string $kind): ?array
    {
        foreach ($lines as $line) {
            if ($line['kind'] === $kind) {
                return $line;
            }
        }

        return null;
    }

    private static function num(mixed $value): int|float|null
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? $value : null;
    }
}
