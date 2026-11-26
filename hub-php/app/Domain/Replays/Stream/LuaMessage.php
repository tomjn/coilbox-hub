<?php

namespace App\Domain\Replays\Stream;

/**
 * A Lua message one of the decoders in `Lua\LuaDecoders` understood. `kind`
 * is the decoder's name and `value` its result.
 */
final readonly class LuaMessage
{
    public function __construct(
        public int $frame,
        public int $player,
        public int $script,
        public int $mode,
        public string $kind,
        public object $value,
    ) {}
}
