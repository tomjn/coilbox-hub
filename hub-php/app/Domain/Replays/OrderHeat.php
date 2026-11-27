<?php

namespace App\Domain\Replays;

use App\Domain\Replays\Stream\Command;

/**
 * Where a match's orders were aimed, counted on the grid Coilbox's heat map
 * uses, so the hub's map card matches the app's. The grid is
 * `crates/tauri-plugin-coilbox-content/src/demo/map_grids.rs` and which orders
 * have a place is `demo/orders.rs`, both in Coilbox.
 *
 * Counts are kept for the whole match and every team together, which is what
 * the page draws. The grid needs the map's size, which the replay does not
 * hold, so it comes from the map catalogue.
 */
final class OrderHeat
{
    /** Cells along the map's longer side, `GRID_RESOLUTION`. */
    public const RESOLUTION = 256;

    private const CMD_INSERT = 1;

    /**
     * Each command's forms: [how many parameters, exactly or at least, where
     * the point starts or null for a unit]. A command not listed here and not
     * in {@see NO_TARGET} is one the engine does not define.
     */
    private const RULES = [
        10 => [[3, false, 0]],                              // move
        15 => [[3, false, 0]],                              // patrol
        16 => [[1, true, null], [3, false, 0]],             // fight
        20 => [[1, true, null], [3, false, 0]],             // attack
        21 => [[3, false, 0]],                              // area attack
        105 => [[1, true, null], [3, false, 0]],            // manual fire
        25 => [[1, true, null]],                            // guard
        40 => [[1, true, null], [4, true, 0], [5, true, 1]], // repair
        90 => [[1, true, null], [4, true, 0], [5, true, 1]], // reclaim
        130 => [[1, true, null], [4, true, 0], [5, true, 1]], // capture
        125 => [[1, true, null], [4, true, 0]],             // resurrect
        110 => [[4, true, 0]],                              // restore
        75 => [[1, true, null], [4, true, 0]],              // load units
        76 => [[1, true, null]],                            // load onto
        80 => [[4, false, 0]],                              // unload units
        81 => [[3, false, 0]],                              // unload unit
    ];

    /**
     * The grid for a map this size in elmos, or null for a map with no size.
     *
     * @return array{width: int, height: int, world_width: int, world_height: int}|null
     */
    public static function grid(int $worldWidth, int $worldHeight): ?array
    {
        if ($worldWidth <= 0 || $worldHeight <= 0) {
            return null;
        }
        $longest = (float) max($worldWidth, $worldHeight);
        $side = fn (int $world) => (int) max(1.0, round(self::RESOLUTION * $world / $longest));

        return ['width' => $side($worldWidth), 'height' => $side($worldHeight), 'world_width' => $worldWidth, 'world_height' => $worldHeight];
    }

    /**
     * The grid with a count for every cell an order was aimed into, as
     * `cells: [[cell, count], ...]` in cell order. A cell is the row times the
     * width plus the column, from the north west corner.
     *
     * @param  array{width: int, height: int, world_width: int, world_height: int}  $grid
     * @return array{width: int, height: int, world_width: int, world_height: int, cells: list<array{0: int, 1: int}>}
     */
    public static function of(Replay $replay, array $grid): array
    {
        $counts = [];
        foreach ($replay->stream->orders as $order) {
            foreach ($order->commands as $command) {
                $point = self::aim($command);
                $cell = $point === null ? null : self::cell($grid, $point[0], $point[1]);
                if ($cell !== null) {
                    $counts[$cell] = ($counts[$cell] ?? 0) + 1;
                }
            }
        }
        ksort($counts);
        $cells = [];
        foreach ($counts as $cell => $count) {
            $cells[] = [$cell, $count];
        }

        return $grid + ['cells' => $cells];
    }

    /**
     * Where a command is aimed on the map as [x, z], or null when it has no
     * place. An insert is read as the command it wraps.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function aim(Command $command): ?array
    {
        $id = $command->id;
        $params = $command->params;
        if ($id === self::CMD_INSERT) {
            if (count($params) < 3 || (int) $params[1] === self::CMD_INSERT) {
                return null;
            }
            $id = (int) $params[1];
            $params = array_slice($params, 3);
        }
        if ($id < 0) {
            // Three or more parameters are a position. Fewer are a factory queue order.
            return self::place($params, 0);
        }
        foreach (self::RULES[$id] ?? [] as [$want, $exactly, $from]) {
            if ($exactly ? count($params) === $want : count($params) >= $want) {
                return $from === null ? null : self::place($params, $from);
            }
        }

        return null;
    }

    /** @param list<float> $params @return array{0: float, 1: float}|null */
    private static function place(array $params, int $from): ?array
    {
        if (count($params) < $from + 3) {
            return null;
        }
        // The stream holds 32 bit floats, so a value that is not a number reads as NAN or INF.
        [$x, $z] = [$params[$from], $params[$from + 2]];

        return is_finite($x) && is_finite($z) ? [$x, $z] : null;
    }

    /**
     * `Grid::cell`. A point off the map is dropped, not moved to the edge.
     *
     * @param  array{width: int, height: int, world_width: int, world_height: int}  $grid
     */
    public static function cell(array $grid, float $x, float $z): ?int
    {
        $w = (float) $grid['world_width'];
        $h = (float) $grid['world_height'];
        if (! ($x >= 0 && $z >= 0 && $x <= $w && $z <= $h)) {
            return null;
        }
        $col = min((int) ($x / $w * $grid['width']), $grid['width'] - 1);
        $row = min((int) ($z / $h * $grid['height']), $grid['height'] - 1);

        return $row * $grid['width'] + $col;
    }
}
