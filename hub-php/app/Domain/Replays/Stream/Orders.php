<?php

namespace App\Domain\Replays\Stream;

use Countable;
use Generator;
use IteratorAggregate;

/**
 * Every order in the stream, in the order they were sent. Each pass decodes
 * them again from the replay's bytes. On a 52 minute Beyond All Reason replay
 * with 53,046 orders, holding them all as objects raised the read's peak
 * memory from 31 MB to 87 MB.
 *
 * @implements IteratorAggregate<int, Order>
 */
final readonly class Orders implements Countable, IteratorAggregate
{
    /**
     * @param  list<int>  $offsets  where each order's packet starts in `$bytes`
     * @param  list<int>  $frames  the frame each order was sent in
     */
    public function __construct(
        private string $bytes,
        private array $offsets,
        private array $frames,
    ) {}

    public function count(): int
    {
        return count($this->offsets);
    }

    /** @return Generator<int, Order> */
    public function getIterator(): Generator
    {
        foreach ($this->offsets as $i => $p) {
            yield $i => StreamReader::decodeOrder($this->bytes, $p, $this->frames[$i]);
        }
    }
}
