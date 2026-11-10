<?php

namespace Tests\Unit\Games;

use App\Domain\Assets\Asset;
use App\Domain\Games\Units;
use App\Supabase\Client;
use App\Supabase\Supabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

/**
 * Ported from `lib/games/units.test.ts`. The TS tests used a hand written fake
 * client. These run the real query builder against `Http::fake()`, with a small
 * PostgREST stand in that applies the filters the reads send, so a read that
 * sends `removed_at=eq.null` or an unfiltered embed fails here as it would
 * against the database.
 *
 */
class UnitsTest extends TestCase
{
    private Client $supabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk']);
        $this->supabase = Supabase::anon();
    }

    /**
     * Answers `/rest/v1/<table>` from `$tables`. Applies `eq`, `is.null`,
     * `not.is.null`, `in`, `not.in` and `cs` on the row's own columns, the
     * embedded revision filters, `order`, `offset` and `limit`. A response is
     * capped at `$cap` rows the way PostgREST's `max_rows` caps one. An offset
     * past the end answers 416 when a count was asked for.
     *
     * @param  array<string, list<array<string, mixed>>>  $tables
     */
    private function serve(array $tables, int $cap = 1000): void
    {
        Http::fake(function (Request $request) use ($tables, $cap) {
            $table = basename((string) parse_url($request->url(), PHP_URL_PATH));
            $p = $this->params($request->url());
            $rows = $this->shape($tables[$table] ?? throw new LogicException("no fake table {$table}"), $p);

            foreach (explode(',', $p['order'] ?? '') as $part) {
                if ($part === '') {
                    continue;
                }
                [$column, $direction] = explode('.', $part) + [1 => 'asc'];
                usort($rows, fn ($a, $b) => strcmp((string) $a[$column], (string) $b[$column]) * ($direction === 'desc' ? -1 : 1));
            }

            $total = count($rows);
            $offset = (int) ($p['offset'] ?? 0);
            if ($offset > 0 && $offset >= $total && str_contains(implode(',', $request->header('Prefer')), 'count=')) {
                return Http::response(['code' => 'PGRST103', 'message' => 'Requested range not satisfiable'], 416);
            }
            $limit = min((int) ($p['limit'] ?? PHP_INT_MAX), $cap);
            $page = array_slice($rows, $offset, $limit);
            $range = $page === [] ? "*/{$total}" : $offset.'-'.($offset + count($page) - 1)."/{$total}";

            return Http::response($page, 200, ['Content-Range' => $range]);
        });
    }

    /** @return array<string, string> */
    private function params(string $url): array
    {
        $out = [];
        foreach (explode('&', (string) parse_url($url, PHP_URL_QUERY)) as $part) {
            if ($part !== '') {
                [$name, $value] = explode('=', $part, 2) + [1 => ''];
                $out[urldecode($name)] = urldecode($value);
            }
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $rows */
    private function shape(array $rows, array $p): array
    {
        $reserved = ['select', 'order', 'offset', 'limit', 'or'];
        foreach ($p as $column => $expression) {
            if (in_array($column, $reserved, true) || str_contains($column, '.')) {
                continue;
            }
            $rows = array_values(array_filter($rows, fn ($row) => $this->satisfies($row[$column] ?? null, $expression)));
        }

        $select = $p['select'] ?? '';
        $embedded = [];
        foreach ($rows as $row) {
            if (! str_contains($select, 'game_unit_revision')) {
                unset($row['game_unit_revision']);
            } elseif (isset($row['game_unit_revision'])) {
                $revisions = $row['game_unit_revision'];
                if (isset($p['game_unit_revision.version'])) {
                    $revisions = array_values(array_filter($revisions, fn ($r) => 'eq.'.$r['version'] === $p['game_unit_revision.version']));
                }
                if (isset($p['game_unit_revision.build_options'])) {
                    $revisions = array_values(array_filter($revisions, fn ($r) => $this->satisfies($r['build_options'], $p['game_unit_revision.build_options'])));
                }
                if (isset($p['game_unit_revision.order'])) {
                    usort($revisions, fn ($a, $b) => strcmp($b['version'], $a['version']));
                }
                if ($revisions === [] && str_contains($select, 'game_unit_revision!inner')) {
                    continue;
                }
                $row['game_unit_revision'] = $revisions;
            }
            $embedded[] = $row;
        }

        return $embedded;
    }

    private function satisfies(mixed $value, string $expression): bool
    {
        $list = fn (string $from) => array_map(fn ($item) => trim($item, '"'), explode(',', substr($expression, strlen($from), -1)));

        return match (true) {
            $expression === 'is.null' => $value === null,
            $expression === 'not.is.null' => $value !== null,
            str_starts_with($expression, 'eq.') => (string) $value === substr($expression, 3),
            str_starts_with($expression, 'in.(') => in_array($value, $list('in.('), true),
            str_starts_with($expression, 'not.in.(') => ! in_array($value, $list('not.in.('), true),
            str_starts_with($expression, 'cs.{') => array_diff($list('cs.{'), $value ?? []) === [],
            default => throw new LogicException("unhandled filter {$expression}"),
        };
    }

    /** @return list<string> */
    private function sentUrls(): array
    {
        return Http::recorded()->map(fn ($pair) => urldecode($pair[0]->url()))->all();
    }

    private function grid(array $rows, array $filters = [], array $exclude = [], array $factions = []): array
    {
        $this->serve(['game_unit' => $rows, 'game_faction' => $factions]);

        return Units::loadUnitGrid($this->supabase, 'BA', Units::parseUnitGridFilters($filters), $exclude);
    }

    private const ROWS = [
        ['unit_name' => 'armcom', 'full_name' => 'Commander', 'faction_key' => 'arm', 'removed_at' => null],
        ['unit_name' => 'armsolar', 'full_name' => 'Solar Collector', 'faction_key' => 'arm', 'removed_at' => null],
        ['unit_name' => 'armbrawl', 'full_name' => 'Brawler', 'faction_key' => 'arm', 'removed_at' => '2026-01-01'],
    ];

    public function test_a_page_too_large_to_be_a_real_page_falls_back_to_the_first(): void
    {
        $last = intdiv(9007199254740991, Units::UNIT_PAGE_SIZE);

        $this->assertSame($last, Units::parseUnitGridFilters(['page' => (string) $last])['page']);
        $this->assertSame(1, Units::parseUnitGridFilters(['page' => (string) ($last + 1)])['page']);
        $this->assertSame(1, Units::parseUnitGridFilters(['page' => '99999999999999999999'])['page']);
    }

    public function test_the_page_reads_the_way_javascript_reads_a_number(): void
    {
        $this->assertSame(3, Units::parseUnitGridFilters(['page' => ' 3 '])['page']);
        $this->assertSame(100, Units::parseUnitGridFilters(['page' => '1e2'])['page']);
        $this->assertSame(16, Units::parseUnitGridFilters(['page' => '0x10'])['page']);
        $this->assertSame(1, Units::parseUnitGridFilters(['page' => '2.5'])['page']);
        $this->assertSame(1, Units::parseUnitGridFilters(['page' => 'abc'])['page']);
        $this->assertSame(1, Units::parseUnitGridFilters(['page' => '1'])['page']);
        $this->assertSame(1, Units::parseUnitGridFilters([])['page']);
    }

    public function test_the_filters_parse_trimmed_and_null_when_nothing_is_chosen(): void
    {
        $this->assertNull(Units::parseUnitGridFilters([])['faction']);
        $this->assertNull(Units::parseUnitGridFilters(['faction' => ''])['faction']);
        $this->assertSame('core', Units::parseUnitGridFilters(['faction' => ['core']])['faction']);
        $this->assertSame('core', Units::parseUnitGridFilters(['faction' => ' core '])['faction']);
        $this->assertSame('commander', Units::parseUnitGridFilters(['q' => ' commander '])['q']);
        $this->assertNull(Units::parseUnitGridFilters(['q' => '  '])['q']);
        $this->assertTrue(Units::parseUnitGridFilters(['retired' => '1'])['retired']);
        $this->assertFalse(Units::parseUnitGridFilters(['retired' => 'yes'])['retired']);
    }

    public function test_the_grid_hides_retired_units_without_being_refused_for_it(): void
    {
        $grid = $this->grid(self::ROWS);

        $this->assertNull($grid['error']);
        $this->assertSame(['armcom', 'armsolar'], array_column($grid['units'], 'unit_name'));
        $this->assertSame(2, $grid['count']);
        // PostgREST refuses `removed_at=eq.null`, which is what took the grid down (#255).
        $urls = implode("\n", $this->sentUrls());
        $this->assertStringContainsString('removed_at=is.null', $urls);
        $this->assertStringNotContainsString('eq.null', $urls);
    }

    public function test_the_grid_embeds_the_game_it_filters_on(): void
    {
        $this->grid(self::ROWS);

        $urls = implode("\n", $this->sentUrls());
        $this->assertStringContainsString('game.shortname=eq.BA', $urls);
        $this->assertStringContainsString('select=unit_name,full_name,faction_key,game!inner(shortname)', $urls);
    }

    public function test_asking_for_retired_units_shows_the_one_that_was_removed(): void
    {
        $grid = $this->grid(self::ROWS, ['retired' => '1']);

        $this->assertNull($grid['error']);
        $this->assertSame(['armbrawl', 'armcom', 'armsolar'], array_column($grid['units'], 'unit_name'));
    }

    public function test_a_faction_filter_narrows_the_grid_to_that_side(): void
    {
        $rows = [
            ...self::ROWS,
            ['unit_name' => 'corcom', 'full_name' => 'Commander', 'faction_key' => 'core', 'removed_at' => null],
            ['unit_name' => 'corsolar', 'full_name' => 'Solar Collector', 'faction_key' => 'core', 'removed_at' => null],
        ];
        $grid = $this->grid($rows, ['faction' => 'core']);

        $this->assertNull($grid['error']);
        $this->assertSame(['corcom', 'corsolar'], array_column($grid['units'], 'unit_name'));
    }

    public function test_a_search_matches_either_name(): void
    {
        $this->grid(self::ROWS, ['q' => 'commander']);

        $this->assertStringContainsString(
            'or=(unit_name.ilike.%commander%,full_name.ilike.%commander%)',
            implode("\n", $this->sentUrls()),
        );
    }

    public function test_a_game_without_sides_lists_unassigned_units_despite_exclusions(): void
    {
        $rows = array_map(fn ($row) => [...$row, 'faction_key' => null], self::ROWS);
        $grid = $this->grid($rows, [], array_column($rows, 'unit_name'));

        $this->assertNull($grid['error']);
        $this->assertSame(['armcom', 'armsolar'], array_column($grid['units'], 'unit_name'));
        $this->assertSame(count($grid['units']), $grid['count']);
    }

    public function test_a_game_without_sides_can_still_show_retired_units(): void
    {
        $rows = array_map(fn ($row) => [...$row, 'faction_key' => null], self::ROWS);
        $grid = $this->grid($rows, ['retired' => '1'], array_column($rows, 'unit_name'));

        $this->assertNull($grid['error']);
        $this->assertSame(['armbrawl', 'armcom', 'armsolar'], array_column($grid['units'], 'unit_name'));
        $this->assertSame(count($rows), $grid['count']);
    }

    public function test_a_random_option_alone_does_not_count_as_a_playable_side(): void
    {
        $grid = $this->grid(self::ROWS, [], ['armcom', 'armsolar'], [['key' => 'random', 'name' => 'Random']]);

        $this->assertNull($grid['error']);
        $this->assertSame(['armcom', 'armsolar'], array_column($grid['units'], 'unit_name'));
        $this->assertSame(count($grid['units']), $grid['count']);
    }

    public function test_a_game_with_a_side_still_excludes_units_before_counting(): void
    {
        $grid = $this->grid(self::ROWS, [], ['armsolar'], [['key' => 'arm', 'name' => 'Arm']]);

        $this->assertNull($grid['error']);
        $this->assertSame(['armcom'], array_column($grid['units'], 'unit_name'));
        $this->assertSame(count($grid['units']), $grid['count']);
        $this->assertStringContainsString('unit_name=not.in.(armsolar)', implode("\n", $this->sentUrls()));
    }

    public function test_a_page_is_forty_eight_units_and_the_count_is_the_whole_game(): void
    {
        $rows = array_map(fn ($i) => ['unit_name' => sprintf('unit%03d', $i), 'full_name' => null, 'faction_key' => null, 'removed_at' => null], range(1, 60));
        $grid = $this->grid($rows, ['page' => '2']);

        $this->assertSame(60, $grid['count']);
        $this->assertCount(12, $grid['units']);
        $this->assertSame('unit049', $grid['units'][0]['unit_name']);
    }

    public function test_a_page_past_the_end_is_an_empty_page_with_the_real_count(): void
    {
        $grid = $this->grid(self::ROWS, ['page' => '5']);

        $this->assertNull($grid['error']);
        $this->assertSame([], $grid['units']);
        $this->assertSame(2, $grid['count']);
    }

    public function test_a_failed_grid_read_reports_the_error(): void
    {
        Http::fake(['*/rest/v1/game_unit*' => Http::response(['code' => '42501', 'message' => 'denied'], 401)]);

        $grid = Units::loadUnitGrid($this->supabase, 'BA', Units::parseUnitGridFilters([]));

        $this->assertSame(['units' => [], 'count' => 0, 'error' => 'denied'], $grid);
    }

    private const CATALOG = [
        ['unit_name' => 'armcom', 'build_options' => ['armsolar'], 'removed_at' => null],
        ['unit_name' => 'armsolar', 'build_options' => [], 'removed_at' => null],
        ['unit_name' => 'armfark', 'build_options' => [], 'removed_at' => null],
        ['unit_name' => 'oldref', 'build_options' => [], 'removed_at' => '2026-01-01'],
    ];

    private function catalog(array $rows, array $startUnits): void
    {
        $this->serve(['game_unit' => $rows, 'game' => [['shortname' => 'BA', 'start_units' => $startUnits]]]);
    }

    public function test_a_unit_nobody_builds_and_no_start_unit_heads_is_a_ghost(): void
    {
        $this->catalog(self::CATALOG, ['armcom']);

        // armfark is alive but unreachable and unbuilt. oldref does not count, since it retired already.
        $this->assertSame(['armfark'], Units::unbuildableUnits($this->supabase, 'BA'));
    }

    public function test_a_start_unit_never_hides_however_lonely_it_is(): void
    {
        $this->catalog(self::CATALOG, ['armcom', 'ARMFARK']);

        $this->assertSame([], Units::unbuildableUnits($this->supabase, 'BA'));
    }

    public function test_a_game_reporting_no_build_options_at_all_hides_nothing(): void
    {
        // THIS had 39 units and not one build option, and this rule called 37 of them ghosts.
        $this->catalog([
            ['unit_name' => 'carrier', 'build_options' => [], 'removed_at' => null],
            ['unit_name' => 'dagger', 'build_options' => [], 'removed_at' => null],
            ['unit_name' => 'sword', 'build_options' => [], 'removed_at' => null],
        ], ['carrier']);

        $this->assertSame([], Units::unbuildableUnits($this->supabase, 'THIS'));
    }

    public function test_a_failed_catalog_read_hides_nothing(): void
    {
        Http::fake(['*' => Http::response(['message' => 'boom'], 500)]);

        $this->assertSame([], Units::unbuildableUnits($this->supabase, 'BA'));
    }

    public function test_a_game_with_retired_units_keeps_the_toggle(): void
    {
        $this->serve(['game_unit' => [
            ...self::ROWS,
            ['unit_name' => 'a', 'removed_at' => '2026-01-01'],
            ['unit_name' => 'b', 'removed_at' => '2026-01-01'],
            ['unit_name' => 'c', 'removed_at' => '2026-01-01'],
        ]]);

        $this->assertTrue(Units::hasRetiredUnits($this->supabase, 'SF'));
    }

    public function test_a_game_that_has_never_retired_a_unit_loses_the_toggle(): void
    {
        $this->serve(['game_unit' => [self::ROWS[0], self::ROWS[1]]]);

        $this->assertFalse(Units::hasRetiredUnits($this->supabase, 'THIS'));
    }

    public function test_a_failed_count_leaves_the_toggle_where_it_is(): void
    {
        Http::fake(['*' => Http::response(['message' => 'boom'], 500)]);

        $this->assertTrue(Units::hasRetiredUnits($this->supabase, 'BA'));
    }

    private const MORPHING = [
        ['unit_name' => 'ArmCom1', 'morph_targets' => [['into' => 'armcom2']], 'removed_at' => null],
        ['unit_name' => 'armcom2', 'morph_targets' => [['into' => 'armcom3']], 'removed_at' => null],
        ['unit_name' => 'armcom3', 'morph_targets' => [], 'removed_at' => null],
        ['unit_name' => 'armsolar', 'morph_targets' => [], 'removed_at' => null],
    ];

    public function test_every_level_but_the_base_is_kept_off_the_grid(): void
    {
        $this->serve(['game_unit' => self::MORPHING]);

        // The stored spelling, because the exclusion is a `unit_name` filter that PostgREST matches exactly.
        $this->assertSame(['armcom2', 'armcom3'], Units::morphedAwayUnits($this->supabase, 'BA'));
    }

    public function test_a_unit_that_turns_into_nothing_is_not_hidden_by_this(): void
    {
        $this->serve(['game_unit' => self::MORPHING]);

        $this->assertNotContains('armsolar', Units::morphedAwayUnits($this->supabase, 'BA'));
    }

    public function test_a_retired_level_does_not_head_the_group_the_grid_shows(): void
    {
        $this->serve(['game_unit' => [
            ['unit_name' => 'armcom1', 'morph_targets' => [['into' => 'armcom2']], 'removed_at' => '2026-01-01'],
            ['unit_name' => 'armcom2', 'morph_targets' => [['into' => 'armcom3']], 'removed_at' => null],
            ['unit_name' => 'armcom3', 'morph_targets' => [], 'removed_at' => null],
        ]]);

        $this->assertSame(['armcom3'], Units::morphedAwayUnits($this->supabase, 'BA'));
    }

    public function test_a_chain_split_across_the_row_cap_is_still_one_chain(): void
    {
        $filler = array_map(fn ($i) => ['unit_name' => "filler{$i}", 'morph_targets' => [], 'removed_at' => null], range(0, 7));
        $this->serve(['game_unit' => [
            ['unit_name' => 'armcom1', 'morph_targets' => [['into' => 'armcom2']], 'removed_at' => null],
            ['unit_name' => 'armcom2', 'morph_targets' => [], 'removed_at' => null],
            ...$filler,
        ]], cap: 3);

        $this->assertSame(['armcom2'], Units::morphedAwayUnits($this->supabase, 'BA'));
    }

    public function test_a_failed_morph_read_hides_nothing(): void
    {
        Http::fake(['*' => Http::response(['message' => 'boom'], 500)]);

        $this->assertSame([], Units::morphedAwayUnits($this->supabase, 'BA'));
    }

    private const REVISIONS = [
        ['version' => '2.0', 'full_name' => 'Commander', 'faction_key' => 'arm', 'build_options' => ['armsolar'], 'stats' => ['health' => 3000]],
        ['version' => '1.0', 'full_name' => 'Commander Mk I', 'faction_key' => 'arm', 'build_options' => [], 'stats' => ['health' => 2200]],
    ];

    private function revisioned(): void
    {
        $this->serve([
            'game_unit' => [[
                'id' => 1,
                'unit_name' => 'armcom',
                'full_name' => 'Commander',
                'faction_key' => 'arm',
                'build_options' => ['armsolar'],
                'stats' => ['health' => 3000],
                'snippet' => null,
                'source_version' => '2.0',
                'removed_at' => null,
                'morph_targets' => [],
                'game_unit_revision' => self::REVISIONS,
            ]],
            'game_version' => [
                ['version' => '1.0', 'last_seen_at' => '2026-01-01'],
                ['version' => '2.0', 'last_seen_at' => '2026-06-01'],
            ],
            'game_faction' => [['key' => 'arm', 'name' => 'Arm']],
        ]);
    }

    public function test_asking_for_an_older_release_shows_what_that_release_said(): void
    {
        $this->revisioned();

        $page = Units::loadUnitPage($this->supabase, 'BA', 'armcom', '1.0');

        $this->assertSame('1.0', $page['shown_version']);
        $this->assertSame('Commander Mk I', $page['full_name']);
        $this->assertSame(['health' => 2200], $page['stats']);
        $this->assertSame([], $page['build_options']);
        // The revision is filtered by the release asked for, not ordered and searched.
        $this->assertStringContainsString('game_unit_revision.version=eq.1.0', implode("\n", $this->sentUrls()));
    }

    public function test_a_release_the_hub_holds_no_revision_for_falls_back_to_current_facts(): void
    {
        $this->revisioned();

        $page = Units::loadUnitPage($this->supabase, 'BA', 'armcom', '0.9');

        // An ordinary answer for a unit a later patch added, and the page says so rather than 404ing.
        $this->assertNull($page['shown_version']);
        $this->assertSame('Commander', $page['full_name']);
    }

    public function test_no_release_asked_for_is_current_facts_and_reads_no_revisions_to_say_so(): void
    {
        $this->revisioned();

        $page = Units::loadUnitPage($this->supabase, 'BA', 'armcom');

        $this->assertNull($page['shown_version']);
        $this->assertSame(['health' => 3000], $page['stats']);
        $this->assertSame('Arm', $page['faction_name']);
        $this->assertSame(['2.0', '1.0'], $page['versions']);
        $this->assertSame([['name' => 'armsolar', 'label' => 'armsolar']], $page['builds']);
        $this->assertSame([], $page['built_by']);
        $this->assertSame([], $page['stages']);
        foreach ($this->sentUrls() as $url) {
            $this->assertStringNotContainsString('game_unit_revision', $url);
        }
    }

    public function test_a_unit_the_game_does_not_hold_has_no_page(): void
    {
        $this->revisioned();

        $this->assertNull(Units::loadUnitPage($this->supabase, 'BA', 'nothere'));
    }

    public function test_a_failed_page_read_has_no_page(): void
    {
        Http::fake(['*' => Http::response(['message' => 'boom'], 500)]);

        $this->assertNull(Units::loadUnitPage($this->supabase, 'BA', 'armcom'));
    }

    private const COMMANDER = [
        ['unit_name' => 'armcom1', 'full_name' => 'Commander', 'build_options' => ['armsolar'], 'stats' => ['health' => 3000, 'buildpower' => 100], 'morph_targets' => [['into' => 'armcom2', 'metal' => 600, 'time' => 30]], 'removed_at' => null],
        ['unit_name' => 'armcom2', 'full_name' => 'Commander, level 2', 'build_options' => ['armsolar', 'armvp'], 'stats' => ['health' => 4500, 'buildpower' => 100], 'morph_targets' => [['into' => 'armcom3', 'metal' => 1800]], 'removed_at' => null],
        ['unit_name' => 'armcom3', 'full_name' => 'Commander, level 3', 'build_options' => ['armsolar', 'armvp', 'armfus'], 'stats' => ['health' => 6000, 'buildpower' => 200], 'morph_targets' => [], 'removed_at' => null],
        ['unit_name' => 'armsolar', 'full_name' => 'Solar Collector', 'build_options' => [], 'stats' => [], 'morph_targets' => [], 'removed_at' => null],
        ['unit_name' => 'armvp', 'full_name' => 'Vehicle Plant', 'build_options' => [], 'stats' => [], 'morph_targets' => [], 'removed_at' => null],
        ['unit_name' => 'armfus', 'full_name' => 'Fusion Plant', 'build_options' => [], 'stats' => [], 'morph_targets' => [], 'removed_at' => null],
    ];

    private function stages(string $unit, ?array $rows = null, ?string $version = null): array
    {
        $this->serve(['game_unit' => $rows ?? self::COMMANDER]);

        return Units::loadUnitStages($this->supabase, 'BA', $unit, $version);
    }

    public function test_a_levels_page_lists_every_stage_in_order_and_marks_the_one_being_shown(): void
    {
        $stages = $this->stages('armcom2')['stages'];

        $this->assertSame(['armcom1', 'armcom2', 'armcom3'], array_column($stages, 'unit_name'));
        $this->assertSame([false, true, false], array_column($stages, 'current'));
        $this->assertSame(['Commander', 'Commander, level 2', 'Commander, level 3'], array_column($stages, 'label'));
    }

    public function test_a_stage_says_what_it_unlocks_not_everything_it_can_build(): void
    {
        $stages = $this->stages('armcom1')['stages'];
        $unlocks = array_combine(
            array_column($stages, 'unit_name'),
            array_map(fn ($stage) => array_column($stage['unlocks'], 'name'), $stages),
        );

        // The base has no level before it, so it unlocks nothing.
        $this->assertSame([], $unlocks['armcom1']);
        $this->assertSame(['armvp'], $unlocks['armcom2']);
        $this->assertSame(['armfus'], $unlocks['armcom3']);
    }

    public function test_an_unlocked_unit_is_named_as_the_catalog_names_it(): void
    {
        $stages = $this->stages('armcom1')['stages'];

        $this->assertSame([['name' => 'armvp', 'label' => 'Vehicle Plant']], $stages[1]['unlocks']);
    }

    public function test_how_you_get_to_a_stage_rides_along_in_the_games_own_words(): void
    {
        $stages = $this->stages('armcom1')['stages'];

        $this->assertNull($stages[0]['from']);
        $this->assertSame([], $stages[0]['conditions']);
        $this->assertSame('armcom1', $stages[1]['from']);
        $this->assertSame(['metal' => 600, 'time' => 30], $stages[1]['conditions']);
        $this->assertSame(['metal' => 1800], $stages[2]['conditions']);
    }

    public function test_the_stats_run_across_the_stages_with_the_rows_that_move_marked(): void
    {
        $rows = [];
        foreach ($this->stages('armcom1')['stage_stats'] as $row) {
            $rows[$row['key']] = $row;
        }

        $this->assertSame(['3000', '4500', '6000'], $rows['health']['values']);
        $this->assertTrue($rows['health']['changed']);
        $this->assertSame('Health', $rows['health']['label']);
        // Build power is the same at levels one and two and different at three, so the row still moves.
        $this->assertSame(['100', '100', '200'], $rows['buildpower']['values']);
        $this->assertTrue($rows['buildpower']['changed']);
    }

    public function test_a_stat_that_holds_still_across_every_stage_is_not_marked_as_moving(): void
    {
        $flat = self::COMMANDER;
        foreach ([0, 1, 2] as $i) {
            $flat[$i]['stats'] = ['health' => 3000];
        }

        $row = $this->stages('armcom1', $flat)['stage_stats'][0];

        $this->assertSame('health', $row['key']);
        $this->assertFalse($row['changed']);
    }

    public function test_a_stat_that_is_a_list_of_records_is_left_for_the_stages_own_page(): void
    {
        $weapons = self::COMMANDER;
        $weapons[0]['stats'] = ['health' => 1, 'weapons' => [['range' => 300]]];

        $keys = array_column($this->stages('armcom1', $weapons)['stage_stats'], 'key');

        $this->assertContains('health', $keys);
        $this->assertNotContains('weapons', $keys);
    }

    public function test_a_unit_that_turns_into_nothing_gets_no_strip_at_all(): void
    {
        $this->assertSame(['stages' => [], 'stage_stats' => []], $this->stages('armsolar'));
    }

    public function test_a_release_groups_and_reads_stages_from_its_own_revisions(): void
    {
        $rows = array_map(fn ($row) => [...$row, 'game_unit_revision' => [[
            'version' => '2.0',
            'full_name' => $row['full_name'].' v2',
            'build_options' => $row['build_options'],
            'stats' => $row['stats'],
            'morph_targets' => $row['morph_targets'],
        ]]], self::COMMANDER);
        // The release has no record of level three.
        $rows[2]['game_unit_revision'] = [];

        $stages = $this->stages('armcom1', $rows, '2.0')['stages'];

        // Level three has no record in the release, so its label falls back to the def key.
        $this->assertSame(['armcom1', 'armcom2', 'armcom3'], array_column($stages, 'unit_name'));
        $this->assertSame(['Commander v2', 'Commander, level 2 v2', 'armcom3'], array_column($stages, 'label'));
        $this->assertSame([true, true, false], array_column($stages, 'found'));
    }

    private const BUILDERS = [
        [
            'unit_name' => 'armlab', 'full_name' => 'Kbot Lab', 'removed_at' => null, 'build_options' => ['armck', 'armpw'],
            'game_unit_revision' => [
                ['version' => '1.0', 'full_name' => 'Kbot Factory', 'build_options' => ['armck']],
                ['version' => '2.0', 'full_name' => 'Kbot Lab', 'build_options' => ['armck', 'armpw']],
            ],
        ],
        [
            'unit_name' => 'armcom', 'full_name' => 'Commander', 'removed_at' => null, 'build_options' => ['armlab', 'armsolar'],
            'game_unit_revision' => [['version' => '2.0', 'full_name' => 'Commander', 'build_options' => ['armlab']]],
        ],
        [
            'unit_name' => 'armshed', 'full_name' => 'Kbot Shed', 'removed_at' => '2026-01-01', 'build_options' => ['armck'],
            'game_unit_revision' => [['version' => '1.0', 'full_name' => 'Kbot Shed', 'build_options' => ['armck']]],
        ],
    ];

    public function test_a_units_builders_are_the_units_holding_it_as_a_build_option(): void
    {
        $this->serve(['game_unit' => self::BUILDERS]);

        $this->assertSame([['name' => 'armcom', 'label' => 'Commander']], Units::unitBuilders($this->supabase, 'BA', 'armlab'));
    }

    public function test_a_retired_builder_is_not_a_live_path_to_a_unit(): void
    {
        $this->serve(['game_unit' => self::BUILDERS]);

        // armshed builds armck too, but it retired, so only the lab counts.
        $this->assertSame([['name' => 'armlab', 'label' => 'Kbot Lab']], Units::unitBuilders($this->supabase, 'BA', 'armck'));
    }

    public function test_a_release_answers_with_that_releases_builders_under_that_releases_names(): void
    {
        $this->serve(['game_unit' => self::BUILDERS]);

        // In 1.0 the lab was called a factory and did not build armpw yet, and it had not retired, so the shed still counts.
        $this->assertSame(
            [['name' => 'armlab', 'label' => 'Kbot Factory'], ['name' => 'armshed', 'label' => 'Kbot Shed']],
            Units::unitBuilders($this->supabase, 'BA', 'armck', '1.0'),
        );
        $this->assertSame([], Units::unitBuilders($this->supabase, 'BA', 'armpw', '1.0'));
    }

    public function test_a_builder_read_that_fails_answers_with_nothing_rather_than_taking_the_page_down(): void
    {
        Http::fake(['*' => Http::response(['message' => 'nope'], 401)]);

        $this->assertSame([], Units::unitBuilders($this->supabase, 'BA', 'armlab'));
    }

    public function test_the_random_side_hides_from_every_faction_list(): void
    {
        $this->serve(['game_faction' => [
            ['key' => 'arm', 'name' => 'Arm'],
            ['key' => 'random', 'name' => 'Random'],
            ['key' => 'core', 'name' => 'Random'],
            ['key' => 'random', 'name' => 'Trolls'],
        ]]);

        $this->assertSame([['key' => 'arm', 'name' => 'Arm']], Units::gameFactions($this->supabase, 'BA'));
    }

    public function test_a_failed_faction_read_is_no_factions(): void
    {
        Http::fake(['*' => Http::response(['message' => 'nope'], 500)]);

        $this->assertSame([], Units::gameFactions($this->supabase, 'BA'));
    }

    public function test_unit_name_labels_are_keyed_lower_cased_and_fall_back_to_the_name(): void
    {
        $this->serve(['game_unit' => [
            ['unit_name' => 'ArmCom', 'full_name' => 'Commander'],
            ['unit_name' => 'armmex', 'full_name' => null],
        ]]);

        $this->assertSame([
            'armcom' => ['name' => 'ArmCom', 'label' => 'Commander'],
            'armmex' => ['name' => 'armmex', 'label' => 'armmex'],
        ], Units::unitNameLabels($this->supabase, 'BA'));
    }

    private const NO_STATS = [
        'build_options' => [],
        'builder' => null,
        'weapon' => null,
        'energyMake' => null,
        'windGenerator' => null,
        'tidalGenerator' => null,
        'energyUpkeep' => null,
    ];

    public function test_unit_roles_key_each_unit_lower_cased_and_leave_out_the_ones_with_no_role(): void
    {
        $this->serve(['game_unit' => [
            [...self::NO_STATS, 'unit_name' => 'ArmCK', 'build_options' => ['armsolar']],
            [...self::NO_STATS, 'unit_name' => 'armllt', 'weapon' => ['range' => 430]],
            [...self::NO_STATS, 'unit_name' => 'armwin', 'windGenerator' => 25],
            [...self::NO_STATS, 'unit_name' => 'armmex'],
        ]]);

        $this->assertEquals(['armck' => 'builder', 'armllt' => 'weapon', 'armwin' => 'energy'], Units::unitRoles($this->supabase, 'BA'));

        // The stats the rule reads, and not the whole blob.
        $select = $this->params(Http::recorded()->first()[0]->url())['select'];
        $this->assertStringContainsString('weapon:stats->weapons->0', $select);
        $this->assertDoesNotMatchRegularExpression('/(^|,)stats(,|$)/', $select);
    }

    public function test_the_roles_rule_orders_builder_then_weapon_then_energy(): void
    {
        $this->serve(['game_unit' => [
            [...self::NO_STATS, 'unit_name' => 'a', 'builder' => true, 'weapon' => ['range' => 1]],
            [...self::NO_STATS, 'unit_name' => 'b', 'weapon' => ['range' => 1], 'energyMake' => 20],
            [...self::NO_STATS, 'unit_name' => 'c', 'energyUpkeep' => -5],
            [...self::NO_STATS, 'unit_name' => 'd', 'energyUpkeep' => 5],
            [...self::NO_STATS, 'unit_name' => 'e', 'tidalGenerator' => 1.5],
        ]]);

        $this->assertEquals(
            ['a' => 'builder', 'b' => 'weapon', 'c' => 'energy', 'e' => 'energy'],
            Units::unitRoles($this->supabase, 'BA'),
        );
    }

    public function test_a_comparison_reads_both_releases_and_marks_what_changed(): void
    {
        $this->serve(['game_unit' => [[
            'unit_name' => 'armcom',
            'game_unit_revision' => [
                ['version' => '1.0', 'full_name' => 'Commander Mk I', 'faction_key' => 'arm', 'build_options' => [], 'stats' => ['health' => 2200]],
                ['version' => '2.0', 'full_name' => 'Commander', 'faction_key' => 'arm', 'build_options' => ['armsolar'], 'stats' => ['health' => 3000, 'buildpower' => 100]],
            ],
        ]]]);

        $comparison = Units::loadUnitComparison($this->supabase, 'BA', 'armcom', '1.0', '2.0');

        $this->assertSame('armcom', $comparison['unit_name']);
        $this->assertTrue($comparison['left']['found']);
        $this->assertSame('Commander Mk I', $comparison['left']['full_name']);
        $this->assertSame(['armsolar'], $comparison['right']['build_options']);
        $this->assertSame(
            [['health', '2200', '3000', true], ['buildpower', '-', '100', true]],
            array_map(fn ($row) => [$row['key'], $row['left'], $row['right'], $row['changed']], $comparison['rows']),
        );
        $this->assertStringContainsString('game_unit_revision.order=version.desc', implode("\n", $this->sentUrls()));
    }

    public function test_a_release_without_a_revision_reads_as_not_recorded(): void
    {
        $this->serve(['game_unit' => [['unit_name' => 'armcom', 'game_unit_revision' => []]]]);

        $comparison = Units::loadUnitComparison($this->supabase, 'BA', 'armcom', '1.0', '2.0');

        $this->assertSame(
            ['version' => '1.0', 'found' => false, 'full_name' => null, 'faction_key' => null, 'build_options' => [], 'stats' => []],
            $comparison['left'],
        );
        $this->assertSame([], $comparison['rows']);
    }

    public function test_a_comparison_of_a_unit_the_game_does_not_hold_is_null(): void
    {
        $this->serve(['game_unit' => []]);

        $this->assertNull(Units::loadUnitComparison($this->supabase, 'BA', 'nothere', '1.0', '2.0'));
    }

    private function renderRow(string $angle): array
    {
        return [
            'game' => 'BA',
            'unit_name' => 'armcom',
            'map_name' => null,
            'variant' => "render:{$angle}",
            'tier' => 'static',
            'path' => "units/BA/render-{$angle}/abc.webp",
            'width' => 256,
            'height' => 256,
            'moderation' => 'approved',
        ];
    }

    public function test_a_units_renders_cover_every_angle_the_vocabulary_names(): void
    {
        $this->serve(['asset' => [$this->renderRow('top'), $this->renderRow('side')]]);

        $renders = Units::unitRenders($this->supabase, 'BA', 'armcom');

        $this->assertSame(Asset::unitRenderAngles(), array_column($renders, 'angle'));
        $filters = implode("\n", $this->sentUrls());
        foreach (Asset::unitRenderAngles() as $angle) {
            $this->assertStringContainsString("render:{$angle}", $filters);
        }
    }

    public function test_every_angle_resolves_in_one_read_rather_than_one_each(): void
    {
        $this->serve(['asset' => []]);

        Units::unitRenders($this->supabase, 'BA', 'armcom');

        $this->assertCount(1, $this->sentUrls());
    }

    public function test_an_angle_the_hub_holds_is_served_and_one_it_does_not_is_a_placeholder(): void
    {
        $this->serve(['asset' => [$this->renderRow('top'), $this->renderRow('side')]]);

        $by = [];
        foreach (Units::unitRenders($this->supabase, 'BA', 'armcom') as $entry) {
            $by[$entry['angle']] = $entry['asset'];
        }

        $this->assertSame('static', $by['side']['from']);
        $this->assertSame('placeholder', $by['front']['from']);
    }

    public function test_buildpics_are_keyed_by_unit_name_and_a_missing_one_is_a_placeholder(): void
    {
        $this->serve(['asset' => [[...$this->renderRow('top'), 'variant' => 'buildpic', 'path' => 'units/BA/buildpic/abc.webp']]]);

        $pictures = Units::unitBuildpics($this->supabase, 'BA', [['unit_name' => 'armcom'], ['unit_name' => 'armsolar']]);

        $this->assertSame(['armcom', 'armsolar'], array_keys($pictures));
        $this->assertSame('static', $pictures['armcom']['from']);
        $this->assertSame('placeholder', $pictures['armsolar']['from']);
        $this->assertSame('static', Units::unitBuildpic($this->supabase, 'BA', 'armcom')['from']);
    }
}
