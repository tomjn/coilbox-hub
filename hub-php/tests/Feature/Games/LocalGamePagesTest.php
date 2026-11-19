<?php

namespace Tests\Feature\Games;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsGameCatalogue;
use Tests\TestCase;

/**
 * The game, unit, release and compare pages, the games API and the sitemap
 * with the catalogue in MariaDB (#597). No request reaches Supabase for a
 * catalogue table.
 */
class LocalGamePagesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsGameCatalogue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLocalCatalogue();
        $this->seedCatalogue();
        $this->fakeSupabaseRemainder([
            'https://sb.test/rest/v1/asset*' => fn (Request $request) => Http::response(
                str_contains(urldecode($request->url()), 'variant=eq.logo')
                    ? [['faction_key' => 'arm', 'path' => 'factions/arm.webp']]
                    : [],
            ),
        ]);
        // Maps, items and the rest of the site the sitemap and layout read.
        Http::fake(['https://sb.test/*' => Http::response([])]);
    }

    protected function tearDown(): void
    {
        Http::assertNotSent(fn (Request $request) => preg_match('#/rest/v1/game[a-z_]*(\?|$)#', $request->url()) === 1);
        parent::tearDown();
    }

    public function test_the_game_page_reads_mariadb_and_draws_the_live_side_logo(): void
    {
        $this->get('/games/BA')->assertOk()
            ->assertSee('Balanced Annihilation')
            ->assertSee('factions/arm.webp');
    }

    public function test_a_hidden_game_is_not_found(): void
    {
        $this->get('/games/HID')->assertNotFound();
    }

    public function test_the_listing_and_the_units_grid(): void
    {
        $this->get('/games')->assertOk()->assertSee('Balanced Annihilation');
        $this->get('/games/BA/units')->assertOk()->assertSee('Arm Commander')->assertDontSee('Old Thing');
        $this->get('/games/BA/units?q=solar')->assertOk()->assertSee('Solar Collector')->assertDontSee('Core Commander');
    }

    public function test_unit_release_and_compare_pages(): void
    {
        $this->get('/games/BA/units/armcom')->assertOk()->assertSee('Arm Commander')->assertSee('3000');
        $this->get('/games/BA/units/armcom@1.0')->assertOk()->assertSee('2500');
        $this->get('/games/BA/units/armcom/compare?left=1.0&right=2.0')->assertOk()->assertSee('2500')->assertSee('3000');
        $this->get('/games/BA/units/armcom/compare?with=corcom')->assertOk()->assertSee('Core Commander');
        $this->get('/games/BA/units/secret')->assertNotFound();
    }

    public function test_the_tree_and_changes_pages(): void
    {
        $this->get('/games/BA/tree')->assertOk()->assertSee('Solar Collector');
        $this->get('/games/BA/changes?from=1.0&to=2.0')->assertOk();
    }

    public function test_the_games_api_lists_releases_and_downloads(): void
    {
        $this->getJson('/api/v1/games')->assertOk()
            ->assertJsonPath('games.0.shortname', 'BA')
            ->assertJsonPath('games.0.downloads', [['kind' => 'rapid', 'value' => 'ba:stable']])
            ->assertJsonPath('games.0.releases', ['2.0', '1.0'])
            ->assertJsonCount(1, 'games');
    }

    public function test_the_sitemap_names_the_visible_units(): void
    {
        $this->get('/sitemap.xml')->assertOk()
            ->assertSee('/games/BA/units/armcom', false)
            ->assertDontSee('/games/BA/units/armold', false)
            ->assertDontSee('/games/HID', false);
    }
}
