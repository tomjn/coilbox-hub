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
            $bytes = @gzdecode($raw);
            if ($bytes === false) {
                throw new ReplayException('This replay is damaged: it could not be decompressed.');
            }
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
}
