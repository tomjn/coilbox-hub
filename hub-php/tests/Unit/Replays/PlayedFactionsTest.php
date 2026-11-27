<?php

namespace Tests\Unit\Replays;

use App\Domain\Replays\PlayedFactions;
use App\Domain\Replays\Replay;
use App\Domain\Replays\ReplayReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The catalogue here is empty. A start unit it has a faction for is covered by
 * `ReplayCheckTest`.
 */
class PlayedFactionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The fixture with Lua messages from player 0 added before its first frame,
     * after its last, or straight after the frame each is keyed by. `$script`
     * replaces text in the start script.
     */
    private function read(string $fixture, array $before = [], array $after = [], array $at = [], array $script = []): Replay
    {
        $b = gzdecode(file_get_contents(__DIR__.'/../../Fixtures/replays/'.$fixture.'.sdfz'));
        [$scriptSize, $size] = array_values(unpack('V2', $b, 304));
        $text = strtr(substr($b, 352, $scriptSize), $script);
        $packets = fn (array $messages) => implode('', array_map(function (string $data) {
            $payload = chr(50).pack('v', 7 + strlen($data)).chr(0).pack('v', 100).chr(0).$data;

            return pack('g', 0.0).pack('V', strlen($payload)).$payload;
        }, $messages));
        $stream = substr($b, 352 + $scriptSize, $size);
        $out = $packets($before);
        $frame = -1;
        for ($p = 0; $p < $size; $p += $length) {
            $length = 8 + unpack('V', $stream, $p + 4)[1];
            $out .= substr($stream, $p, $length);
            // A new frame packet or a key frame packet moves the match on.
            $type = $length > 8 ? ord($stream[$p + 8]) : null;
            if ($type === 2 || $type === 1) {
                $frame = $type === 2 ? $frame + 1 : unpack('l', $stream, $p + 9)[1];
                $out .= $packets((array) ($at[$frame] ?? []));
                unset($at[$frame]);
            }
        }
        $this->assertSame([], $at, 'The fixture has no such frame.');
        $out .= $packets($after);
        $b = substr_replace($b, pack('V2', strlen($text), strlen($out)), 304, 8);
        $b = substr($b, 0, 352).$text.$out.substr($b, 352 + $scriptSize + $size);

        return (new ReplayReader)->readBytes($b);
    }

    /** @return list<array<string, mixed>> summary units naming each id */
    private static function units(array $names): array
    {
        return array_map(fn ($id, $name) => ['unit_def_id' => $id, 'name' => $name], array_keys($names), $names);
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
    }

    public function test_a_game_with_no_picks_read_keeps_the_start_scripts_side(): void
    {
        $replay = $this->read('mf-valles', before: ['PICKFACTION|zaal'], script: ['Metal Factions ' => 'Other Factions ']);

        $this->assertStringStartsWith('Other Factions ', $replay->script->gameType);
        $this->assertSame([0 => ['faction' => 'aven', 'known' => true]], PlayedFactions::of($replay));
    }

    public function test_a_splinter_faction_pick_in_the_faction_phase_counts(): void
    {
        $replay = $this->read('sf-tumult');

        $this->assertSame(41, $replay->stream->luaMessagesOf('faction_pick')[0]->frame);
        $this->assertSame([0 => ['faction' => 'fedcommander', 'known' => false]], PlayedFactions::of($replay, self::units([19 => 'fedcommander'])));
    }

    public function test_the_faction_phase_closes_90_frames_after_every_players_team_has_picked(): void
    {
        $units = self::units([19 => 'fedcommander', 20 => 'lozcommander', 21 => 'late']);
        // The fixture's one player picks at frame 41.
        $replay = $this->read('sf-tumult', at: [131 => "\x8A20", 132 => "\x8A21"]);

        $this->assertSame('lozcommander', PlayedFactions::of($replay, $units)[0]['faction']);
    }

    public function test_the_faction_phase_closes_at_frame_900_while_a_team_has_not_picked(): void
    {
        // A second player, on the AI's team, who never picks.
        $script = ['[allyteam0]' => "[player1]\n{\nname=Other;\nteam=1;\nspectator=0;\n}\n[allyteam0]"];
        $units = self::units([19 => 'fedcommander', 20 => 'lozcommander', 21 => 'late']);
        $replay = $this->read('sf-tumult', at: [899 => "\x8A20", 900 => "\x8A21"], script: $script);

        $this->assertCount(2, $replay->script->players);
        $this->assertSame('lozcommander', PlayedFactions::of($replay, $units)[0]['faction']);
    }

    public function test_ta_recoil_has_the_same_faction_phase(): void
    {
        $replay = $this->read('sf-tumult', script: ['SplinterFaction 0.1.95' => 'Total Annihilation: Recoil 1.0.0']);

        $this->assertSame('arm_commander', PlayedFactions::of($replay, self::units([19 => 'arm_commander']))[0]['faction']);
    }

    /**
     * The tumult script with a second player, Other, on the AI's team, sides
     * for both teams, and these mod options.
     *
     * @return array<string, string>
     */
    private static function twoTeams(string $game = 'SplinterFaction 0.1.95', string $modOptions = '', int $otherTeam = 1): array
    {
        return [
            'SplinterFaction 0.1.95' => $game,
            '[allyteam0]' => "[player1]\n{\nname=Other;\nteam=$otherTeam;\nspectator=0;\n}\n[allyteam0]",
            "[team0]\n{\n" => "[team0]\n{\nside=Loz;\n",
            "[team1]\n{\n" => "[team1]\n{\nside=Fed;\n",
            'allowuserwidgets=1;' => 'allowuserwidgets=1;'.$modOptions,
        ];
    }

    public function test_a_splinter_faction_team_with_no_pick_has_no_known_faction(): void
    {
        $replay = $this->read('sf-tumult', script: self::twoTeams());

        $this->assertSame('Fed', $replay->script->teams[1]->side);
        $this->assertSame([
            0 => ['faction' => 'fedcommander', 'known' => false],
            1 => ['faction' => null, 'known' => false],
        ], PlayedFactions::of($replay, self::units([19 => 'fedcommander'])));
    }

    public function test_a_ta_recoil_team_with_no_pick_has_no_known_faction(): void
    {
        $replay = $this->read('sf-tumult', script: self::twoTeams('Total Annihilation: Recoil 1.0.0'));

        $this->assertSame(['faction' => null, 'known' => false], PlayedFactions::of($replay, self::units([19 => 'arm_commander']))[1]);
    }

    public function test_a_player_takes_the_pick_of_a_teammate(): void
    {
        $replay = $this->read('sf-tumult', script: self::twoTeams(otherTeam: 0));

        $this->assertSame(['faction' => 'fedcommander', 'known' => false], PlayedFactions::of($replay, self::units([19 => 'fedcommander']))[1]);
    }

    public function test_splinter_faction_with_the_faction_chooser_skipped_takes_no_pick_and_keeps_the_sides(): void
    {
        $replay = $this->read('sf-tumult', script: self::twoTeams(modOptions: "\nskipfactionchooser=1;"));

        $this->assertSame([
            0 => ['faction' => 'Loz', 'known' => true],
            1 => ['faction' => 'Fed', 'known' => true],
        ], PlayedFactions::of($replay, self::units([19 => 'fedcommander'])));
    }

    public function test_splinter_faction_ignores_ta_recoils_mod_option(): void
    {
        $replay = $this->read('sf-tumult', script: self::twoTeams(modOptions: "\nfactionchoice=0;"));

        $this->assertNull(PlayedFactions::of($replay, self::units([19 => 'fedcommander']))[1]['faction']);
    }

    public function test_ta_recoil_with_faction_choice_off_takes_no_pick_and_keeps_the_sides(): void
    {
        foreach (["\nfactionchoice=0;", "\nfactionchoice=false;", "\nskippregame=1;"] as $option) {
            $replay = $this->read('sf-tumult', script: self::twoTeams('Total Annihilation: Recoil 1.0.0', $option));

            $this->assertSame([
                0 => ['faction' => 'Loz', 'known' => true],
                1 => ['faction' => 'Fed', 'known' => true],
            ], PlayedFactions::of($replay, self::units([19 => 'arm_commander'])), $option);
        }
    }

    public function test_ta_recoil_with_faction_choice_on_by_its_mod_options_takes_picks(): void
    {
        foreach (["\nfactionchoice=1;", "\nskippregame=0;", "\nskipfactionchooser=1;"] as $option) {
            $replay = $this->read('sf-tumult', script: self::twoTeams('Total Annihilation: Recoil 1.0.0', $option));

            $this->assertSame([
                0 => ['faction' => 'arm_commander', 'known' => false],
                1 => ['faction' => null, 'known' => false],
            ], PlayedFactions::of($replay, self::units([19 => 'arm_commander'])), $option);
        }
    }

    public function test_another_game_with_the_same_message_takes_no_pick_after_the_first_frame(): void
    {
        $replay = $this->read('sf-tumult', script: ['SplinterFaction 0.1.95' => 'Total Atomic Power v4.0.7.2']);

        $this->assertSame([0 => ['faction' => null, 'known' => true]], PlayedFactions::of($replay, self::units([19 => 'bowhq'])));
    }

    public function test_a_start_unit_the_replay_cannot_name_leaves_the_faction_unknown(): void
    {
        $replay = $this->read('sf-tumult');

        $this->assertSame([0 => ['faction' => null, 'known' => false]], PlayedFactions::of($replay, self::units([20 => 'lozcommander'])));
    }
}
