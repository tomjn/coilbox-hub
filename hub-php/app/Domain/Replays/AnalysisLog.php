<?php

namespace App\Domain\Replays;

/**
 * A run's `events.jsonl` from Coilbox's replay logger, and the check that it
 * played the match the replay recorded (#664). The rules are Coilbox's own, in
 * `demo/analysis/divergence.rs`: the winners, the game's length, the teams,
 * each team's sample count and final totals, and the logger's unit lines
 * against the engine's unit counts. Coilbox also counts the engine's desync
 * warnings, which are in the engine's output and not the log.
 */
final class AnalysisLog
{
    /** The log format the logger writes and this reader reads. */
    public const FORMAT = 1;

    /** `GAME_SPEED` in the engine. */
    private const FRAMES_PER_SECOND = 30;

    private const FLOATS = ['metalUsed', 'energyUsed', 'metalProduced', 'energyProduced', 'metalExcess', 'energyExcess',
        'metalReceived', 'energyReceived', 'metalSent', 'energySent', 'damageDealt', 'damageReceived'];

    private const COUNTS = ['unitsProduced', 'unitsDied', 'unitsReceived', 'unitsSent', 'unitsCaptured', 'unitsOutCaptured', 'unitsKilled'];

    /** @param list<array<string, mixed>> $lines */
    private function __construct(public readonly array $lines) {}

    /** Reads the log, or says why it will not. */
    public static function parse(string $events): self|string
    {
        $lines = [];
        foreach (explode("\n", rtrim($events, "\n")) as $i => $text) {
            $line = json_decode($text, true);
            if (! is_array($line) || array_is_list($line) || ! is_string($line['kind'] ?? null)) {
                return 'Line '.($i + 1).' is not a JSON object with a kind.';
            }
            $lines[] = $line;
        }

        return $lines === [] ? 'The log is empty.' : new self($lines);
    }

    /** @return array<string, int> lines of each kind */
    public function counts(): array
    {
        return array_count_values(array_column($this->lines, 'kind'));
    }

    /**
     * The first way the run and the replay differ, or null when it matches.
     *
     * @param  object  $replay  a `replay` row
     * @param  list<object>  $teams  its `replay_team` rows
     * @return array{0: string, 1: string}|null the reason and detail
     */
    public function difference(object $replay, array $teams): ?array
    {
        $header = $this->first('header');
        if ($header === null) {
            return ['no_header', 'The log has no header line, so the logger never started.'];
        }
        if (($header['format'] ?? null) !== self::FORMAT) {
            return ['unknown_format', 'The log is format '.json_encode($header['format'] ?? null).', and the hub reads format '.self::FORMAT.'.'];
        }
        if (($header['map'] ?? null) !== $replay->map) {
            return ['wrong_map', 'The run was on '.json_encode($header['map'] ?? null).', and the replay on "'.$replay->map.'".'];
        }
        $over = $this->first('game_over');
        if ($over === null) {
            return ['no_game_over', 'The log has no game over line, so the run did not finish.'];
        }

        $recorded = json_decode((string) $replay->winners, true) ?? [];
        $observed = is_array($over['winners'] ?? null) ? $over['winners'] : [];
        sort($recorded);
        sort($observed);
        if ($recorded !== $observed) {
            return ['outcome_mismatch', 'The run was won by ally teams ['.implode(', ', $observed).'], and the replay by ['.implode(', ', $recorded).'].'];
        }
        $seconds = intdiv((int) ($over['frame'] ?? 0), self::FRAMES_PER_SECOND);
        if ($seconds !== (int) $replay->duration) {
            return ['outcome_mismatch', "The run lasted {$seconds} game seconds, and the replay {$replay->duration}."];
        }
        $ran = [];
        foreach (is_array($over['teams'] ?? null) ? $over['teams'] : [] as $team) {
            if (is_array($team) && is_int($team['team'] ?? null)) {
                $ran[$team['team']] = $team;
            }
        }
        if (count($ran) !== count($teams)) {
            return ['outcome_mismatch', 'The run ended with '.count($ran).' teams, and the replay with '.count($teams).'.'];
        }

        $lines = $this->unitLines(is_array($over['dying'] ?? null) ? $over['dying'] : []);
        foreach ($teams as $row) {
            $id = (int) $row->team;
            if ($row->units_produced === null) {
                return ['no_totals', "The replay has no final totals for team {$id} to check the run against."];
            }
            $team = $ran[$id] ?? null;
            if ($team === null) {
                return ['outcome_mismatch', "The run has no totals for team {$id}."];
            }
            if ($row->samples !== null && ($team['samples'] ?? null) !== (int) $row->samples) {
                return ['total_mismatch', "Team {$id} has ".json_encode($team['samples'] ?? null)." samples in the run and {$row->samples} in the replay."];
            }
            foreach (self::FLOATS as $figure) {
                $want = (float) $row->{self::column($figure)};
                $got = $team[$figure] ?? null;
                if ((! is_int($got) && ! is_float($got)) || ! self::sameFloat($want, (float) $got)) {
                    return ['total_mismatch', "Team {$id}'s {$figure} is ".json_encode(is_int($got) || is_float($got) ? self::float32($got) : $got).' in the run and '.json_encode(self::float32($want)).' in the replay.'];
                }
            }
            foreach (self::COUNTS as $figure) {
                $want = (int) $row->{self::column($figure)};
                if (($team[$figure] ?? null) !== $want) {
                    return ['total_mismatch', "Team {$id}'s {$figure} is ".json_encode($team[$figure] ?? null)." in the run and {$want} in the replay."];
                }
            }
            // The logger itself: one line per unit made, lost, given or captured.
            foreach ([
                'unitsProduced' => 'unit_created', 'unitsDied' => 'unit_destroyed', 'unitsReceived' => 'received',
                'unitsSent' => 'sent', 'unitsCaptured' => 'captured', 'unitsOutCaptured' => 'out_captured',
            ] as $figure => $kind) {
                $want = (int) $row->{self::column($figure)};
                $got = $lines[$kind][$id] ?? 0;
                if ($got !== $want) {
                    return ['count_mismatch', "The log has {$got} {$kind} lines for team {$id}, and the replay's {$figure} is {$want}."];
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function first(string $kind): ?array
    {
        foreach ($this->lines as $line) {
            if ($line['kind'] === $kind) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Unit lines by kind and team. A unit still dying at game over has its
     * death line, and the engine counts it only once it removes the unit, so
     * that line is left out. Its line is the last with its id, as the engine
     * can hand an id out again.
     *
     * @param  list<mixed>  $dying
     * @return array<string, array<int, int>>
     */
    private function unitLines(array $dying): array
    {
        $lastDeath = [];
        foreach ($this->lines as $i => $line) {
            if ($line['kind'] === 'unit_destroyed') {
                $lastDeath[$line['unit'] ?? null] = $i;
            }
        }
        $skip = [];
        foreach ($dying as $unit) {
            if (isset($lastDeath[$unit])) {
                $skip[$lastDeath[$unit]] = true;
            }
        }

        $counts = [];
        $add = function (string $kind, mixed $team) use (&$counts) {
            if (is_int($team)) {
                $counts[$kind][$team] = ($counts[$kind][$team] ?? 0) + 1;
            }
        };
        foreach ($this->lines as $i => $line) {
            $team = $line['team'] ?? null;
            match ($line['kind']) {
                'unit_created' => $add('unit_created', $team),
                'unit_destroyed' => isset($skip[$i]) ? null : $add('unit_destroyed', $team),
                'unit_given' => ($line['captured'] ?? null) === true
                    ? [$add('captured', $team), $add('out_captured', $line['from'] ?? null)]
                    : [$add('received', $team), $add('sent', $line['from'] ?? null)],
                default => null,
            };
        }

        return $counts;
    }

    /** `metalUsed` is the `metal_used` column. */
    private static function column(string $figure): string
    {
        return strtolower(preg_replace('/[A-Z]/', '_$0', $figure));
    }

    /** Both sides are the engine's 32 bit floats, and the logger writes enough digits to read back as the same one. */
    private static function sameFloat(float $a, float $b): bool
    {
        return pack('g', $a) === pack('g', $b) || (is_nan($a) && is_nan($b));
    }

    private static function float32(int|float $value): float
    {
        return unpack('g', pack('g', $value))[1];
    }
}
