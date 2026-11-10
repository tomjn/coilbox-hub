<?php

namespace Tests\Unit\Games;

use App\Domain\Games\Morph;
use Tests\TestCase;

/** Ported from `lib/games/morph.test.ts`. The graph is not a chain: it branches, converges and can cycle. */
class MorphTest extends TestCase
{
    /** A unit row as the readers hand it over: whatever the catalog stored. */
    private function unit(string $name, string ...$into): array
    {
        return ['unit_name' => $name, 'morph_targets' => array_map(fn ($target) => ['into' => $target], $into)];
    }

    private function names(array $stages): array
    {
        return array_column($stages, 'name');
    }

    public function test_a_chain_is_one_group_based_on_the_unit_nothing_morphs_into(): void
    {
        $groups = Morph::morphGroups([$this->unit('armcom1', 'armcom2'), $this->unit('armcom2', 'armcom3'), $this->unit('armcom3')]);

        $this->assertCount(1, $groups);
        $this->assertSame('armcom1', $groups[0]['base']);
        $this->assertSame(['armcom1', 'armcom2', 'armcom3'], $this->names($groups[0]['stages']));
    }

    public function test_each_stage_says_which_stage_it_is_reached_from_and_the_base_says_none(): void
    {
        $groups = Morph::morphGroups([$this->unit('armcom1', 'armcom2'), $this->unit('armcom2', 'armcom3'), $this->unit('armcom3')]);

        $this->assertSame([null, 'armcom1', 'armcom2'], array_column($groups[0]['stages'], 'from'));
    }

    public function test_a_stage_that_morphs_into_two_things_lists_both_alphabetically(): void
    {
        $groups = Morph::morphGroups([$this->unit('armcom', 'zeta', 'alpha'), $this->unit('alpha'), $this->unit('zeta')]);

        $this->assertSame(['armcom', 'alpha', 'zeta'], $this->names($groups[0]['stages']));
        $this->assertSame(['alpha', 'zeta'], array_column($groups[0]['stages'][0]['into'], 'into'));
    }

    public function test_a_cycle_has_no_unit_nothing_morphs_into_so_the_first_by_code_unit_heads_it(): void
    {
        $groups = Morph::morphGroups([$this->unit('beta', 'alpha'), $this->unit('alpha', 'beta')]);

        $this->assertCount(1, $groups);
        $this->assertSame('alpha', $groups[0]['base']);
        // Every member once. A walk that revisits would be the million drawings #257 cost the build tree.
        $this->assertSame(['alpha', 'beta'], $this->names($groups[0]['stages']));
    }

    public function test_two_units_morphing_into_one_thing_are_one_group_headed_by_the_first_root(): void
    {
        $groups = Morph::morphGroups([$this->unit('corvp', 'shared'), $this->unit('armvp', 'shared'), $this->unit('shared')]);

        $this->assertCount(1, $groups);
        $this->assertSame('armvp', $groups[0]['base']);
        // corvp is not reachable from armvp, so it lands after what is, reached from nothing.
        $this->assertSame(['armvp', 'shared', 'corvp'], $this->names($groups[0]['stages']));
        $this->assertNull($groups[0]['stages'][2]['from']);
    }

    public function test_an_edge_to_a_unit_nobody_holds_is_dropped_rather_than_grouping_a_ghost(): void
    {
        $groups = Morph::morphGroups([$this->unit('armcom1', 'armcom2', 'typo'), $this->unit('armcom2')]);

        $this->assertSame(['armcom1', 'armcom2'], $this->names($groups[0]['stages']));
        $this->assertSame(['armcom2'], array_column($groups[0]['stages'][0]['into'], 'into'));
    }

    public function test_a_unit_that_turns_into_nothing_and_nothing_turns_into_is_not_a_group(): void
    {
        $this->assertSame([], Morph::morphGroups([$this->unit('armsolar'), $this->unit('armmex')]));
    }

    public function test_keys_match_case_insensitively_and_the_stored_spelling_rides_along(): void
    {
        $groups = Morph::morphGroups([
            ['unit_name' => 'ArmCom1', 'morph_targets' => [['into' => 'ARMCOM2']]],
            ['unit_name' => 'armcom2', 'morph_targets' => []],
        ]);

        $this->assertSame('armcom1', $groups[0]['base']);
        $this->assertSame(['ArmCom1', 'armcom2'], array_column($groups[0]['stages'], 'unit_name'));
    }

    public function test_the_games_own_words_for_what_a_morph_costs_ride_the_edge(): void
    {
        $groups = Morph::morphGroups([
            ['unit_name' => 'armcom1', 'morph_targets' => [['into' => 'armcom2', 'metal' => 600, 'time' => 30, 'tech' => 'Level 2']]],
            ['unit_name' => 'armcom2', 'morph_targets' => []],
        ]);

        $expected = ['metal' => 600, 'time' => 30, 'tech' => 'Level 2'];
        $this->assertSame($expected, $groups[0]['stages'][0]['into'][0]['conditions']);
        // The stage reached carries the same conditions.
        $this->assertSame($expected, $groups[0]['stages'][1]['conditions']);
    }

    public function test_morph_targets_that_are_not_a_list_of_objects_naming_a_unit_are_ignored(): void
    {
        $groups = Morph::morphGroups([
            ['unit_name' => 'a', 'morph_targets' => 'nonsense'],
            ['unit_name' => 'b', 'morph_targets' => [null, ['into' => 7], [], ['into' => 'c']]],
            ['unit_name' => 'c', 'morph_targets' => null],
        ]);

        $this->assertSame(['b'], array_column($groups, 'base'));
        $this->assertSame(['b', 'c'], $this->names($groups[0]['stages']));
    }

    public function test_two_separate_chains_are_two_groups_in_base_order(): void
    {
        $groups = Morph::morphGroups([
            $this->unit('corcom1', 'corcom2'),
            $this->unit('corcom2'),
            $this->unit('armcom1', 'armcom2'),
            $this->unit('armcom2'),
        ]);

        $this->assertSame(['armcom1', 'corcom1'], array_column($groups, 'base'));
    }

    public function test_the_base_index_answers_for_every_member_including_the_base(): void
    {
        $groups = Morph::morphGroups([
            $this->unit('armcom1', 'armcom2'),
            $this->unit('armcom2', 'armcom3'),
            $this->unit('armcom3'),
            $this->unit('armsolar'),
        ]);

        $bases = Morph::baseIndex($groups);
        $this->assertSame('armcom1', $bases['armcom1']);
        $this->assertSame('armcom1', $bases['armcom3']);
        // A unit in no group is absent.
        $this->assertArrayNotHasKey('armsolar', $bases);
    }

    public function test_a_numeric_unit_name_still_groups(): void
    {
        $groups = Morph::morphGroups([$this->unit('1', '2'), $this->unit('2')]);

        $this->assertSame('1', $groups[0]['base']);
        $this->assertSame(['1', '2'], $this->names($groups[0]['stages']));
    }
}
