<?php

namespace App\Domain\Replays\Lua;

/** Every Lua message decoder the reader runs, in one list. */
final class LuaDecoders
{
    /** @return list<LuaDecoder> */
    public static function all(): array
    {
        return [
            new StartUnitPick('changeStartUnit'),
            new StartUnitPick("\x8A"),
            new FactionNamePick,
            new BarAwards,
            new BarUnitList,
        ];
    }
}
