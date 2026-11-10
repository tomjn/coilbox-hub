<?php

namespace Tests\Unit\Gallery;

use App\Domain\Gallery\Cards;
use App\Supabase\Supabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ported from the Next app's `cardTitles`, `itemCardArt`, `cardShapes`
 * (`itemHasCardShape` and the fetching cases), `cardCounts` and `cardPictures`
 * tests. The galaxy, run and layout generators run in the browser, so their
 * cases are not ported.
 */
class CardsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk']);
    }

    private function urls(string $needle): array
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), $needle))
            ->map(fn ($pair) => urldecode($pair[0]->url()))->values()->all();
    }

    // cardTitles

    public function test_a_title_with_no_twin_on_the_page_keeps_its_own_title_untouched(): void
    {
        $titles = Cards::titles([
            ['id' => '11111111-1111-1111-1111-111111111111', 'title' => 'SF Double Cold Fusion'],
            ['id' => '22222222-2222-2222-2222-222222222222', 'title' => 'Something else entirely'],
        ]);

        $this->assertSame(['title' => 'SF Double Cold Fusion', 'tail' => null], $titles['11111111-1111-1111-1111-111111111111']);
    }

    public function test_a_title_shared_by_two_rows_gets_a_different_tail_on_each(): void
    {
        $titles = Cards::titles([
            ['id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaab460ed', 'title' => 'SF Double Cold Fusion'],
            ['id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbabeed', 'title' => 'SF Double Cold Fusion'],
        ]);

        $this->assertSame(['title' => 'SF Double Cold Fusion', 'tail' => 'b460ed'], $titles['aaaaaaaa-aaaa-aaaa-aaaa-aaaaaab460ed']);
        $this->assertSame(['title' => 'SF Double Cold Fusion', 'tail' => 'babeed'], $titles['bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbabeed']);
    }

    public function test_a_title_shared_by_three_rows_gets_a_tail_on_all_three(): void
    {
        $items = [
            ['id' => '11111111-1111-1111-1111-111111111111', 'title' => 'Duplicate'],
            ['id' => '22222222-2222-2222-2222-222222222222', 'title' => 'Duplicate'],
            ['id' => '33333333-3333-3333-3333-333333333333', 'title' => 'Duplicate'],
        ];

        $titles = Cards::titles($items);
        foreach ($items as $item) {
            $this->assertNotNull($titles[$item['id']]['tail']);
        }
    }

    public function test_titles_are_compared_trimmed_and_case_folded(): void
    {
        $titles = Cards::titles([
            ['id' => '11111111-1111-1111-1111-111111111111', 'title' => 'Foo'],
            ['id' => '22222222-2222-2222-2222-222222222222', 'title' => 'foo '],
        ]);

        $this->assertNotNull($titles['11111111-1111-1111-1111-111111111111']['tail']);
        $this->assertNotNull($titles['22222222-2222-2222-2222-222222222222']['tail']);
    }

    public function test_the_tail_is_the_last_six_characters_of_the_id(): void
    {
        $titles = Cards::titles([
            ['id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaab460ed', 'title' => 'Duplicate'],
            ['id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbabeed', 'title' => 'Duplicate'],
        ]);

        $this->assertSame('b460ed', $titles['aaaaaaaa-aaaa-aaaa-aaaa-aaaaaab460ed']['tail']);
    }

    public function test_an_empty_page_returns_no_titles(): void
    {
        $this->assertSame([], Cards::titles([]));
    }

    // itemCardArt

    private const PICTURE = ['from' => 'static', 'url' => 'https://example.test/comet.webp', 'substituted' => false, 'width' => 512, 'height' => 512];

    private const PLACEHOLDER = ['from' => 'placeholder', 'name' => 'Comet Catcher', 'keyedOn' => 'map', 'footprint' => null];

    private const GALAXY_SHAPE = ['kind' => 'challenge', 'mode' => 'conquest', 'parts' => ['settings' => []], 'roles' => []];

    private const RUN_SHAPE = ['kind' => 'challenge', 'mode' => 'warpath', 'parts' => ['settings' => []], 'roles' => []];

    private const BLUEPRINT_SHAPE = ['kind' => 'blueprint', 'mode' => null, 'parts' => ['buildings' => []], 'roles' => []];

    public function test_names_its_map_is_true_only_for_a_scenario_or_a_preset_that_names_one(): void
    {
        $this->assertTrue(Cards::namesItsMap(['kind' => 'scenario', 'map_name' => 'Comet Catcher']));
        $this->assertTrue(Cards::namesItsMap(['kind' => 'preset', 'map_name' => 'Comet Catcher']));
        $this->assertFalse(Cards::namesItsMap(['kind' => 'setup-pack', 'map_name' => 'Comet Catcher']));
        $this->assertFalse(Cards::namesItsMap(['kind' => 'blueprint', 'map_name' => 'Comet Catcher']));
        $this->assertFalse(Cards::namesItsMap(['kind' => 'challenge', 'map_name' => 'Comet Catcher']));
        $this->assertFalse(Cards::namesItsMap(['kind' => 'scenario', 'map_name' => null]));
    }

    public function test_a_scenario_with_a_map_and_a_resolved_picture_gets_the_map(): void
    {
        $this->assertSame('map', Cards::artChoice(['kind' => 'scenario', 'map_name' => 'Comet Catcher'], self::PICTURE, null));
    }

    public function test_a_preset_with_a_map_and_a_resolved_picture_gets_the_map(): void
    {
        $this->assertSame('map', Cards::artChoice(['kind' => 'preset', 'map_name' => 'Comet Catcher'], self::PICTURE, null));
    }

    public function test_a_map_resolved_to_the_placeholder_still_counts_as_the_maps_own_art(): void
    {
        $this->assertSame('map', Cards::artChoice(['kind' => 'scenario', 'map_name' => 'Comet Catcher'], self::PLACEHOLDER, null));
    }

    public function test_a_scenario_or_preset_with_no_map_name_gets_the_plate(): void
    {
        $this->assertSame('plate', Cards::artChoice(['kind' => 'scenario', 'map_name' => null], self::PICTURE, null));
        $this->assertSame('plate', Cards::artChoice(['kind' => 'preset', 'map_name' => null], self::PICTURE, null));
    }

    public function test_a_setup_pack_never_draws_its_map_name(): void
    {
        $this->assertSame('plate', Cards::artChoice(['kind' => 'setup-pack', 'map_name' => 'Comet Catcher'], self::PICTURE, null));
    }

    public function test_a_blueprint_or_challenge_gets_the_plate(): void
    {
        $this->assertSame('plate', Cards::artChoice(['kind' => 'blueprint', 'map_name' => null], null, null));
        $this->assertSame('plate', Cards::artChoice(['kind' => 'challenge', 'map_name' => null], null, null));
    }

    public function test_a_row_nothing_was_looked_up_for_falls_back_to_the_plate_rather_than_throwing(): void
    {
        $this->assertSame('plate', Cards::artChoice(['kind' => 'scenario', 'map_name' => 'Comet Catcher'], null, null));
    }

    public function test_a_conquest_challenge_whose_shape_rebuilt_to_a_galaxy_draws_it(): void
    {
        $this->assertSame('art', Cards::artChoice(['kind' => 'challenge', 'map_name' => null], null, self::GALAXY_SHAPE));
    }

    public function test_a_warpath_challenge_whose_shape_rebuilt_to_a_run_draws_it(): void
    {
        $this->assertSame('art', Cards::artChoice(['kind' => 'challenge', 'map_name' => null], null, self::RUN_SHAPE));
    }

    public function test_a_challenge_with_no_shape_keeps_the_plate(): void
    {
        $this->assertSame('plate', Cards::artChoice(['kind' => 'challenge', 'map_name' => null], null, null));
    }

    public function test_a_blueprint_whose_shape_rebuilt_to_a_layout_draws_it(): void
    {
        $this->assertSame('art', Cards::artChoice(['kind' => 'blueprint', 'map_name' => null], null, self::BLUEPRINT_SHAPE));
    }

    public function test_a_blueprint_with_no_shape_keeps_the_plate(): void
    {
        $this->assertSame('plate', Cards::artChoice(['kind' => 'blueprint', 'map_name' => null], null, null));
    }

    public function test_a_galaxy_or_run_shape_on_a_row_that_is_not_a_challenge_is_not_drawn_as_art(): void
    {
        $this->assertSame('plate', Cards::artChoice(['kind' => 'scenario', 'map_name' => null], null, self::GALAXY_SHAPE));
    }

    public function test_a_blueprint_shape_on_a_row_that_is_not_a_blueprint_is_not_drawn_as_art(): void
    {
        $this->assertSame('plate', Cards::artChoice(['kind' => 'scenario', 'map_name' => null], null, self::BLUEPRINT_SHAPE));
    }

    // cardShapes: itemHasCardShape

    public function test_only_a_challenge_the_hub_can_draw_or_a_blueprint_has_a_shape_to_fetch(): void
    {
        $this->assertTrue(Cards::hasShape(['kind' => 'challenge', 'mode' => 'conquest']));
        $this->assertTrue(Cards::hasShape(['kind' => 'challenge', 'mode' => 'warpath']));
        $this->assertTrue(Cards::hasShape(['kind' => 'blueprint', 'mode' => null]));

        $this->assertFalse(Cards::hasShape(['kind' => 'challenge', 'mode' => 'siege']));
        $this->assertFalse(Cards::hasShape(['kind' => 'challenge', 'mode' => null]));
        $this->assertFalse(Cards::hasShape(['kind' => 'scenario', 'mode' => null]));
        $this->assertFalse(Cards::hasShape(['kind' => 'preset', 'mode' => null]));
        $this->assertFalse(Cards::hasShape(['kind' => 'setup-pack', 'mode' => null]));
    }

    // cardShapes: fetching

    private const EMPTY_PARTS = ['settings' => null, 'name' => null, 'ordered' => null, 'buildings' => null, 'terrain' => null, 'footprints' => null];

    private const CONQUEST = ['seed' => 12345, 'game' => ['shortname' => 'ba'], 'nodeCount' => 24, 'factionCount' => 2, 'layout' => 'spiral'];

    private const WARPATH = ['seed' => 4242, 'game' => ['shortname' => 'ba'], 'factionId' => 'arm', 'side' => 'Arm', 'length' => 'long', 'difficulty' => 3, 'ascension' => 0];

    private function blueprintParts(): array
    {
        return [
            'name' => 'SF Double Cold Fusion',
            'ordered' => true,
            'buildings' => [
                ['def' => 'armsolar', 'offset' => ['x' => 0, 'z' => 0], 'facing' => 0],
                ['def' => 'armmex', 'offset' => ['x' => 96, 'z' => 0], 'facing' => 1],
            ],
            'footprints' => ['armsolar' => ['x' => 5, 'z' => 5], 'armmex' => ['x' => 3, 'z' => 3]],
        ];
    }

    private function unitRow(string $name, array $stats): array
    {
        return array_merge([
            'unit_name' => $name, 'build_options' => [], 'builder' => null, 'weapon' => null,
            'energyMake' => null, 'windGenerator' => null, 'tidalGenerator' => null, 'energyUpkeep' => null,
        ], $stats);
    }

    /** Answers item reads from the rows and game_unit reads with the units on the first page only. */
    private function fakeShapes(array $rows, array $units = []): void
    {
        Http::fake(function (Request $request) use ($rows, $units) {
            if (str_contains($request->url(), '/rest/v1/game_unit')) {
                return Http::response(str_contains($request->url(), 'offset=0') ? $units : [], 200);
            }

            return Http::response($rows, 200);
        });
    }

    public function test_a_whole_page_costs_one_query_naming_only_the_rows_worth_asking_about(): void
    {
        $this->fakeShapes([
            ['id' => 'a', ...self::EMPTY_PARTS, 'settings' => self::CONQUEST],
            ['id' => 'b', ...self::EMPTY_PARTS, 'settings' => self::WARPATH],
            ['id' => 'c', ...self::EMPTY_PARTS, ...$this->blueprintParts()],
        ]);

        $shapes = Cards::shapes(Supabase::anon(), [
            ['id' => 'a', 'kind' => 'challenge', 'mode' => 'conquest', 'game_key' => null],
            ['id' => 'b', 'kind' => 'challenge', 'mode' => 'warpath', 'game_key' => null],
            ['id' => 'c', 'kind' => 'blueprint', 'mode' => null, 'game_key' => null],
            ['id' => 'd', 'kind' => 'scenario', 'mode' => null, 'game_key' => null],
            ['id' => 'e', 'kind' => 'setup-pack', 'mode' => null, 'game_key' => null],
        ]);

        $asked = $this->urls('/rest/v1/item');
        $this->assertCount(1, $asked);
        $this->assertStringContainsString('id=in.(a,b,c)', $asked[0]);
        $this->assertSame(['a', 'b', 'c'], array_keys($shapes));
        $this->assertSame('conquest', $shapes['a']['mode']);
        $this->assertSame(self::CONQUEST, $shapes['a']['parts']['settings']);
        $this->assertSame('warpath', $shapes['b']['mode']);
        $this->assertSame('blueprint', $shapes['c']['kind']);
        $this->assertArrayNotHasKey('d', $shapes);
    }

    public function test_a_page_with_nothing_to_draw_makes_no_shape_query_at_all(): void
    {
        $this->fakeShapes([]);

        $shapes = Cards::shapes(Supabase::anon(), [
            ['id' => 'a', 'kind' => 'scenario', 'mode' => null, 'game_key' => null],
            ['id' => 'b', 'kind' => 'preset', 'mode' => null, 'game_key' => null],
        ]);

        Http::assertNothingSent();
        $this->assertSame([], $shapes);
    }

    public function test_a_shape_row_the_query_answered_nothing_for_is_absent(): void
    {
        $this->fakeShapes([]);

        $shapes = Cards::shapes(Supabase::anon(), [['id' => 'a', 'kind' => 'challenge', 'mode' => 'conquest', 'game_key' => null]]);

        $this->assertSame([], $shapes);
        $this->assertArrayNotHasKey('a', $shapes);
    }

    public function test_a_blueprint_card_carries_the_roles_of_its_own_buildings_read_once_per_game(): void
    {
        $this->fakeShapes(
            [
                ['id' => 'c', ...self::EMPTY_PARTS, ...$this->blueprintParts()],
                ['id' => 'f', ...self::EMPTY_PARTS, ...$this->blueprintParts()],
            ],
            [
                $this->unitRow('ArmSolar', ['energyMake' => null, 'energyUpkeep' => -20]),
                $this->unitRow('armmex', []),
                $this->unitRow('armllt', ['weapon' => ['range' => 430]]),
            ],
        );

        $shapes = Cards::shapes(Supabase::anon(), [
            ['id' => 'c', 'kind' => 'blueprint', 'mode' => null, 'game_key' => 'ba'],
            ['id' => 'f', 'kind' => 'blueprint', 'mode' => null, 'game_key' => 'ba'],
        ]);

        $firstPages = array_filter($this->urls('/rest/v1/game_unit'), fn ($url) => str_contains($url, 'offset=0'));
        $this->assertCount(1, $firstPages);
        $this->assertStringContainsString('game.shortname=eq.ba', array_values($firstPages)[0]);
        // Only this layout's defs. The turret is in the game but not the layout.
        $this->assertSame(['armsolar' => 'energy'], $shapes['c']['roles']);
    }

    public function test_a_blueprint_with_no_game_is_drawn_without_roles_and_asks_the_catalog_nothing(): void
    {
        $this->fakeShapes([['id' => 'c', ...self::EMPTY_PARTS, ...$this->blueprintParts()]]);

        $shapes = Cards::shapes(Supabase::anon(), [['id' => 'c', 'kind' => 'blueprint', 'mode' => null, 'game_key' => null]]);

        $this->assertSame([], $this->urls('/rest/v1/game_unit'));
        $this->assertSame([], $shapes['c']['roles']);
    }

    // cardCounts

    public function test_a_whole_counts_page_costs_one_query_naming_only_the_mod_project_rows(): void
    {
        Http::fake(['https://sb.test/rest/v1/item*' => Http::response([[
            'id' => 'a', 'overrides' => ['armsolar' => ['maxdamage' => 1]], 'menus' => null, 'text' => null,
            'disabled' => ['armmex'], 'clone_count' => 3,
        ]], 200)]);

        $counts = Cards::counts(Supabase::anon(), [
            ['id' => 'a', 'kind' => 'mod-project'],
            ['id' => 'b', 'kind' => 'blueprint'],
            ['id' => 'c', 'kind' => 'preset'],
        ]);

        $asked = $this->urls('/rest/v1/item');
        $this->assertCount(1, $asked);
        $this->assertStringContainsString('id=in.(a)', $asked[0]);
        $this->assertSame(['unitsTouched' => 1, 'fields' => 1, 'clones' => 3, 'menuOps' => 0, 'disabled' => 1], $counts['a']);
        $this->assertArrayNotHasKey('b', $counts);
    }

    public function test_a_page_with_no_mod_project_on_it_makes_no_query_at_all(): void
    {
        Http::fake();

        $counts = Cards::counts(Supabase::anon(), [['id' => 'a', 'kind' => 'blueprint'], ['id' => 'b', 'kind' => 'preset']]);

        Http::assertNothingSent();
        $this->assertSame([], $counts);
    }

    public function test_a_counts_row_the_query_answered_nothing_for_is_absent(): void
    {
        Http::fake(['https://sb.test/rest/v1/item*' => Http::response([], 200)]);

        $counts = Cards::counts(Supabase::anon(), [['id' => 'a', 'kind' => 'mod-project']]);

        $this->assertSame([], $counts);
    }

    public function test_the_clone_count_comes_from_the_database_and_no_clone_definition_is_requested(): void
    {
        Http::fake(['https://sb.test/rest/v1/item*' => Http::response([], 200)]);

        Cards::counts(Supabase::anon(), [['id' => 'a', 'kind' => 'mod-project']]);

        $asked = $this->urls('/rest/v1/item');
        $this->assertCount(1, $asked);
        $this->assertStringContainsString('clone_count', $asked[0]);
        $this->assertStringNotContainsString('edits->clones', $asked[0]);
    }

    public function test_a_row_with_a_null_clone_count_reads_as_no_clones(): void
    {
        Http::fake(['https://sb.test/rest/v1/item*' => Http::response([[
            'id' => 'a', 'overrides' => null, 'menus' => null, 'text' => null, 'disabled' => null, 'clone_count' => null,
        ]], 200)]);

        $counts = Cards::counts(Supabase::anon(), [['id' => 'a', 'kind' => 'mod-project']]);

        $this->assertSame(0, $counts['a']['clones']);
    }

    // cardPictures

    private function comet(): array
    {
        return [
            'game' => null, 'unit_name' => null, 'map_name' => 'Comet Catcher', 'variant' => 'minimap',
            'tier' => 'static', 'path' => 'maps/comet.webp', 'width' => 512, 'height' => 512, 'moderation' => 'approved',
        ];
    }

    public function test_only_a_scenario_or_a_preset_that_names_a_map_is_looked_up_at_all(): void
    {
        Http::fake();

        $pictures = Cards::mapPictures(Supabase::anon(), [
            ['kind' => 'setup-pack', 'map_name' => 'Comet Catcher'],
            ['kind' => 'blueprint', 'map_name' => null],
            ['kind' => 'scenario', 'map_name' => null],
        ]);

        $this->assertSame([], $pictures);
        Http::assertNothingSent();
    }

    public function test_a_picture_the_hub_holds_resolves_for_the_scenarios_map(): void
    {
        Http::fake(['https://sb.test/rest/v1/asset*' => Http::response([$this->comet()], 200)]);

        $pictures = Cards::mapPictures(Supabase::anon(), [['kind' => 'scenario', 'map_name' => 'Comet Catcher']]);

        $this->assertSame('static', $pictures['Comet Catcher']['from']);
    }

    public function test_a_map_the_hub_holds_nothing_for_still_gets_an_entry_the_placeholder(): void
    {
        Http::fake(['https://sb.test/rest/v1/asset*' => Http::response([], 200)]);

        $pictures = Cards::mapPictures(Supabase::anon(), [['kind' => 'preset', 'map_name' => 'Unknown Map']]);

        $this->assertSame('placeholder', $pictures['Unknown Map']['from']);
    }

    public function test_two_rows_naming_the_same_map_cost_one_lookup_not_two(): void
    {
        Http::fake(['https://sb.test/rest/v1/asset*' => Http::response([$this->comet()], 200)]);

        $pictures = Cards::mapPictures(Supabase::anon(), [
            ['kind' => 'scenario', 'map_name' => 'Comet Catcher'],
            ['kind' => 'preset', 'map_name' => 'Comet Catcher'],
        ]);

        $this->assertCount(1, $pictures);
        $this->assertCount(1, $this->urls('/rest/v1/asset'));
    }

    // projectCountLine (no Next test, covered here for the PHP helper)

    public function test_the_project_count_line_leaves_out_what_nothing_changed(): void
    {
        $this->assertSame('1 unit · 3 fields', Cards::projectCountLine(['unitsTouched' => 1, 'fields' => 3, 'clones' => 0, 'menuOps' => 0, 'disabled' => 0]));
        $this->assertSame('', Cards::projectCountLine([]));
    }
}
