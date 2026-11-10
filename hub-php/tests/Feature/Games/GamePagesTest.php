<?php

namespace Tests\Feature\Games;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The games listing, a game's page and its units grid, against a faked Supabase. */
class GamePagesTest extends TestCase
{
    private const GAME = [
        'shortname' => 'BA', 'display_name' => 'Balanced Annihilation', 'description' => 'A **classic** game.',
        'links' => [['label' => 'Home', 'url' => 'https://ba.test/']], 'conquest_factions' => null,
        'owner_user_id' => null, 'hidden_at' => null, 'base_game' => null,
        'logo_path' => null, 'logo_hash' => null, 'logo_staged_tier' => null,
        'banner_path' => null, 'banner_hash' => null, 'banner_staged_tier' => null,
        'card_path' => null, 'card_hash' => null, 'card_staged_tier' => null,
        'game_download_source' => [['kind' => 'rapid', 'value' => 'ba:stable', 'asset' => null, 'filename' => null, 'sort_order' => 0]],
        'game_faction' => [['key' => 'arm', 'name' => 'ARM', 'logo_path' => null], ['key' => 'random', 'name' => 'Random', 'logo_path' => null]],
        'game_version' => [['version' => '1.2', 'last_seen_at' => '2026-01-01']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk', 'cache.default' => 'array']);
    }

    /** Answers each table with rows, and everything else with an empty list. */
    private function fakeTables(array $tables): void
    {
        Http::fake(function (Request $request) use ($tables) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            foreach ($tables as $key => $rows) {
                // `table` or `table?text the query must contain`.
                [$table, $needle] = array_pad(explode('?', $key, 2), 2, '');
                if ($path === '/rest/v1/'.$table && str_contains(urldecode((string) parse_url($request->url(), PHP_URL_QUERY)), $needle)) {
                    return Http::response($rows, 200, ['Content-Range' => '0-0/'.count($rows)]);
                }
            }

            return Http::response([], 200, ['Content-Range' => '*/0']);
        });
    }

    public function test_the_listing_draws_featured_then_the_rest(): void
    {
        $this->fakeTables(['game_browse' => [
            ['shortname' => 'BA', 'display_name' => null, 'description' => null, 'featured_at' => null, 'downloads' => [], 'faction_count' => 1, 'unit_count' => 3, 'item_count' => 0, 'base_game' => null],
            ['shortname' => 'ZK', 'display_name' => 'Zero-K', 'description' => 'ZK', 'featured_at' => '2026-01-01', 'downloads' => [], 'faction_count' => 1, 'unit_count' => 1, 'item_count' => 0, 'base_game' => null],
            ['shortname' => 'BA-Rush', 'display_name' => null, 'description' => null, 'featured_at' => null, 'downloads' => [], 'faction_count' => 0, 'unit_count' => 0, 'item_count' => 0, 'base_game' => 'BA'],
        ]]);

        $this->get('/games')->assertOk()
            ->assertSeeInOrder(['Featured', 'Zero-K', 'All games', 'BA'])
            ->assertSee('<span class="font-medium text-neutral-100">1</span> faction', false)
            ->assertDontSee('BA-Rush');
    }

    public function test_the_listing_says_so_when_the_catalog_cannot_be_read(): void
    {
        Http::fake(['https://sb.test/*' => Http::response(['message' => 'down'], 500)]);

        $this->get('/games')->assertOk()->assertSee('The catalog could not be read just now.');
    }

    public function test_a_game_page_draws_its_row(): void
    {
        $this->fakeTables([
            'game' => [self::GAME],
            'game_browse?select=faction_count' => [['faction_count' => 1, 'unit_count' => 40, 'item_count' => 1]],
        ]);

        $this->get('/games/BA')->assertOk()
            ->assertSee('<title>Balanced Annihilation - Coilbox Hub</title>', false)
            ->assertSee('A <strong>classic</strong> game.', false)
            ->assertSee('Game version 1.2')
            ->assertSee('Install with rapid:')
            ->assertSee('href="/games/BA/units?faction=arm"', false)
            ->assertDontSee('>Random<', false)
            ->assertSee('1 faction, 40 units')
            ->assertSee('1 community item')
            ->assertSee('https://ba.test/')
            // One release cannot be compared with anything.
            ->assertDontSee('Release changes');
    }

    public function test_an_unknown_game_is_not_found_at_every_level(): void
    {
        $this->fakeTables([]);

        $this->get('/games/nope')->assertNotFound();
        $this->get('/games/nope/units')->assertNotFound();
    }

    public function test_the_units_grid_draws_cells_and_keeps_the_filters_in_its_links(): void
    {
        $this->fakeTables([
            'game' => [self::GAME],
            'game_browse?select=faction_count' => [['faction_count' => 1, 'unit_count' => 2, 'item_count' => 0]],
            'game_unit' => [['unit_name' => 'armcom', 'full_name' => 'Commander', 'faction_key' => 'arm', 'game' => ['shortname' => 'BA']]],
        ]);

        $this->get('/games/BA/units?q=com&retired=1')->assertOk()
            ->assertSee('<title>Balanced Annihilation units - Coilbox Hub</title>', false)
            ->assertSee('href="/games/BA/units/armcom"', false)
            ->assertSee('Commander')
            ->assertSee('value="com"', false);
    }
}
