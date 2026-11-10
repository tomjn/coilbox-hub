<?php

namespace Tests\Unit\Games;

use App\Domain\Games\Tree;
use App\Supabase\Supabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ported from `lib/games/tree.test.ts`: the grouping the build tree shows
 * (#228), which carries upstream's rules. The first root to reach a unit
 * claims it, a unit two builders make is listed by both, dangling edges are
 * dropped, and units no root reaches stay visible in an ungrouped block.
 */
class TreeTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function units(): array
    {
        return [
            ['unit_name' => 'armcom', 'full_name' => 'Commander', 'build_options' => ['armsolar', 'armmex', 'ghost']],
            ['unit_name' => 'corcom', 'full_name' => 'Commander', 'build_options' => ['armsolar']],
            ['unit_name' => 'armsolar', 'full_name' => 'Solar Collector', 'build_options' => []],
            ['unit_name' => 'armmex', 'full_name' => 'Metal Extractor', 'build_options' => ['ARMSOLAR']],
            ['unit_name' => 'armfark', 'full_name' => 'Fark', 'build_options' => []],
        ];
    }

    /** @param list<array<string, mixed>> $nodes @return list<string> */
    private function names(array $nodes): array
    {
        return array_column($nodes, 'name');
    }

    /** @param list<array<string, mixed>> $nodes */
    private function node(array $nodes, string $name): ?array
    {
        foreach ($nodes as $node) {
            if ($node['name'] === $name) {
                return $node;
            }
        }

        return null;
    }

    public function test_the_first_root_to_reach_a_unit_claims_it_and_shared_units_are_shared(): void
    {
        $tree = Tree::buildTree($this->units(), ['armcom', 'corcom']);

        $this->assertSame(['armcom', 'corcom'], array_column($tree['factions'], 'root'));
        // armsolar is reached by both. armcom's walk got there first.
        $this->assertSame(['armcom', 'armmex', 'armsolar'], $this->names($tree['factions'][0]['units']));
        $this->assertSame(['corcom'], $this->names($tree['factions'][1]['units']));
    }

    public function test_a_unit_two_builders_make_is_listed_by_both_of_them(): void
    {
        $tree = Tree::buildTree($this->units(), ['armcom', 'corcom']);

        $this->assertContains('armsolar', $this->node($tree['factions'][0]['units'], 'armcom')['builds']);
        $this->assertContains('armsolar', $this->node($tree['factions'][1]['units'], 'corcom')['builds']);
    }

    public function test_dangling_edges_are_dropped_from_what_a_unit_builds(): void
    {
        $tree = Tree::buildTree($this->units(), ['armcom']);

        $this->assertSame(['armmex', 'armsolar'], $this->node($tree['factions'][0]['units'], 'armcom')['builds']);
    }

    public function test_a_start_unit_nobody_holds_heads_nothing(): void
    {
        $tree = Tree::buildTree($this->units(), ['armcom', 'legcom']);

        $this->assertSame(['armcom'], array_column($tree['factions'], 'root'));
    }

    public function test_units_no_root_reaches_stay_visible_as_ungrouped_unless_nothing_builds_them_either(): void
    {
        $tree = Tree::buildTree($this->units(), ['corcom']);

        // armsolar is reached from corcom, so it belongs to cortex. armmex is
        // unreachable but armcom builds it, so it stays visible. armcom itself
        // and armfark have no incoming edges at all (archive ghosts, #280), so
        // they hide rather than head an ungrouped block.
        $this->assertSame(['armmex'], $this->names($tree['ungrouped']));
    }

    public function test_a_reference_kept_only_by_an_unreachable_unit_still_counts_as_built(): void
    {
        // An orphaned cluster that points at each other stays visible as a
        // cluster. Hiding chain by chain would take the whole island out one
        // walk at a time.
        $island = [
            ['unit_name' => 'root', 'full_name' => 'Root', 'build_options' => ['a']],
            ['unit_name' => 'a', 'full_name' => 'A', 'build_options' => ['b']],
            ['unit_name' => 'b', 'full_name' => 'B', 'build_options' => ['a']],
            ['unit_name' => 'ghost', 'full_name' => 'Ghost', 'build_options' => []],
        ];
        $tree = Tree::buildTree($island, ['missing']);

        $this->assertSame(['a', 'b'], $this->names($tree['ungrouped']));
    }

    public function test_keys_match_case_insensitively_because_def_keys_arrive_however_they_like(): void
    {
        $tree = Tree::buildTree($this->units(), ['ARMCOM']);

        $this->assertCount(1, $tree['factions']);
        $this->assertSame('armcom', $tree['factions'][0]['root']);
    }

    /** @return list<array<string, mixed>> */
    private function armedUnits(): array
    {
        return [
            ['unit_name' => 'armbase', 'full_name' => 'Base', 'build_options' => ['armllt', 'armemp', 'armck'], 'stats' => []],
            ['unit_name' => 'armllt', 'full_name' => 'Light Laser Tower', 'build_options' => [], 'stats' => ['weapons' => [['range' => 210]]]],
            ['unit_name' => 'armsolar', 'full_name' => 'Solar Collector', 'build_options' => [], 'stats' => []],
            ['unit_name' => 'armemp', 'full_name' => 'EMP Missile', 'build_options' => [], 'stats' => ['weapons' => []]],
            ['unit_name' => 'armck', 'full_name' => 'Constructor Kbot', 'build_options' => ['armsolar'], 'stats' => ['health' => 200]],
        ];
    }

    /** @return array<string, bool> */
    private function armedMap(array $tree): array
    {
        $armed = [];
        foreach ($tree['factions'][0]['units'] as $unit) {
            $armed[$unit['name']] = $unit['armed'];
        }

        return $armed;
    }

    public function test_a_weapons_summary_makes_a_unit_armed(): void
    {
        $armed = $this->armedMap(Tree::buildTree($this->armedUnits(), ['armbase']));

        $this->assertTrue($armed['armllt']);
    }

    public function test_no_summary_an_empty_one_or_none_at_all_leaves_a_unit_unarmed(): void
    {
        $armed = $this->armedMap(Tree::buildTree($this->armedUnits(), ['armbase']));

        $this->assertFalse($armed['armsolar']);
        $this->assertFalse($armed['armemp']);
        $this->assertFalse($armed['armck']);
    }

    /** @return list<array<string, mixed>> */
    private function morphUnits(): array
    {
        return [
            ['unit_name' => 'armcom1', 'full_name' => 'Commander', 'build_options' => ['armsolar'], 'morph_targets' => [['into' => 'armcom2']]],
            [
                'unit_name' => 'armcom2',
                'full_name' => 'Commander, level 2',
                'build_options' => ['armsolar', 'armvp'],
                'morph_targets' => [['into' => 'armcom3']],
                'stats' => ['weapons' => [['range' => 300]]],
            ],
            ['unit_name' => 'armcom3', 'full_name' => 'Commander, level 3', 'build_options' => ['armfus'], 'morph_targets' => []],
            ['unit_name' => 'armsolar', 'full_name' => 'Solar Collector', 'build_options' => [], 'morph_targets' => []],
            ['unit_name' => 'armfus', 'full_name' => 'Fusion Plant', 'build_options' => [], 'morph_targets' => []],
            ['unit_name' => 'armvp', 'full_name' => 'Vehicle Plant', 'build_options' => ['armcom3'], 'morph_targets' => []],
        ];
    }

    public function test_a_morph_chain_is_one_node_and_the_levels_are_not_nodes_of_their_own(): void
    {
        $tree = Tree::buildTree($this->morphUnits(), ['armcom1']);
        $named = $this->names($tree['factions'][0]['units']);

        $this->assertContains('armcom1', $named);
        $this->assertNotContains('armcom2', $named);
        $this->assertNotContains('armcom3', $named);
        $this->assertStringNotContainsString('armcom2', json_encode($tree['ungrouped']));
    }

    public function test_what_the_levels_build_folds_into_the_one_node(): void
    {
        $tree = Tree::buildTree($this->morphUnits(), ['armcom1']);

        // armsolar from level one, armvp from level two, armfus from level three.
        $this->assertSame(['armfus', 'armsolar', 'armvp'], $this->node($tree['factions'][0]['units'], 'armcom1')['builds']);
    }

    public function test_a_node_says_how_many_stages_it_stands_for_and_an_ordinary_unit_says_one(): void
    {
        $tree = Tree::buildTree($this->morphUnits(), ['armcom1']);

        $this->assertSame(3, $this->node($tree['factions'][0]['units'], 'armcom1')['stages']);
        $this->assertSame(1, $this->node($tree['factions'][0]['units'], 'armsolar')['stages']);
    }

    public function test_a_build_option_naming_a_level_points_at_the_levels_base(): void
    {
        $tree = Tree::buildTree($this->morphUnits(), ['armcom1']);

        // The vehicle plant reports building armcom3. The reader sees it
        // pointing at the commander, which is the unit armcom3 is a stage of.
        $this->assertSame(['armcom1'], $this->node($tree['factions'][0]['units'], 'armvp')['builds']);
    }

    public function test_a_node_is_armed_when_any_of_its_stages_is(): void
    {
        $tree = Tree::buildTree($this->morphUnits(), ['armcom1']);

        // Only level two reports weapons. The node stands for the whole life of
        // the unit, so the node shoots.
        $this->assertTrue($this->node($tree['factions'][0]['units'], 'armcom1')['armed']);
    }

    public function test_a_start_unit_naming_a_level_heads_the_group_its_level_belongs_to(): void
    {
        $tree = Tree::buildTree($this->morphUnits(), ['armcom2']);

        $this->assertSame(['armcom1'], array_column($tree['factions'], 'root'));
    }

    /**
     * Leaving retired units out is a null check, and PostgREST spells that
     * `is.null` (#255). `removed_at=eq.null` is refused, and `loadTree` answers
     * null on a refused read, which the page turns into a 404.
     *
     * @return list<array<string, mixed>>
     */
    private function stored(): array
    {
        return [
            ['unit_name' => 'armcom', 'full_name' => 'Commander', 'build_options' => ['armsolar'], 'faction_key' => 'arm', 'removed_at' => null],
            ['unit_name' => 'armsolar', 'full_name' => 'Solar Collector', 'build_options' => [], 'faction_key' => 'arm', 'removed_at' => null],
            ['unit_name' => 'corcom', 'full_name' => 'Commander', 'build_options' => [], 'faction_key' => 'core', 'removed_at' => null],
            ['unit_name' => 'armbrawl', 'full_name' => 'Brawler', 'build_options' => [], 'faction_key' => 'arm', 'removed_at' => '2026-01-01'],
        ];
    }

    /**
     * A hub holding `$rows` for one game, answering about null the way
     * PostgREST does: `is.null` is the null check, and `eq.null` is refused
     * with a 400. A fake that quietly accepted it would pass while every tree
     * stayed a 404. The whole game is read a page at a time, so the fake
     * serves a window of at most `$cap` rows.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $startUnits
     */
    private function fakeHub(array $rows, array $startUnits, int $cap = 1000): void
    {
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk']);

        Http::fake(function (Request $request) use ($rows, $startUnits, $cap) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (str_contains($url, '/rest/v1/game?')) {
                return Http::response([['start_units' => $startUnits]]);
            }

            if (str_contains($url, 'eq.null') || ($query['removed_at'] ?? null) === 'eq.null') {
                return Http::response(['message' => 'failed to parse filter (eq.null)'], 400);
            }
            $held = $rows;
            if (($query['removed_at'] ?? null) === 'is.null') {
                $held = array_values(array_filter($held, fn ($row) => $row['removed_at'] === null));
            }
            usort($held, fn ($a, $b) => strcmp($a['unit_name'], $b['unit_name']));
            $offset = (int) ($query['offset'] ?? 0);
            $limit = min((int) ($query['limit'] ?? $cap), $cap);

            return Http::response(array_slice($held, $offset, $limit));
        });
    }

    public function test_the_tree_draws_from_the_units_the_hub_has_not_retired(): void
    {
        $this->fakeHub($this->stored(), ['armcom']);

        $tree = Tree::loadTree(Supabase::anon(), 'BA');

        $this->assertNotNull($tree);
        $this->assertSame(['armcom'], array_column($tree['factions'], 'root'));
        $this->assertSame(['armcom', 'armsolar'], $this->names($tree['factions'][0]['units']));
    }

    public function test_a_retired_unit_is_left_out_of_the_tree_rather_than_taking_it_down(): void
    {
        $this->fakeHub($this->stored(), ['armcom']);

        $this->assertStringNotContainsString('armbrawl', json_encode(Tree::loadTree(Supabase::anon(), 'BA')));
    }

    public function test_a_game_larger_than_one_response_still_draws_a_whole_tree(): void
    {
        // PostgREST caps a response at max_rows. A read that took one request
        // for the lot did not draw a smaller tree, it drew a wrong one: every
        // build option pointing past the cap read as dangling and its branch
        // vanished.
        $wide = [['unit_name' => 'armcom', 'full_name' => 'Commander', 'build_options' => ['zzlast'], 'faction_key' => 'arm', 'removed_at' => null]];
        for ($index = 0; $index < 8; $index++) {
            $wide[] = ['unit_name' => 'filler'.$index, 'full_name' => 'Filler '.$index, 'build_options' => [], 'faction_key' => 'arm', 'removed_at' => null];
        }
        $wide[] = ['unit_name' => 'zzlast', 'full_name' => 'Last Unit', 'build_options' => [], 'faction_key' => 'arm', 'removed_at' => null];
        $this->fakeHub($wide, ['armcom'], 3);

        $tree = Tree::loadTree(Supabase::anon(), 'BA');

        // zzlast sorts last, so it is on the final page. Its branch has to survive.
        $this->assertSame(['zzlast'], $this->node($tree['factions'][0]['units'], 'armcom')['builds']);
        $this->assertSame(['armcom', 'zzlast'], $this->names($tree['factions'][0]['units']));
    }

    public function test_a_faction_scope_keeps_the_whole_walk_to_that_sides_units(): void
    {
        $this->fakeHub($this->stored(), ['armcom', 'corcom']);

        $tree = Tree::loadTree(Supabase::anon(), 'BA', null, 'core');

        $this->assertSame(['corcom'], array_column($tree['factions'], 'root'));
        // The other side's units do not appear anywhere in the answer, not even
        // as build options the walk could have followed across.
        $this->assertStringNotContainsString('armcom', json_encode($tree));
    }

    public function test_a_failed_read_is_a_null_tree(): void
    {
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk']);
        Http::fake(['*/rest/v1/*' => Http::response(['message' => 'boom'], 500)]);

        $this->assertNull(Tree::loadTree(Supabase::anon(), 'BA'));
    }

    public function test_a_versioned_read_takes_the_facts_from_the_revision(): void
    {
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk']);
        $row = [
            'unit_name' => 'armcom',
            'full_name' => 'Commander now',
            'faction_key' => 'arm',
            'stats' => null,
            'build_options' => ['armsolar'],
            'morph_targets' => [],
            'game_unit_revision' => [['full_name' => 'Commander then', 'faction_key' => 'arm', 'build_options' => [], 'morph_targets' => [], 'stats' => null]],
        ];
        Http::fake(function (Request $request) use ($row) {
            if (str_contains($request->url(), '/rest/v1/game?')) {
                return Http::response([['start_units' => ['armcom']]]);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(($query['offset'] ?? '0') === '0' ? [$row] : []);
        });

        $tree = Tree::loadTree(Supabase::anon(), 'BA', '1.0');

        $this->assertSame('Commander then', $tree['factions'][0]['label']);
        $this->assertSame([], $tree['factions'][0]['units'][0]['builds']);
        Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'game_unit_revision.version=eq.1.0'));
    }
}
