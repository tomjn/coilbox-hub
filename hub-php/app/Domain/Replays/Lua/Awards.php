<?php

namespace App\Domain\Replays\Lua;

final readonly class Awards
{
    public function __construct(
        /** @var list<Award> in the order the game sent them */
        public array $awards,
    ) {}

    /** @return list<Award> */
    public function named(string $name): array
    {
        return array_values(array_filter($this->awards, fn (Award $a) => $a->name === $name));
    }
}
