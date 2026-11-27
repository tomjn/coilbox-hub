<?php

namespace Tests\Unit\Replays;

use App\Domain\Replays\Lua\BarAwards;
use App\Domain\Replays\Lua\BarUnitList;
use App\Domain\Replays\Lua\FactionNamePick;
use App\Domain\Replays\Lua\LuaDecoders;
use App\Domain\Replays\Lua\StartUnitPick;
use App\Domain\Replays\ReplayReader;
use PHPUnit\Framework\TestCase;

class LuaDecodersTest extends TestCase
{
    public function test_each_game_has_only_its_own_decoders(): void
    {
        $picks = [
            'Beyond All Reason test-32071-e1cbffe' => ['changeStartUnit'],
            'SplinterFaction 0.1.97' => ["\x8A"],
            'Total Annihilation: Recoil 1.0.0' => ["\x8A"],
            'Total Atomic Power v4.0.7.2' => ["\x8A"],
            'Metal Factions v2.58' => ['PICKFACTION|'],
            'Zero-K v1.12' => [],
            // The space before the version is part of what is matched.
            'Metal Factions' => [],
        ];

        foreach ($picks as $game => $prefixes) {
            $barOnly = str_starts_with($game, 'Beyond All Reason ') ? [['awards', "\xA1"], ['unit_list', 'unitdefs:']] : [];
            $this->assertSame(
                [...array_map(fn ($prefix) => ['faction_pick', $prefix], $prefixes), ...$barOnly],
                array_map(fn ($d) => [$d->kind(), $d->prefix()], LuaDecoders::for($game)),
                $game,
            );
        }
    }

    public function test_start_unit_picks_need_digits(): void
    {
        $bar = new StartUnitPick('changeStartUnit');
        $this->assertSame(635, $bar->decode('changeStartUnit635')->unitDefId);
        $this->assertNull($bar->decode('changeStartUnit'));
        $this->assertNull($bar->decode('changeStartUnitcorcom'));
        $this->assertNull($bar->decode('changeStartUnit12 '));

        $byte = new StartUnitPick("\x8A");
        $this->assertSame(19, $byte->decode("\x8A19")->unitDefId);
        $this->assertNull($byte->decode("\x8A\x13"));
    }

    public function test_a_metal_factions_pick_gives_the_faction_name(): void
    {
        $pick = (new FactionNamePick)->decode('PICKFACTION|aven');
        $this->assertSame(['aven', null], [$pick->faction, $pick->unitDefId]);
        $this->assertNull((new FactionNamePick)->decode('PICKFACTION|'));
        $this->assertNull((new FactionNamePick)->decode("PICKFACTION|\xFF"));
    }

    public function test_a_metal_factions_pick_is_read_from_the_stream(): void
    {
        // The Metal Factions fixture has no pick, so one is added.
        $b = $this->withMessage('mf-valles', 100, 'PICKFACTION|zaal');

        $stream = (new ReplayReader)->readBytes($b)->stream;
        $picks = $stream->luaMessagesOf('faction_pick');
        $this->assertCount(1, $picks);
        $this->assertSame([90, 0, 100, 'zaal'], [$picks[0]->frame, $picks[0]->player, $picks[0]->script, $picks[0]->value->faction]);
        $this->assertSame(95, $stream->luaMessageCount);
    }

    public function test_a_pick_sent_to_luaui_is_not_read(): void
    {
        $b = $this->withMessage('mf-valles', 2000, 'PICKFACTION|zaal');

        $this->assertSame([], (new ReplayReader)->readBytes($b)->stream->luaMessages);
    }

    public function test_the_unit_list_and_awards_are_not_read_in_another_game(): void
    {
        foreach (['unitdefs:'.gzcompress('["armcom"]'), "\xA11:2"] as $data) {
            $b = $this->withMessage('mf-valles', 100, $data);

            $this->assertSame([], (new ReplayReader)->readBytes($b)->stream->luaMessages);
        }
    }

    public function test_beyond_all_reasons_messages_are_read_only_from_luarules(): void
    {
        $counts = fn (string $b) => array_count_values(array_map(fn ($m) => $m->kind, (new ReplayReader)->readBytes($b)->stream->luaMessages));
        $real = $counts(gzdecode(file_get_contents(__DIR__.'/../../Fixtures/replays/bar-tangerine.sdfz')));

        foreach (['faction_pick' => 'changeStartUnit5', 'unit_list' => 'unitdefs:'.gzcompress('["armcom"]'), 'awards' => "\xA11:2"] as $kind => $data) {
            $this->assertEquals($real, $counts($this->withMessage('bar-tangerine', 2000, $data)), $kind);
            $this->assertSame($real[$kind] + 1, $counts($this->withMessage('bar-tangerine', 100, $data))[$kind], $kind);
        }
    }

    /**
     * A fixture's bytes with a Lua message from player 0 added as the last
     * packet of its stream, and the header's stream size moved with it.
     */
    private function withMessage(string $fixture, int $script, string $data): string
    {
        $b = gzdecode(file_get_contents(__DIR__."/../../Fixtures/replays/{$fixture}.sdfz"));
        $payload = chr(50).pack('v', 7 + strlen($data)).chr(0).pack('v', $script).chr(0).$data;
        $packet = pack('g', 3.0).pack('V', strlen($payload)).$payload;
        $end = 352 + unpack('V', $b, 304)[1] + unpack('V', $b, 308)[1];
        $b = substr_replace($b, pack('V', unpack('V', $b, 308)[1] + strlen($packet)), 308, 4);

        return substr($b, 0, $end).$packet.substr($b, $end);
    }

    public function test_awards_must_fit_their_layout(): void
    {
        $awards = (new BarAwards)->decode("\xA10:0\xA26:18970600\xA3".'2:0.21801907'."\xA40\xA7".'1:-3.5e2');
        $this->assertSame(
            [['econDestroyed', null, 0.0], ['fightingUnitsDestroyed', 5, 18970600.0], ['resourceEfficiency', 1, 0.21801907], ['cow', null, null], ['sleep', 0, -350.0]],
            array_map(fn ($a) => [$a->name, $a->team, $a->value], $awards->awards),
        );
        $this->assertNull((new BarAwards)->decode("\xA1"));
        $this->assertNull((new BarAwards)->decode("\xA11:2 trailing"));
        $this->assertNull((new BarAwards)->decode("\xA1x:2"));
    }

    public function test_a_unit_list_must_be_zlib_json_of_names(): void
    {
        $decoder = new BarUnitList;
        $list = $decoder->decode('unitdefs:'.gzcompress('["armcom","corcom"]'));
        $this->assertSame(['armcom', 'corcom'], $list->names);
        $this->assertSame('corcom', $list->name(2));
        $this->assertNull($list->name(0));
        $this->assertNull($list->name(3));
        $this->assertNull($decoder->decode('unitdefs:not zlib'));
        $this->assertNull($decoder->decode('unitdefs:'.gzcompress('{"a":"b"}')));
        $this->assertNull($decoder->decode('unitdefs:'.gzcompress('["a",1]')));
    }
}
