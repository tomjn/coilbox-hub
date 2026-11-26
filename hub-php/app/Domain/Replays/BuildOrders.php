<?php

namespace App\Domain\Replays;

use App\Domain\Replays\Stream\Command;
use App\Domain\Replays\Stream\Event;
use App\Domain\Replays\Stream\Order;

/**
 * Each team's build orders, worked out the way Coilbox does in
 * `crates/tauri-plugin-coilbox-content/src/demo/build_orders.rs`, so the hub
 * gets the same list Coilbox sends in its summary.
 *
 * A build order is a command with a negative id, or one wrapped in
 * `CMD_INSERT`. A factory order with the right mouse bit takes units off the
 * queue and is no build. An order takes the team its player was on when it
 * arrived, and an AI's order its AI's team. A widget orders several builders
 * with one packet each in the same frame, and those count as one order.
 */
final class BuildOrders
{
    private const CMD_INSERT = 1;

    private const RIGHT_MOUSE_KEY = 1 << 4;

    private const SHIFT_KEY = 1 << 5;

    private const CONTROL_KEY = 1 << 6;

    private const ALT_KEY = 1 << 7;

    /**
     * @return array<int, list<array{frame: int, unit_def_id: int}>> by team, in the order given
     */
    public static function of(Replay $replay): array
    {
        $names = $teams = [];
        foreach ($replay->script->players as $player) {
            if (! $player->spectator && $player->team !== null) {
                $teams[$player->id] = $player->team;
            }
        }
        $events = array_values(array_filter($replay->stream->events,
            fn (Event $e) => $e->type === Event::NEW_PLAYER || $e->type === Event::JOIN_TEAM));
        $next = 0;

        // Every order so far as [frame, key], for finding a widget's repeat in the same frame.
        $seen = [];
        $out = [];
        foreach ($replay->stream->orders as $order) {
            for (; $next < count($events) && $events[$next]->at < $order->at; $next++) {
                $e = $events[$next];
                if ($e->type === Event::NEW_PLAYER && $e->data['spectator']) {
                    unset($teams[$e->player]);
                } else {
                    $teams[$e->player] = $e->data['team'];
                }
            }
            $team = $order->origin === Order::AI ? $order->team : ($teams[$order->player] ?? null);
            foreach ($order->commands as $command) {
                $build = self::build($command);
                if ($build === null) {
                    continue;
                }
                $key = json_encode([$order->player, $team, $order->origin, $order->ai, $order->team, $build]);
                if ($build['position'] !== null && $order->origin !== Order::SELECTION && self::repeat($seen, $order->frame, $key)) {
                    continue;
                }
                $seen[] = [$order->frame, $key];
                if ($team !== null) {
                    $out[$team][] = ['frame' => $order->frame, 'unit_def_id' => $build['unit_def_id']];
                }
            }
        }
        ksort($out);

        return $out;
    }

    /** @param  list<array{0: int, 1: string}>  $seen */
    private static function repeat(array $seen, int $frame, string $key): bool
    {
        for ($i = count($seen) - 1; $i >= 0 && $seen[$i][0] === $frame; $i--) {
            if ($seen[$i][1] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * The command as a build, or null when it is none.
     *
     * @return array{unit_def_id: int, position: ?string, facing: ?int, count: int, slot: string, options: int}|null
     */
    private static function build(Command $command): ?array
    {
        if ($command->id < 0) {
            $facts = self::facts($command->id, $command->options, $command->params);
            if ($facts['position'] === null) {
                if (($command->options & self::RIGHT_MOUSE_KEY) !== 0) {
                    return null;
                }
                if (($command->options & self::ALT_KEY) !== 0) {
                    $facts['slot'] = 'front';
                }
            } elseif (($command->options & self::SHIFT_KEY) === 0) {
                $facts['slot'] = 'replace';
            }

            return $facts;
        }
        if ($command->id !== self::CMD_INSERT || count($command->params) < 3) {
            return null;
        }
        [$place, $id, $options] = $command->params;
        $id = self::int($id, -0x80000000, 0x7FFFFFFF);
        if ($id >= 0) {
            return null;
        }
        $facts = self::facts($id, self::int($options, 0, 255), array_slice($command->params, 3));
        $facts['slot'] = ($command->options & self::ALT_KEY) !== 0
            ? 'at:'.self::int($place, -0x80000000, 0x7FFFFFFF)
            : 'tag:'.self::int($place, 0, 0xFFFFFFFF).':'.(int) (($command->options & self::RIGHT_MOUSE_KEY) !== 0);

        return $facts;
    }

    /**
     * @param  list<float>  $params
     * @return array{unit_def_id: int, position: ?string, facing: ?int, count: int, slot: string, options: int}
     */
    private static function facts(int $id, int $options, array $params): array
    {
        $position = count($params) >= 3 ? bin2hex(pack('g3', $params[0], $params[1], $params[2])) : null;
        $count = 1;
        if ($position === null) {
            $count *= ($options & self::SHIFT_KEY) !== 0 ? 5 : 1;
            $count *= ($options & self::CONTROL_KEY) !== 0 ? 20 : 1;
        }

        return [
            'unit_def_id' => $id === -0x80000000 ? 0x7FFFFFFF : -$id,
            'position' => $position,
            'facing' => count($params) === 4 ? abs(self::int($params[3], -0x80000000, 0x7FFFFFFF)) % 4 : null,
            'count' => $count,
            'slot' => 'append',
            'options' => $options,
        ];
    }

    /** A float cast to a whole number the way Rust's `as` does: towards zero, clamped, and NaN as 0. */
    private static function int(float $v, int $min, int $max): int
    {
        if (is_nan($v)) {
            return 0;
        }

        return (int) max($min, min($max, $v < 0 ? ceil($v) : floor($v)));
    }
}
