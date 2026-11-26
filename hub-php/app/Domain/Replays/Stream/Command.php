<?php

namespace App\Domain\Replays\Stream;

/**
 * One command in an order. A negative id is a build order for the unit def
 * whose id is its absolute value.
 */
final readonly class Command
{
    public function __construct(
        public int $id,
        public int $options,
        /** @var list<float> */
        public array $params,
    ) {}
}
