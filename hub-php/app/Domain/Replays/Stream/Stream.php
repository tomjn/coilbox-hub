<?php

namespace App\Domain\Replays\Stream;

/**
 * What the packet stream recorded. Frames come from the stream's own frame
 * messages. Anything before the first frame has frame -1, the engine's own
 * value for the pregame.
 */
final readonly class Stream
{
    public const PREGAME = -1;

    public function __construct(
        public int $packets,
        public int $lastFrame,
        public Orders $orders,
        /** @var list<Selection> */
        public array $selections,
        /** @var list<ChatMessage> */
        public array $chat,
        /** @var list<SystemMessage> */
        public array $systemMessages,
        /** @var list<StartPosition> every start position message, in order */
        public array $startPositionMessages,
        /** @var list<Event> */
        public array $events,
        /** @var list<LuaMessage> the Lua messages a decoder understood */
        public array $luaMessages,
        /** Every Lua message, decoded or not. */
        public int $luaMessageCount,
    ) {}

    /**
     * Where each team started, by team number.
     *
     * The last message before the first frame counts, because the server ends
     * the pregame by sending each team's position as it holds it. A team whose
     * player never readied and has no position is left out.
     *
     * @return array<int, StartPosition>
     */
    public function startPositions(): array
    {
        $out = [];
        foreach ($this->startPositionMessages as $p) {
            if ($p->frame !== self::PREGAME) {
                continue;
            }
            if ($p->ready === StartPosition::READY_FAILED && $p->x === 0.0 && $p->y === 0.0 && $p->z === 0.0) {
                continue;
            }
            $out[$p->team] = $p;
        }
        ksort($out);

        return $out;
    }

    /** @return list<LuaMessage> */
    public function luaMessagesOf(string $kind): array
    {
        return array_values(array_filter($this->luaMessages, fn (LuaMessage $m) => $m->kind === $kind));
    }

    /** @return list<Event> */
    public function eventsOf(string $type): array
    {
        return array_values(array_filter($this->events, fn (Event $e) => $e->type === $type));
    }
}
