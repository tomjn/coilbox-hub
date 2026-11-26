<?php

namespace App\Domain\Replays\Lua;

/** Beyond All Reason's unit list, `unitdefs:` and zlib compressed JSON of names. */
final readonly class BarUnitList implements LuaDecoder
{
    public function kind(): string
    {
        return 'unit_list';
    }

    public function prefix(): string
    {
        return 'unitdefs:';
    }

    public function decode(string $data): ?object
    {
        $json = @gzuncompress(substr($data, strlen($this->prefix())));
        $names = $json === false ? null : json_decode($json, true);
        if (! is_array($names) || ! array_is_list($names)) {
            return null;
        }
        foreach ($names as $name) {
            if (! is_string($name)) {
                return null;
            }
        }

        return new UnitList($names);
    }
}
