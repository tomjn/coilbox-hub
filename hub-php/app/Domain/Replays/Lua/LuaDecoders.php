<?php

namespace App\Domain\Replays\Lua;

use Illuminate\Support\Str;

/** Every Lua message decoder the reader runs, in one list. */
final class LuaDecoders
{
    /**
     * Each message is read only for the games Coilbox reads it for (#695,
     * #696), by how their game type starts. The summary of any other game has
     * the lobby's faction and no awards, even when the game sends messages that
     * look the same.
     *
     * @return list<LuaDecoder>
     */
    public static function for(string $gameType): array
    {
        return match (true) {
            Str::startsWith($gameType, 'Beyond All Reason ') => [new StartUnitPick('changeStartUnit'), new BarAwards, new BarUnitList],
            Str::startsWith($gameType, ['SplinterFaction ', 'Total Annihilation: Recoil ', 'Total Atomic Power ']) => [new StartUnitPick("\x8A")],
            Str::startsWith($gameType, 'Metal Factions ') => [new FactionNamePick],
            default => [],
        };
    }
}
