<?php

namespace Tests\Unit\Gallery;

use App\Domain\Gallery\Previews;
use PHPUnit\Framework\TestCase;

/** Ported from the Next app's `lib/gallery/presetPreview.test.ts` and `setupPackPreview.test.ts`. */
class PreviewsTest extends TestCase
{
    public function test_colour_is_read_as_a_coilbox_rgb_tuple_not_an_rgb_object(): void
    {
        $this->assertSame('rgb(230 61 51)', Previews::participantColorCss([0.9, 0.24, 0.2]));
        $this->assertSame('rgb(0 0 0)', Previews::participantColorCss([0, 0, 0]));
        $this->assertSame('rgb(255 255 255)', Previews::participantColorCss([1, 1, 1]));
    }

    public function test_a_missing_or_malformed_colour_degrades_to_black_rather_than_throwing(): void
    {
        $this->assertSame('rgb(0 0 0)', Previews::participantColorCss(null));
        $this->assertSame('rgb(0 0 0)', Previews::participantColorCss([]));
    }

    public function test_out_of_range_colour_components_clamp_instead_of_producing_invalid_css(): void
    {
        $this->assertSame('rgb(0 255 128)', Previews::participantColorCss([-1, 2, 0.5]));
    }

    public function test_the_you_row_is_named_for_the_player_falling_back_to_you(): void
    {
        $this->assertSame('Tom', Previews::participantLabel(['kind' => 'you', 'name' => 'Tom']));
        $this->assertSame('You', Previews::participantLabel(['kind' => 'you']));
    }

    public function test_an_ai_row_prefers_the_ais_own_name_then_its_short_name_then_the_row_name(): void
    {
        $this->assertSame('BARbarian', Previews::participantLabel(['kind' => 'ai', 'ai' => ['name' => 'BARbarian', 'shortName' => 'BARb']]));
        $this->assertSame('BARb', Previews::participantLabel(['kind' => 'ai', 'ai' => ['shortName' => 'BARb']]));
        $this->assertSame('AI 1', Previews::participantLabel(['kind' => 'ai', 'name' => 'AI 1']));
    }

    public function test_an_ai_row_with_no_ai_reference_and_no_name_is_an_open_slot(): void
    {
        $this->assertSame('Open slot', Previews::participantLabel(['kind' => 'ai']));
    }

    public function test_the_random_side_sentinel_reads_as_random(): void
    {
        $this->assertSame('Random', Previews::participantSideLabel('__random__'));
    }

    public function test_a_named_side_passes_through_an_empty_or_absent_side_shows_nothing(): void
    {
        $this->assertSame('Cortex', Previews::participantSideLabel('Cortex'));
        $this->assertNull(Previews::participantSideLabel(''));
        $this->assertNull(Previews::participantSideLabel(null));
    }

    public function test_participants_group_into_ally_teams_ordered_by_ally_team_number(): void
    {
        $composition = Previews::presetComposition(['participants' => [
            ['kind' => 'you', 'allyTeam' => 1, 'color' => [1, 0, 0]],
            ['kind' => 'ai', 'allyTeam' => 0, 'color' => [0, 1, 0]],
            ['kind' => 'ai', 'allyTeam' => 1, 'color' => [0, 0, 1]],
        ]]);

        $this->assertNotNull($composition);
        $this->assertSame([0, 1], array_map(fn ($t) => $t['allyTeam'], $composition['teams']));
        $this->assertCount(1, $composition['teams'][0]['members']);
        $this->assertCount(2, $composition['teams'][1]['members']);
        $this->assertSame(3, $composition['playingCount']);
    }

    public function test_a_participant_with_no_ally_team_is_grouped_as_team_0(): void
    {
        $composition = Previews::presetComposition(['participants' => [['kind' => 'you']]]);

        $this->assertSame([['allyTeam' => 0, 'members' => [['kind' => 'you']]]], $composition['teams']);
    }

    public function test_spectators_are_dropped_from_the_count_and_the_teams(): void
    {
        $composition = Previews::presetComposition(['participants' => [
            ['kind' => 'you', 'allyTeam' => 0, 'spectator' => true],
            ['kind' => 'ai', 'allyTeam' => 1, 'spectator' => false],
        ]]);

        $this->assertSame(1, $composition['playingCount']);
        $this->assertSame(
            [['allyTeam' => 1, 'members' => [['kind' => 'ai', 'allyTeam' => 1, 'spectator' => false]]]],
            $composition['teams'],
        );
    }

    public function test_a_spectator_only_preset_has_no_composition_to_show(): void
    {
        $this->assertNull(Previews::presetComposition(['participants' => [['kind' => 'you', 'spectator' => true]]]));
    }

    public function test_a_payload_with_no_participants_array_degrades_to_no_composition(): void
    {
        $this->assertNull(Previews::presetComposition([]));
        $this->assertNull(Previews::presetComposition(['participants' => 'not an array']));
        $this->assertNull(Previews::presetComposition(['participants' => []]));
    }

    public function test_a_colour_that_is_not_a_tuple_degrades_to_black_rather_than_throwing(): void
    {
        $this->assertSame('rgb(0 0 0)', Previews::participantColorCss(['r' => 255, 'g' => 80, 'b' => 80]));
        $this->assertSame('rgb(0 0 0)', Previews::participantColorCss('red'));
        $this->assertSame('rgb(0 0 0)', Previews::participantColorCss(null));
        $this->assertSame('rgb(0 0 0)', Previews::participantColorCss(5));
        $this->assertSame('rgb(255 0 0)', Previews::participantColorCss([1]));
    }

    public function test_a_pack_published_as_a_collection_names_every_game_it_carries(): void
    {
        $this->assertSame(
            ['Beyond All Reason 1.2', 'SplinterFaction 0.1.78'],
            Previews::setupPackGameNames(['games' => [['name' => 'Beyond All Reason 1.2'], ['name' => 'SplinterFaction 0.1.78']]]),
        );
    }

    public function test_a_pack_published_before_the_change_still_reads_its_single_game(): void
    {
        $this->assertSame(['Beyond All Reason 1.2'], Previews::setupPackGameNames(['game' => ['name' => 'Beyond All Reason 1.2']]));
    }

    public function test_a_pack_naming_no_game_at_all_reads_as_none(): void
    {
        $this->assertSame([], Previews::setupPackGameNames(['maps' => ['Comet Catcher Remake']]));
        $this->assertSame([], Previews::setupPackGameNames(['games' => []]));
    }

    public function test_a_pack_lists_its_maps_in_the_order_it_installs_them(): void
    {
        $this->assertSame(
            ['All That Smolders v1.2', 'All That Glitters v2.2.3'],
            Previews::setupPackMaps(['payload' => ['maps' => ['All That Smolders v1.2', 'All That Glitters v2.2.3']]]),
        );
        $this->assertSame([], Previews::setupPackMaps(['payload' => ['games' => []]]));
    }

    public function test_a_blank_map_name_is_dropped(): void
    {
        $this->assertSame(
            ['All That Glitters v2.2.3'],
            Previews::setupPackMaps(['payload' => ['maps' => ['', 'All That Glitters v2.2.3', null]]]),
        );
    }

    public function test_a_pack_naming_the_same_map_twice_installs_it_once(): void
    {
        $this->assertSame(
            ['All That Glitters v2.2.3'],
            Previews::setupPackMaps(['payload' => ['maps' => ['All That Glitters v2.2.3', 'All That Glitters v2.2.3']]]),
        );
    }

    public function test_a_container_with_no_payload_names_no_maps(): void
    {
        $this->assertSame([], Previews::setupPackMaps(null));
        $this->assertSame([], Previews::setupPackMaps([]));
    }

    public function test_an_engine_the_pack_does_not_pin_is_no_engine_at_all(): void
    {
        $this->assertNull(Previews::setupPackEngine(['engineVersion' => '.spring']));
        $this->assertNull(Previews::setupPackEngine(['maps' => []]));
        $this->assertSame('105.1.1-2590', Previews::setupPackEngine(['engineVersion' => '105.1.1-2590']));
    }

    public function test_an_entry_with_no_archive_name_is_dropped_rather_than_shown_blank(): void
    {
        $this->assertSame(
            ['SplinterFaction 0.1.78'],
            Previews::setupPackGameNames(['games' => [['shortname' => 'BAR'], ['name' => 'SplinterFaction 0.1.78']]]),
        );
    }
}
