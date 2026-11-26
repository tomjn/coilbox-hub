<?php

namespace App\Domain\Replays\Stream;

final readonly class ChatMessage
{
    public const ALLIES = 'allies';

    public const SPECTATORS = 'spectators';

    public const EVERYONE = 'everyone';

    public const PLAYER = 'player';

    public function __construct(
        public int $frame,
        public int $from,
        /** One of the constants above. */
        public string $audience,
        /** The player it was for, when the audience is one player. */
        public ?int $toPlayer,
        public string $text,
    ) {}
}
