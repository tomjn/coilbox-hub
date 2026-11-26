<?php

namespace App\Domain\Replays\Lua;

/**
 * A faction pick sent as a prefix and the start unit's def id in digits.
 * Beyond All Reason sends `changeStartUnit635`. Splinter Faction, TA Recoil
 * and TAP send the byte 0x8A and then the digits.
 */
final readonly class StartUnitPick implements LuaDecoder
{
    public function __construct(private string $prefix) {}

    public function kind(): string
    {
        return 'faction_pick';
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function decode(string $data): ?object
    {
        $id = substr($data, strlen($this->prefix));

        return preg_match('/\A\d{1,9}\z/', $id) === 1 ? new FactionPick((int) $id, null) : null;
    }
}
