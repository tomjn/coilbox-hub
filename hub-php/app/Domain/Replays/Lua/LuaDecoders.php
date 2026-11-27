<?php

namespace App\Domain\Replays\Lua;

use Illuminate\Support\Str;

/** Every Lua message decoder the reader runs, in one list. */
final class LuaDecoders
{
    /**
     * A faction pick is read only for the games Coilbox reads it for (#695),
     * by how their game type starts. The summary of any other game has the
     * lobby's faction, even when the game sends a message that looks the same.
     *
     * @return list<LuaDecoder>
     */
    public static function for(string $gameType): array
    {
        $picks = match (true) {
            Str::startsWith($gameType, 'Beyond All Reason ') => [new StartUnitPick('changeStartUnit')],
            Str::startsWith($gameType, ['SplinterFaction ', 'Total Annihilation: Recoil ', 'Total Atomic Power ']) => [new StartUnitPick("\x8A")],
            Str::startsWith($gameType, 'Metal Factions ') => [new FactionNamePick],
            default => [],
        };

        return [...$picks, new BarAwards, new BarUnitList];
    }
}
