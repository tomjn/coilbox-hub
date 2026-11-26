<?php

namespace App\Domain\Replays\Lua;

/**
 * Beyond All Reason's end of game awards. Each place is a marker byte from
 * 0xA1 to 0xA7 naming the award, then the team and, for most awards, `:` and
 * a value. The team on the wire is the team number plus one, and 0 means
 * nobody, which matches the awards Beyond All Reason's replay site shows.
 */
final readonly class BarAwards implements LuaDecoder
{
    private const NAMES = [
        "\xA1" => 'econDestroyed',
        "\xA2" => 'fightingUnitsDestroyed',
        "\xA3" => 'resourceEfficiency',
        "\xA4" => 'cow',
        "\xA5" => 'mostResourcesProduced',
        "\xA6" => 'mostDamageTaken',
        "\xA7" => 'sleep',
    ];

    public function kind(): string
    {
        return 'awards';
    }

    public function prefix(): string
    {
        return "\xA1";
    }

    public function decode(string $data): ?object
    {
        $number = '-?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?';
        if (preg_match_all('/\G([\xA1-\xA7])(\d+)(?::('.$number.'))?/', $data, $places, PREG_SET_ORDER) === 0) {
            return null;
        }
        if (strlen(implode('', array_column($places, 0))) !== strlen($data)) {
            return null;
        }

        $awards = [];
        foreach ($places as $p) {
            $team = (int) $p[2] - 1;
            $awards[] = new Award(
                name: self::NAMES[$p[1]],
                team: $team < 0 ? null : $team,
                value: isset($p[3]) ? (float) $p[3] : null,
            );
        }

        return new Awards($awards);
    }
}
