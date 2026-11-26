<?php

namespace App\Domain\Replays;

/**
 * What Coilbox worked out from a replay, sent when it starts an upload. The
 * shape is in docs/replay-uploads.md. This only checks the shape. #663 checks
 * the values against the file.
 */
final class ReplaySummary
{
    /** The final statistics sample of a team, as `TeamSample` names them. */
    public const TOTALS = [
        'metal_used', 'energy_used', 'metal_produced', 'energy_produced', 'metal_excess', 'energy_excess',
        'metal_received', 'energy_received', 'metal_sent', 'energy_sent', 'damage_dealt', 'damage_received',
        'units_produced', 'units_died', 'units_received', 'units_sent', 'units_captured', 'units_out_captured', 'units_killed',
    ];

    /**
     * Answers the summary as sent, or a short message saying what is wrong.
     *
     * @return array<string, mixed>|string
     */
    public static function parse(mixed $summary): array|string
    {
        if (! is_array($summary) || ($summary !== [] && array_is_list($summary))) {
            return 'summary must be a JSON object.';
        }
        if (($summary['v'] ?? null) !== 1) {
            return 'summary.v must be 1.';
        }
        foreach (['engine', 'game', 'map'] as $key) {
            if (! self::text($summary[$key] ?? null)) {
                return 'summary.'.$key.' must be 1 to 255 characters.';
            }
        }
        if (! is_int($summary['started_at'] ?? null)) {
            return 'summary.started_at must be a whole number of Unix seconds.';
        }
        if (! is_int($summary['duration'] ?? null) || $summary['duration'] < 0) {
            return 'summary.duration must be a whole number of game seconds, at least 0.';
        }

        $error = self::players($summary['players'] ?? null)
            ?? self::spectators($summary['spectators'] ?? null)
            ?? self::winners($summary['winners'] ?? null)
            ?? self::totals($summary['totals'] ?? null)
            ?? self::startPositions($summary['start_positions'] ?? null)
            ?? self::units($summary['units'] ?? null)
            ?? self::buildOrders($summary['build_orders'] ?? null, $summary['units'] ?? []);

        return $error ?? $summary;
    }

    /**
     * Whether the name is a player or a spectator in a parsed summary.
     *
     * @param  array<string, mixed>  $summary
     */
    public static function names(array $summary, string $name): bool
    {
        return in_array($name, array_column($summary['players'], 'name'), true)
            || in_array($name, $summary['spectators'], true);
    }

    private static function players(mixed $players): ?string
    {
        if (! self::list($players) || $players === []) {
            return 'summary.players must be a list with at least one player.';
        }
        foreach ($players as $i => $player) {
            if (! is_array($player) || ! self::text($player['name'] ?? null) || ! self::seat($player['team'] ?? null)
                || ! self::seat($player['ally_team'] ?? null)
                || (($player['faction'] ?? null) !== null && ! self::text($player['faction']))) {
                return 'summary.players['.$i.'] needs a name, a team, an ally_team and a faction that is text or null.';
            }
        }

        return null;
    }

    private static function spectators(mixed $spectators): ?string
    {
        if (! self::list($spectators)) {
            return 'summary.spectators must be a list of names.';
        }
        foreach ($spectators as $name) {
            if (! self::text($name)) {
                return 'summary.spectators must be a list of names.';
            }
        }

        return null;
    }

    private static function winners(mixed $winners): ?string
    {
        if (! self::list($winners)) {
            return 'summary.winners must be a list of ally team numbers.';
        }
        foreach ($winners as $allyTeam) {
            if (! self::seat($allyTeam)) {
                return 'summary.winners must be a list of ally team numbers.';
            }
        }

        return null;
    }

    private static function totals(mixed $totals): ?string
    {
        if (! self::list($totals)) {
            return 'summary.totals must be a list with one entry for each team.';
        }
        foreach ($totals as $i => $team) {
            if (! is_array($team) || ! self::seat($team['team'] ?? null)) {
                return 'summary.totals['.$i.'] needs a team.';
            }
            foreach (self::TOTALS as $key) {
                if (! is_int($team[$key] ?? null) && ! is_float($team[$key] ?? null)) {
                    return 'summary.totals['.$i.'].'.$key.' must be a number.';
                }
            }
        }

        return null;
    }

    private static function startPositions(mixed $positions): ?string
    {
        if (! self::list($positions)) {
            return 'summary.start_positions must be a list.';
        }
        foreach ($positions as $i => $position) {
            if (! is_array($position) || ! self::seat($position['team'] ?? null)
                || ! self::number($position['x'] ?? null) || ! self::number($position['z'] ?? null)) {
                return 'summary.start_positions['.$i.'] needs a team, an x and a z.';
            }
        }

        return null;
    }

    private static function units(mixed $units): ?string
    {
        if (! self::list($units)) {
            return 'summary.units must be a list.';
        }
        $ids = [];
        foreach ($units as $i => $unit) {
            if (! is_array($unit) || ! is_int($unit['unit_def_id'] ?? null) || $unit['unit_def_id'] < 1
                || ! self::text($unit['name'] ?? null)
                || (($unit['display_name'] ?? null) !== null && ! self::text($unit['display_name']))
                || ! self::number($unit['metal'] ?? null) || ! self::number($unit['energy'] ?? null)
                || (($unit['build_time'] ?? null) !== null && ! self::number($unit['build_time']))
                || (($unit['category'] ?? null) !== null && ! self::text($unit['category']))) {
                return 'summary.units['.$i.'] needs a unit_def_id, a name, metal and energy costs, and a display_name, build_time and category that may be null.';
            }
            if (isset($ids[$unit['unit_def_id']])) {
                return 'summary.units lists unit_def_id '.$unit['unit_def_id'].' twice.';
            }
            $ids[$unit['unit_def_id']] = true;
        }

        return null;
    }

    /** @param  mixed  $units  already checked by units() */
    private static function buildOrders(mixed $buildOrders, mixed $units): ?string
    {
        if (! self::list($buildOrders)) {
            return 'summary.build_orders must be a list with one entry for each team.';
        }
        $known = array_flip(array_column($units, 'unit_def_id'));
        foreach ($buildOrders as $i => $team) {
            if (! is_array($team) || ! self::seat($team['team'] ?? null) || ! self::list($team['orders'] ?? null)) {
                return 'summary.build_orders['.$i.'] needs a team and a list of orders.';
            }
            foreach ($team['orders'] as $j => $order) {
                if (! is_array($order) || ! is_int($order['frame'] ?? null) || ! is_int($order['unit_def_id'] ?? null)) {
                    return 'summary.build_orders['.$i.'].orders['.$j.'] needs a frame and a unit_def_id.';
                }
                if (! isset($known[$order['unit_def_id']])) {
                    return 'summary.build_orders['.$i.'].orders['.$j.'] names unit_def_id '.$order['unit_def_id'].', which summary.units does not list.';
                }
            }
        }

        return null;
    }

    private static function list(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    private static function text(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= 255;
    }

    private static function seat(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    private static function number(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
