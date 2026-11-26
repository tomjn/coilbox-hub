<?php

namespace App\Domain\Replays\Stream;

/**
 * Commands a player sent. An order from the player's selection names no
 * units, because it goes to whatever the player had selected. A Lua widget
 * or an AI names its units.
 */
final readonly class Order
{
    /** `NETMSG_COMMAND`, given to the player's selection. */
    public const SELECTION = 'selection';

    /** `NETMSG_AICOMMAND` or `NETMSG_AICOMMANDS` with no AI, sent by a Lua widget. */
    public const LUA = 'lua';

    /** `NETMSG_AICOMMAND` sent by a skirmish AI. */
    public const AI = 'ai';

    public function __construct(
        public int $frame,
        public int $player,
        public string $origin,
        /** The AI number and its team, for an AI order. */
        public ?int $ai,
        public ?int $team,
        /** @var list<int> */
        public array $units,
        /** True when the commands go to the units one for one, not each to all. */
        public bool $pairwise,
        /** @var list<Command> */
        public array $commands,
    ) {}
}
