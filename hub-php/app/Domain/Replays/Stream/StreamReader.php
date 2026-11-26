<?php

namespace App\Domain\Replays\Stream;

use App\Domain\Replays\Lua\LuaDecoder;
use App\Domain\Replays\ReplayException;

/**
 * Walks the packet stream. Each packet is an `f32` game time, a `u32` length
 * and a payload whose first byte is the message type (`NETMSG_*` in the
 * engine's `NetMessageTypes.h`). A message type this reader does not use is
 * stepped over by its length.
 *
 * The payload layouts are the ones Coilbox's reader uses, which it checked
 * against real replays. A packet of a type this reader decodes that does not
 * fit its layout means the file is damaged, so the whole read fails.
 */
final class StreamReader
{
    private const KEYFRAME = 1;

    private const NEWFRAME = 2;

    private const PLAYERNAME = 6;

    private const CHAT = 7;

    private const COMMAND = 11;

    private const SELECT = 12;

    private const PAUSE = 13;

    private const AICOMMAND = 14;

    private const AICOMMANDS = 15;

    private const GAMEOVER = 30;

    private const SYSTEMMSG = 35;

    private const STARTPOS = 36;

    private const PLAYERLEFT = 39;

    private const LUAMSG = 50;

    private const TEAM = 51;

    private const CREATE_NEWPLAYER = 75;

    private const AICOMMAND_TRACKED = 76;

    /** `MAX_AIS` in the AI field of a command means a Lua widget sent it. */
    private const NO_AI = 255;

    private string $b;

    private int $frame = Stream::PREGAME;

    /** @var array<string, list<LuaDecoder>> by the first byte of their prefix */
    private array $decoders = [];

    /**
     * @param  list<LuaDecoder>  $decoders
     */
    public function __construct(array $decoders)
    {
        foreach ($decoders as $d) {
            $this->decoders[$d->prefix()[0]][] = $d;
        }
    }

    /**
     * Reads the stream that fills `$bytes` from `$start` to `$end`.
     *
     * @throws ReplayException
     */
    public function read(string $bytes, int $start, int $end): Stream
    {
        $this->b = $bytes;
        $this->frame = Stream::PREGAME;
        $orderAt = $orderFrame = $selections = $chat = $system = $starts = $events = $lua = [];
        $packets = $luaCount = 0;

        $at = $start;
        while ($at < $end) {
            if ($at + 8 > $end) {
                throw new ReplayException('This replay is damaged: its packet stream ends part way through a packet header.');
            }
            $len = unpack('V', $bytes, $at + 4)[1];
            $p = $at + 8;
            $at = $p + $len;
            if ($at > $end) {
                throw new ReplayException("This replay is damaged: a packet at byte {$p} claims {$len} bytes and the stream has ".($end - $p).' left.');
            }
            $packets++;
            if ($len === 0) {
                continue;
            }

            switch (ord($bytes[$p])) {
                case self::NEWFRAME:
                    $this->fixed($p, $len, 1);
                    $this->frame++;
                    break;
                case self::LUAMSG:
                    $luaCount++;
                    $this->sized16($p, $len, 7);
                    $first = $len > 7 ? $bytes[$p + 7] : null;
                    if ($first !== null && isset($this->decoders[$first])) {
                        $m = $this->lua($p, $len);
                        if ($m !== null) {
                            $lua[] = $m;
                        }
                    }
                    break;
                case self::KEYFRAME:
                    $this->fixed($p, $len, 5);
                    $this->frame = unpack('l', $bytes, $p + 1)[1];
                    break;
                case self::COMMAND:
                case self::AICOMMAND:
                case self::AICOMMAND_TRACKED:
                case self::AICOMMANDS:
                    // Decoded here to check it fits, then again each time the
                    // orders are read, because holding every order costs more
                    // memory than the bytes they come from.
                    $this->order($p, $len);
                    $orderAt[] = $p;
                    $orderFrame[] = $this->frame;
                    break;
                case self::SELECT:
                    $this->sized16($p, $len, 4);
                    if (($len - 4) % 2 !== 0) {
                        $this->bad($p);
                    }
                    $selections[] = new Selection($this->frame, ord($bytes[$p + 3]), $len > 4 ? array_values(unpack('v*', substr($bytes, $p + 4, $len - 4))) : []);
                    break;
                case self::CHAT:
                    $this->sized8($p, $len, 5);
                    $dest = ord($bytes[$p + 3]);
                    $audience = match ($dest) {
                        252 => ChatMessage::ALLIES,
                        253 => ChatMessage::SPECTATORS,
                        254 => ChatMessage::EVERYONE,
                        default => ChatMessage::PLAYER,
                    };
                    $chat[] = new ChatMessage($this->frame, ord($bytes[$p + 2]), $audience, $audience === ChatMessage::PLAYER ? $dest : null, $this->cstr($p + 4, $p + $len));
                    break;
                case self::SYSTEMMSG:
                    $this->sized16($p, $len, 5);
                    $system[] = new SystemMessage($this->frame, ord($bytes[$p + 3]), $this->cstr($p + 4, $p + $len));
                    break;
                case self::STARTPOS:
                    $this->fixed($p, $len, 16);
                    $s = unpack('Cplayer/Cteam/Cready/gx/gy/gz', $bytes, $p + 1);
                    $starts[] = new StartPosition($this->frame, $s['player'], $s['team'], $s['ready'], $s['x'], $s['y'], $s['z']);
                    break;
                case self::PAUSE:
                    $this->fixed($p, $len, 3);
                    $events[] = new Event($this->frame, Event::PAUSE, ord($bytes[$p + 1]), ['paused' => $bytes[$p + 2] !== "\0"], $p);
                    break;
                case self::PLAYERLEFT:
                    $this->fixed($p, $len, 3);
                    $code = ord($bytes[$p + 2]);
                    $events[] = new Event($this->frame, Event::PLAYER_LEFT, ord($bytes[$p + 1]), [
                        'reason' => [0 => 'lost_connection', 1 => 'left', 2 => 'kicked'][$code] ?? $code,
                    ], $p);
                    break;
                case self::TEAM:
                    $this->fixed($p, $len, 5);
                    $events[] = $this->teamAction(unpack('Cplayer/Caction/Cp1/Cp2', $bytes, $p + 1), $p);
                    break;
                case self::GAMEOVER:
                    $this->sized8($p, $len, 3);
                    $events[] = new Event($this->frame, Event::GAME_OVER, ord($bytes[$p + 2]), [
                        'winning_ally_teams' => $len > 3 ? array_values(unpack('C*', substr($bytes, $p + 3, $len - 3))) : [],
                    ], $p);
                    break;
                case self::PLAYERNAME:
                    $this->sized8($p, $len, 4);
                    $events[] = new Event($this->frame, Event::PLAYER_NAME, ord($bytes[$p + 2]), ['name' => $this->cstr($p + 3, $p + $len)], $p);
                    break;
                case self::CREATE_NEWPLAYER:
                    $this->sized16($p, $len, 7);
                    $events[] = new Event($this->frame, Event::NEW_PLAYER, ord($bytes[$p + 3]), [
                        'spectator' => $bytes[$p + 4] !== "\0",
                        'team' => ord($bytes[$p + 5]),
                        'name' => $this->cstr($p + 6, $p + $len),
                    ], $p);
                    break;
            }
        }

        return new Stream(
            packets: $packets,
            lastFrame: $this->frame,
            orders: new Orders($bytes, $orderAt, $orderFrame),
            selections: $selections,
            chat: $chat,
            systemMessages: $system,
            startPositionMessages: $starts,
            events: $events,
            luaMessages: $lua,
            luaMessageCount: $luaCount,
        );
    }

    /**
     * The order in the packet at `$p` of `$bytes`, which the walk has already
     * checked.
     */
    public static function decodeOrder(string $bytes, int $p, int $frame): Order
    {
        $reader = new self([]);
        $reader->b = $bytes;
        $reader->frame = $frame;

        return $reader->order($p, unpack('V', $bytes, $p - 4)[1]);
    }

    private function order(int $p, int $len): Order
    {
        $bytes = $this->b;
        $end = $p + $len;
        switch (ord($bytes[$p])) {
            case self::COMMAND:
                $this->sized16($p, $len, 4);
                $r = $p + 4;
                $command = $this->command($r, $end, false);
                $this->done($r, $end);

                return new Order($this->frame, ord($bytes[$p + 3]), Order::SELECTION, null, null, [], false, [$command], $p);
            case self::AICOMMANDS:
                return $this->unitCommands($p, $len);
            default:
                $this->sized16($p, $len, 8);
                ['player' => $player, 'ai' => $ai, 'team' => $team, 'unit' => $unit] = unpack('Cplayer/Cai/Cteam/vunit', $bytes, $p + 3);
                $r = $p + 8;
                $command = $this->command($r, $end, ord($bytes[$p]) === self::AICOMMAND_TRACKED);
                $this->done($r, $end);

                return $ai === self::NO_AI
                    ? new Order($this->frame, $player, Order::LUA, null, null, [$unit], false, [$command], $p)
                    : new Order($this->frame, $player, Order::AI, $ai, $team, [$unit], false, [$command], $p);
        }
    }

    /**
     * `u16 size, u8 player, u16 script, u8 mode`, then the message. Only a
     * message whose first byte starts a decoder's prefix gets here.
     */
    private function lua(int $p, int $len): ?LuaMessage
    {
        $data = substr($this->b, $p + 7, $len - 7);
        foreach ($this->decoders[$data[0]] as $decoder) {
            if (! str_starts_with($data, $decoder->prefix())) {
                continue;
            }
            $value = $decoder->decode($data);
            if ($value !== null) {
                ['player' => $player, 'script' => $script, 'mode' => $mode] = unpack('Cplayer/vscript/Cmode', $this->b, $p + 3);

                return new LuaMessage($this->frame, $player, $script, $mode, $decoder->kind(), $value);
            }
        }

        return null;
    }

    /**
     * One command: `i32 id, i32 timeout, u8 options, u32 paramCount`, an `i32`
     * of the AI's own for a tracked AI command, then the `f32` parameters.
     */
    private function command(int &$r, int $end, bool $tracked): Command
    {
        if ($r + 13 > $end) {
            $this->bad($r);
        }
        ['id' => $id, 'options' => $options, 'count' => $count] = unpack('lid/x4/Coptions/Vcount', $this->b, $r);
        $r += 13 + ($tracked ? 4 : 0);

        return new Command($id, $options, $this->floats($r, $end, $count));
    }

    /**
     * `NETMSG_AICOMMANDS`, which only Lua widgets send:
     * `u16 size, u8 player, u8 ai, u8 pairwise, u32 sameId, u8 sameOptions,
     * u16 sameParamCount, u16 unitCount, i16 units, u16 commandCount`, then per
     * command the id, options and parameter count where the shared value is
     * not set, then the parameters.
     */
    private function unitCommands(int $p, int $len): Order
    {
        $this->sized16($p, $len, 15);
        $end = $p + $len;
        $h = unpack('Cplayer/Cai/Cpairwise/VsameId/CsameOptions/vsameCount/vunits', $this->b, $p + 3);
        if ($h['ai'] !== self::NO_AI) {
            $this->bad($p);
        }
        $r = $p + 15;
        $units = $this->shorts($r, $end, $h['units']);
        if ($r + 2 > $end) {
            $this->bad($p);
        }
        $count = unpack('v', $this->b, $r)[1];
        $r += 2;

        $commands = [];
        for ($i = 0; $i < $count; $i++) {
            $id = $h['sameId'] === 0 ? $this->u32($r, $end) : $h['sameId'];
            $options = $h['sameOptions'] === 0xFF ? $this->u8($r, $end) : $h['sameOptions'];
            $params = $h['sameCount'] === 0xFFFF ? $this->u16($r, $end) : $h['sameCount'];
            // The id is written as unsigned and read as the engine's signed id.
            $commands[] = new Command($id >= 0x80000000 ? $id - 0x100000000 : $id, $options, $this->floats($r, $end, $params));
        }
        $this->done($r, $end);

        return new Order($this->frame, $h['player'], Order::LUA, null, null, $units, $h['pairwise'] !== 0, $commands, $p);
    }

    /** @param array{player: int, action: int, p1: int, p2: int} $t */
    private function teamAction(array $t, int $at): Event
    {
        return match ($t['action']) {
            1 => new Event($this->frame, Event::GIVE_AWAY, $t['player'], ['to_team' => $t['p1'], 'from_team' => $t['p2']], $at),
            2 => new Event($this->frame, Event::RESIGN, $t['player'], [], $at),
            3 => new Event($this->frame, Event::JOIN_TEAM, $t['player'], ['team' => $t['p1']], $at),
            4 => new Event($this->frame, Event::TEAM_DIED, $t['player'], ['team' => $t['p1']], $at),
            default => new Event($this->frame, Event::TEAM_ACTION, $t['player'], ['action' => $t['action'], 'param1' => $t['p1'], 'param2' => $t['p2']], $at),
        };
    }

    /** @return list<float> */
    private function floats(int &$r, int $end, int $count): array
    {
        if ($r + 4 * $count > $end) {
            $this->bad($r);
        }
        $out = $count === 0 ? [] : array_values(unpack('g'.$count, $this->b, $r));
        $r += 4 * $count;

        return $out;
    }

    /** @return list<int> */
    private function shorts(int &$r, int $end, int $count): array
    {
        if ($r + 2 * $count > $end) {
            $this->bad($r);
        }
        $out = $count === 0 ? [] : array_values(unpack('v'.$count, $this->b, $r));
        $r += 2 * $count;

        return $out;
    }

    private function u8(int &$r, int $end): int
    {
        if ($r + 1 > $end) {
            $this->bad($r);
        }

        return ord($this->b[$r++]);
    }

    private function u16(int &$r, int $end): int
    {
        if ($r + 2 > $end) {
            $this->bad($r);
        }
        $r += 2;

        return unpack('v', $this->b, $r - 2)[1];
    }

    private function u32(int &$r, int $end): int
    {
        if ($r + 4 > $end) {
            $this->bad($r);
        }
        $r += 4;

        return unpack('V', $this->b, $r - 4)[1];
    }

    /** The text from `$from` to `$end`, which has to end in its only NUL. */
    private function cstr(int $from, int $end): string
    {
        $text = substr($this->b, $from, $end - $from - 1);
        if ($this->b[$end - 1] !== "\0" || str_contains($text, "\0")) {
            $this->bad($from);
        }

        return $text;
    }

    private function fixed(int $p, int $len, int $size): void
    {
        if ($len !== $size) {
            $this->bad($p);
        }
    }

    /** A packet whose second byte is its own length, at least `$min` bytes. */
    private function sized8(int $p, int $len, int $min): void
    {
        if ($len < $min || ord($this->b[$p + 1]) !== $len) {
            $this->bad($p);
        }
    }

    /** A packet whose second and third bytes are its own length, at least `$min` bytes. */
    private function sized16(int $p, int $len, int $min): void
    {
        if ($len < $min || unpack('v', $this->b, $p + 1)[1] !== $len) {
            $this->bad($p);
        }
    }

    private function done(int $r, int $end): void
    {
        if ($r !== $end) {
            $this->bad($r);
        }
    }

    private function bad(int $at): never
    {
        throw new ReplayException("This replay is damaged: a packet near byte {$at} does not fit the layout of its message type.");
    }
}
