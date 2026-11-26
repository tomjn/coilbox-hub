<?php

namespace App\Domain\Replays\Lua;

/**
 * Reads one kind of Lua message a game writes into the replay on purpose.
 * Add a decoder to `LuaDecoders::all()` to read another.
 */
interface LuaDecoder
{
    /** The kind of message, such as `faction_pick`. Several decoders may share one. */
    public function kind(): string;

    /** The bytes the message starts with. Only messages with them reach `decode`. */
    public function prefix(): string;

    /** The message's meaning, or null when the bytes after the prefix do not fit. */
    public function decode(string $data): ?object;
}
