<?php

namespace App\Domain\Replays\Lua;

/**
 * The game's unit def names in id order. Unit def ids in the stream count from
 * one, so id n is entry n minus one.
 */
final readonly class UnitList
{
    public function __construct(
        /** @var list<string> */
        public array $names,
    ) {}

    public function name(int $unitDefId): ?string
    {
        return $this->names[$unitDefId - 1] ?? null;
    }
}
