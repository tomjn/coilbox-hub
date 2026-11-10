<?php

namespace Tests\Feature\Games;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** One unit's page and its comparisons, against a faked Supabase. */
class UnitPageTest extends TestCase
{
    private const GAME = [
        'shortname' => 'BA', 'display_name' => 'Balanced Annihilation', 'description' => null, 'links' => [],
        'conquest_factions' => null, 'owner_user_id' => null, 'hidden_at' => null, 'base_game' => null,
        'logo_path' => null, 'logo_hash' => null, 'logo_staged_tier' => null,
        'banner_path' => null, 'banner_hash' => null, 'banner_staged_tier' => null,
        'card_path' => null, 'card_hash' => null, 'card_staged_tier' => null,
        'game_download_source' => [], 'game_faction' => [['key' => 'arm', 'name' => 'ARM', 'logo_path' => null]],
        'game_version' => [['version' => 'r2', 'last_seen_at' => '2026-01-02']],
    ];

    private const COM = [
        'id' => 1, 'unit_name' => 'armcom', 'full_name' => 'Commander', 'faction_key' => 'arm',
        'build_options' => ['armsolar'], 'snippet' => 'Starts every match.', 'source_version' => 'r2', 'removed_at' => null,
        'stats' => ['metalCost' => 2700, 'health' => 3700, 'weapons' => [['range' => 300, 'damage' => 75]]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk', 'cache.default' => 'array']);
    }

    /** Answers the first table and query text that match, and everything else with an empty list. */
    private function fakeTables(array $tables): void
    {
        Http::fake(function (Request $request) use ($tables) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            foreach ($tables as $key => $rows) {
                [$table, $needle] = array_pad(explode('?', $key, 2), 2, '');
                if ($path === '/rest/v1/'.$table && str_contains(urldecode((string) parse_url($request->url(), PHP_URL_QUERY)), $needle)) {
                    return Http::response($rows, 200, ['Content-Range' => '0-0/'.count($rows)]);
                }
            }

            return Http::response([], 200, ['Content-Range' => '*/0']);
        });
    }

    private function fakeUnitCatalog(): void
    {
        $this->fakeTables([
            'game_browse?select=faction_count' => [['faction_count' => 1, 'unit_count' => 2, 'item_count' => 0]],
            'game' => [self::GAME],
            'game_version' => [['version' => 'r2', 'last_seen_at' => '2026-01-02'], ['version' => 'r1', 'last_seen_at' => '2026-01-01']],
            'game_faction' => [['key' => 'arm', 'name' => 'ARM']],
            'game_unit?select=id,unit_name' => [self::COM],
            'game_unit?unit_name=in.' => [['unit_name' => 'armsolar', 'full_name' => 'Solar Collector']],
            // Nothing builds the commander.
            'game_unit?build_options=cs.' => [],
            'game_unit?select=unit_name,full_name' => [
                ['unit_name' => 'armcom', 'full_name' => 'Commander'],
                ['unit_name' => 'armsolar', 'full_name' => 'Solar Collector'],
            ],
        ]);
    }

    public function test_the_release_query_redirects_into_the_path_as_next_does(): void
    {
        Http::fake();

        $response = $this->get('/games/BA/units/armcom?v=r1&x=1');

        $response->assertStatus(308);
        $this->assertSame('/games/BA/units/armcom@r1?v=r1&x=1', $response->headers->get('Location'));
        Http::assertNothingSent();
    }

    public function test_an_empty_release_query_does_not_redirect(): void
    {
        $this->fakeUnitCatalog();

        $this->get('/games/BA/units/armcom?v=')->assertOk();
    }

    public function test_a_unit_page_draws_its_facts(): void
    {
        $this->fakeUnitCatalog();

        $this->get('/games/BA/units/armcom')->assertOk()
            ->assertSee('<title>Commander - Balanced Annihilation - Coilbox Hub</title>', false)
            ->assertSee('rel="canonical" href="'.url('/games/BA/units/armcom').'"', false)
            ->assertSee('<h1 class="text-3xl font-semibold tracking-tight">Commander</h1>', false)
            ->assertSee('Facts as of release r2.')
            ->assertSee('From the author')
            ->assertSee('Starts every match.')
            ->assertSeeInOrder(['Health', '3700', 'Metal cost', '2700', 'weapons'])
            ->assertSee('Nothing builds this.')
            ->assertSee('Solar Collector')
            ->assertSee('href="/games/BA/units/armcom@r1"', false)
            ->assertSee('Compare releases')
            ->assertSee('<option value="armsolar" label="Solar Collector">', false);
    }

    public function test_a_release_a_unit_has_no_record_in_says_so(): void
    {
        $this->fakeUnitCatalog();

        $this->get('/games/BA/units/armcom@nope')->assertOk()
            ->assertSee('No record of this unit in release nope; showing current facts.');
    }

    public function test_an_unknown_unit_is_not_found(): void
    {
        $this->fakeTables(['game' => [self::GAME]]);

        $this->get('/games/BA/units/nothing')->assertNotFound();
    }

    public function test_a_comparison_with_no_pair_is_not_found(): void
    {
        $this->fakeUnitCatalog();

        $this->get('/games/BA/units/armcom/compare')->assertNotFound();
        $this->get('/games/BA/units/armcom/compare?left=r1')->assertNotFound();
    }

    public function test_two_releases_of_a_unit_draw_a_table_with_a_dash_for_a_missing_record(): void
    {
        $this->fakeTables([
            'game_browse?select=faction_count' => [['faction_count' => 1, 'unit_count' => 2, 'item_count' => 0]],
            'game' => [self::GAME],
            'game_unit?select=unit_name,game' => [[
                'unit_name' => 'armcom',
                'game_unit_revision' => [['version' => 'r1', 'full_name' => 'Commander', 'faction_key' => 'arm', 'build_options' => [], 'stats' => ['health' => 3000, 'metalCost' => 2700]]],
            ]],
        ]);

        $this->get('/games/BA/units/armcom/compare?left=r1&right=r9')->assertOk()
            ->assertSee('<title>Compare Commander - Balanced Annihilation - Coilbox Hub</title>', false)
            ->assertSee('r1 vs r9')
            ->assertSee('2 stats differ between these releases.')
            ->assertSee('A dash means this release has no record of the unit.');
    }

    public function test_a_comparison_with_no_unit_named_asks_for_one(): void
    {
        $this->fakeUnitCatalog();

        $this->get('/games/BA/units/armcom/compare?with=')->assertOk()
            ->assertSee('<title>Compare Commander with another unit - Balanced Annihilation - Coilbox Hub</title>', false)
            ->assertSee('Choose a unit from Balanced Annihilation to compare with Commander.')
            ->assertSee('Compare with');
    }

    public function test_a_comparison_names_a_unit_it_cannot_find(): void
    {
        $this->fakeUnitCatalog();

        $this->get('/games/BA/units/armcom/compare?with=zzz')->assertOk()
            ->assertSee('Balanced Annihilation has no unit called &quot;zzz&quot;.', false);
    }

    public function test_a_comparison_with_the_unit_itself_says_so(): void
    {
        $this->fakeUnitCatalog();

        $this->get('/games/BA/units/armcom/compare?with=ARMCOM')->assertOk()
            ->assertSee('That is the unit you are already looking at.');
    }

    public function test_the_first_of_a_repeated_with_key_is_read(): void
    {
        $this->fakeUnitCatalog();

        $this->get('/games/BA/units/armcom/compare?with=zzz&with=armsolar')->assertOk()
            ->assertSee('has no unit called &quot;zzz&quot;', false);
    }

    public function test_a_comparison_of_a_hidden_or_unknown_unit_is_not_found(): void
    {
        $this->fakeTables(['game' => [self::GAME]]);

        $this->get('/games/BA/units/armcom/compare?with=armsolar')->assertNotFound();
    }
}
