<?php

namespace App\Domain\Replays;

use App\Domain\Replays\Lua\LuaDecoders;
use App\Domain\Replays\Script\StartScript;
use App\Domain\Replays\Stream\StreamReader;

/**
 * Reads a Recoil replay file: `.sdfz` is gzip compressed and `.sdf` is not.
 * The first two bytes decide, not the name.
 *
 * The header gives the size of every part, and they have to add up to the
 * file exactly. A file that is cut short, has bytes left over or has a part
 * that does not fit its layout is refused with a `ReplayException`.
 */
final class ReplayReader
{
    /**
     * The most a `.sdfz` may decompress to. The largest replay seen, 52
     * minutes of Beyond All Reason with 16 players, is 13.9 MB decompressed,
     * so this leaves room for matches several times longer.
     */
    public const MAX_DECOMPRESSED = 64 << 20;

    /**
     * @throws ReplayException
     */
    public function read(string $path): Replay
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new ReplayException("The replay {$path} could not be read.");
        }

        return $this->readBytes($raw);
    }

    /**
     * @throws ReplayException
     */
    public function readBytes(string $raw): Replay
    {
        $bytes = $raw;
        if (str_starts_with($raw, "\x1f\x8b")) {
            $bytes = self::gunzip($raw);
        }
        unset($raw);

        $h = Header::parse($bytes);
        $scriptAt = Header::SIZE;
        $streamAt = $scriptAt + $h->scriptSize;
        $endAt = $streamAt + $h->streamSize;
        $size = $endAt + $h->winningAllyTeamsSize + $h->playerStatSize + $h->teamStatSize;
        if ($size !== strlen($bytes)) {
            $what = $size > strlen($bytes) ? 'is cut short' : 'has bytes after its end';
            throw new ReplayException("This replay {$what}: its header gives {$size} bytes and it has ".strlen($bytes).'.');
        }

        $script = StartScript::parse(substr($bytes, $scriptAt, $h->scriptSize));
        $stream = (new StreamReader(LuaDecoders::all()))->read($bytes, $streamAt, $endAt);
        $endOfGame = $size === $endAt ? null : EndOfGame::parse($bytes, $endAt, $h);

        return new Replay($h, $script, $stream, $endOfGame);
    }

    /**
     * Decompresses in small steps and stops at the cap, so a small file that
     * expands hugely never fills memory. `gzdecode`'s own length limit lets
     * output past it through. Deflate expands at most 1,032 times (zlib's
     * technical notes), so an 8 KB step adds under 9 MB before the check.
     *
     * @throws ReplayException
     */
    private static function gunzip(string $raw): string
    {
        $damaged = new ReplayException('This replay is damaged: it could not be decompressed.');
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);
        $out = '';
        for ($at = 0; $at < strlen($raw) && inflate_get_status($inflate) !== ZLIB_STREAM_END; $at += 8192) {
            $part = @inflate_add($inflate, substr($raw, $at, 8192), ZLIB_SYNC_FLUSH);
            if ($part === false) {
                throw $damaged;
            }
            $out .= $part;
            if (strlen($out) > self::MAX_DECOMPRESSED) {
                throw new ReplayException('This replay is too large: it decompresses to more than '.(self::MAX_DECOMPRESSED >> 20).' MB.');
            }
        }
        if (inflate_get_status($inflate) !== ZLIB_STREAM_END) {
            throw $damaged;
        }

        return $out;
    }
}
