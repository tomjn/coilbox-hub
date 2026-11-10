<?php

namespace Tests\Unit\Games;

use App\Domain\Games\Catalog;
use Tests\TestCase;

/**
 * Ported from `lib/games/catalog.test.ts`: the two decisions the catalog makes
 * on every reader's behalf, what counts as a usable link and what counts as
 * retired (#223).
 */
class CatalogTest extends TestCase
{
    public function test_links_that_are_not_an_array_of_labelled_rows_contribute_nothing(): void
    {
        $this->assertSame([], Catalog::parseGameLinks('[]'));
        $this->assertSame([], Catalog::parseGameLinks(['label' => 'forum']));
        $this->assertSame([], Catalog::parseGameLinks(null));
        $this->assertSame([], Catalog::parseGameLinks([null, 'forum', 42]));
    }

    public function test_a_link_needs_both_a_non_empty_label_and_a_non_empty_url(): void
    {
        $this->assertSame([['label' => 'Forum', 'url' => 'https://example.test']], Catalog::parseGameLinks([
            ['label' => 'Forum', 'url' => 'https://example.test'],
            ['label' => '', 'url' => 'https://example.test'],
            ['label' => 'Wiki', 'url' => ''],
            ['label' => 'Code', 'url' => '   '],
        ]));
    }

    public function test_one_malformed_entry_costs_itself_and_not_the_rest(): void
    {
        $this->assertSame(
            [['label' => 'Forum', 'url' => 'https://example.test']],
            Catalog::parseGameLinks([['label' => 'Forum', 'url' => 'https://example.test'], ['label' => 'Wiki']]),
        );
    }

    public function test_retirement_is_the_presence_of_a_stamp_not_a_flag(): void
    {
        $this->assertFalse(Catalog::gameUnitIsRetired(['removed_at' => null]));
        $this->assertTrue(Catalog::gameUnitIsRetired(['removed_at' => '2026-08-21T00:00:00Z']));
    }

    public function test_conquest_factions_that_are_not_an_array_of_named_entries_contribute_nothing(): void
    {
        $this->assertSame([], Catalog::parseConquestFactions('[]'));
        $this->assertSame([], Catalog::parseConquestFactions(['name' => 'Arm']));
        $this->assertSame([], Catalog::parseConquestFactions(null));
        $this->assertSame([], Catalog::parseConquestFactions([null, 'Arm', 42]));
        $this->assertSame([], Catalog::parseConquestFactions([['color' => '#2f7dff']]));
        $this->assertSame([], Catalog::parseConquestFactions([['name' => '  ']]));
    }

    public function test_a_colour_and_a_side_are_kept_only_when_they_are_usable_and_a_name_alone_still_counts(): void
    {
        $this->assertSame([
            ['name' => 'Arm', 'color' => '#2f7dff', 'side' => 'ARM'],
            ['name' => 'Core'],
            ['name' => 'Cortex'],
        ], Catalog::parseConquestFactions([
            ['name' => 'Arm', 'color' => '#2f7dff', 'side' => 'ARM'],
            ['name' => 'Core', 'color' => 'not-a-colour', 'side' => '  '],
            ['name' => '  Cortex  '],
        ]));
    }

    public function test_a_colour_with_a_trailing_newline_is_not_a_colour(): void
    {
        $this->assertSame([['name' => 'Arm']], Catalog::parseConquestFactions([['name' => 'Arm', 'color' => "#2f7dff\n"]]));
    }

    public function test_two_factions_may_name_the_same_in_game_side(): void
    {
        $factions = [
            ['name' => 'House Arm-1', 'side' => 'ARM'],
            ['name' => 'House Arm-2', 'side' => 'ARM'],
        ];

        $this->assertSame($factions, Catalog::parseConquestFactions($factions));
    }
}
