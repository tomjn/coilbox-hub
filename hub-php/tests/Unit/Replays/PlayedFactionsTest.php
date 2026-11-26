<?php

namespace Tests\Unit\Replays;

use App\Domain\Replays\PlayedFactions;
use App\Domain\Replays\Replay;
use App\Domain\Replays\ReplayReader;
use PHPUnit\Framework\TestCase;

/**
 * The picks here need no catalogue. A start unit the catalogue could name is
 * covered by `ReplayCheckTest`.
 */
class PlayedFactionsTest extends TestCase
{
    /** The fixture with Lua messages from player 0 added before its first frame, or after its last. */
    private function read(string $fixture, array $before = [], array $after = []): Replay
    {
        $b = gzdecode(file_get_contents(__DIR__.'/../../Fixtures/replays/'.$fixture.'.sdfz'));
        $size = unpack('V', $b, 308)[1];
        $start = 352 + unpack('V', $b, 304)[1];
        $packets = fn (array $messages) => implode('', array_map(function (string $data) {
            $payload = chr(50).pack('v', 7 + strlen($data)).chr(0).pack('v', 100).chr(0).$data;

            return pack('g', 0.0).pack('V', strlen($payload)).$payload;
        }, $messages));
        $first = $packets($before);
        $last = $packets($after);
        $b = substr_replace($b, pack('V', $size + strlen($first.$last)), 308, 4);
        $b = substr($b, 0, $start).$first.substr($b, $start, $size).$last.substr($b, $start + $size);

        return (new ReplayReader)->readBytes($b);
    }

    public function test_a_player_with_no_pick_keeps_the_start_scripts_side(): void
    {
        $this->assertSame([0 => ['faction' => 'aven', 'known' => true]], PlayedFactions::of($this->read('mf-valles')));
    }

    public function test_the_last_named_pick_before_the_first_frame_is_the_faction(): void
    {
        $replay = $this->read('mf-valles', before: ['PICKFACTION|gear', 'PICKFACTION|zaal']);

        $this->assertSame([0 => ['faction' => 'zaal', 'known' => true]], PlayedFactions::of($replay));
    }

    public function test_a_pick_after_the_first_frame_does_not_count(): void
    {
        $replay = $this->read('mf-valles', after: ['PICKFACTION|zaal']);

        $this->assertSame(90, $replay->stream->luaMessagesOf('faction_pick')[0]->frame);
        $this->assertSame([0 => ['faction' => 'aven', 'known' => true]], PlayedFactions::of($replay));
        // The Splinter Faction fixture's only pick is at frame 41.
        $this->assertSame([0 => ['faction' => null, 'known' => true]], PlayedFactions::of($this->read('sf-tumult')));
    }

    public function test_a_start_unit_the_replay_cannot_name_leaves_the_faction_unknown(): void
    {
        $replay = $this->read('sf-tumult', before: ["\x8A19"]);

        $this->assertSame([0 => ['faction' => null, 'known' => false]], PlayedFactions::of($replay));
    }
}
