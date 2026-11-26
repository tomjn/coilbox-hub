<?php

namespace App\Domain\Replays;

use App\Domain\Replays\Script\StartScript;
use App\Domain\Replays\Stream\Stream;

/**
 * Everything the hub reads from one replay file. The end of game block is null
 * when the recording has no game over.
 */
final readonly class Replay
{
    public function __construct(
        public Header $header,
        public StartScript $script,
        public Stream $stream,
        public ?EndOfGame $endOfGame,
    ) {}
}
