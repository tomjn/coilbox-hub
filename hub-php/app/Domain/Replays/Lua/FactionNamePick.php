<?php

namespace App\Domain\Replays\Lua;

/** Metal Factions' faction pick, `PICKFACTION|` and the faction's name. */
final readonly class FactionNamePick implements LuaDecoder
{
    public function kind(): string
    {
        return 'faction_pick';
    }

    public function prefix(): string
    {
        return 'PICKFACTION|';
    }

    public function decode(string $data): ?object
    {
        $name = substr($data, strlen($this->prefix()));

        return $name !== '' && mb_check_encoding($name, 'UTF-8') ? new FactionPick(null, $name) : null;
    }
}
