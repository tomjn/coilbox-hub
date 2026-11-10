<?php

namespace Tests\Feature\Maps;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** `/maps` and `/map/{slug}` with Supabase faked. Ports the page cases of `lib/maps/page.test.ts`. */
class MapPagesTest extends TestCase
{
    private const COMET = 'Comet Catcher Remake 1.8';

    /** @var array<string, mixed> */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'sb_publishable_x', 'hub.supabase.service_role_key' => 'sb_secret_x']);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $name = preg_replace('#^/rest/v1/(rpc/)?#', '', (string) parse_url($request->url(), PHP_URL_PATH));
            $rows = array_key_exists($name, $this->answers) ? $this->answers[$name] : [];
            if ($rows === null) {
                return Http::response(['message' => 'down'], 503);
            }

            return Http::response($rows, 200, ['Content-Range' => '0-0/'.count($rows)]);
        });
        $this->answers['asset_licence'] = [['all_maps' => true, 'map_name' => null, 'redistribute_extracted' => 'allowed', 'redistribute_rendered' => 'allowed']];
    }

    private static function facts(): array
    {
        return [
            'slug' => 'comet', 'display_name' => 'Comet Catcher Remake', 'description' => 'A remake', 'authors' => [['key' => 'beherith', 'name' => 'Beherith']],
            'width_elmos' => 6144, 'height_elmos' => 10240, 'world_height_min' => -120.5, 'world_height_max' => 890,
            'min_wind' => 5, 'max_wind' => 25, 'tidal_strength' => 18, 'void_water' => true, 'water_coverage' => null,
            'tags' => ['large', 'water map'],
            'points' => ['start' => [['x' => 512, 'z' => 512, 'y' => null, 'meta' => null]], 'metal' => [], 'geo' => []],
            'appearance' => [],
        ];
    }

    private function holdComet(): void
    {
        $this->answers['map'] = [['id' => 'map-1', 'map_name' => self::COMET, 'archive_filename' => 'comet.sd7']];
        $this->answers['map_facts'] = [['map_name' => self::COMET, 'facts' => self::facts()]];
        $this->answers['map_mirror_host'] = [['name' => 'hakora', 'url_template' => 'http://hakora.xyz/{filename}', 'enabled' => true]];
    }

    public function test_a_slug_nothing_is_stored_under_is_not_found(): void
    {
        $this->get('/map/nothing')->assertNotFound();
    }

    public function test_a_map_the_hub_holds_no_facts_for_is_not_found(): void
    {
        $this->holdComet();
        $this->answers['map_facts'] = [];
        $this->get('/map/comet')->assertNotFound();
    }

    public function test_a_takedown_or_an_unreadable_licence_is_not_found(): void
    {
        $this->holdComet();
        $this->answers['asset_licence'] = [['all_maps' => null, 'map_name' => self::COMET, 'redistribute_extracted' => 'denied', 'redistribute_rendered' => 'denied']];
        $this->get('/map/comet')->assertNotFound();

        $this->answers['asset_licence'] = null;
        $this->get('/map/comet-again')->assertNotFound();
    }

    public function test_a_map_page_shows_the_facts_the_catalog_answered_with(): void
    {
        $this->holdComet();

        $response = $this->get('/map/comet');

        $response->assertOk()
            ->assertSee('<title>Comet Catcher Remake - Coilbox Hub</title>', false)
            ->assertSee('<meta name="description" content="12 x 20, 1 player - A remake">', false)
            ->assertSee('<h1 class="break-words text-3xl font-semibold tracking-tight">Comet Catcher Remake</h1>', false)
            ->assertSee('5 to 25')
            ->assertSee('href="/maps?author=beherith"', false)
            ->assertSee('href="/maps?tag=water%20map"', false)
            ->assertSee('Look for it on hakora')
            ->assertSee('http://hakora.xyz/comet.sd7', false)
            ->assertSee('<dt class="text-xs uppercase tracking-wide text-neutral-400">Water</dt>', false)
            ->assertSee('name="map_id" value="map-1"', false)
            // No minimap held, so the drawing at the catalog's shape, and no 3D view.
            ->assertSee('viewBox="0 0', false)
            ->assertDontSee('data-map-preview=', false)
            ->assertDontSee('Played on this map');
    }

    public function test_the_listing_shows_a_card_per_map_and_a_pager(): void
    {
        $this->answers['map_browse'] = [[
            'id' => 'map-1', 'map_name' => self::COMET, 'slug' => 'comet', 'display_name' => null,
            'width_elmos' => 6144, 'height_elmos' => 10240, 'tags' => ['medium'], 'start_positions' => 8,
            'author_keys' => ['beherith'], 'author_names' => ['Beherith'], 'featured_at' => '2026-01-01T00:00:00Z',
        ]];

        $this->get('/maps?sort=players&size=medium')->assertOk()
            ->assertSee('<option value="players" selected>Players, most first</option>', false)
            ->assertSee('href="/map/comet"', false)
            ->assertSee('8 players')
            ->assertSee('Featured')
            ->assertSee('href="/maps?author=beherith&amp;size=medium&amp;sort=players"', false)
            ->assertSee('Page 1 of 1, 1 map')
            ->assertSee('>Clear</a>', false);
    }

    public function test_the_listing_says_why_it_is_empty(): void
    {
        $this->get('/maps')->assertOk()->assertSee('The hub holds facts about no maps yet.');
        $this->get('/maps?q=zzz')->assertOk()->assertSee('No map matches that.');

        $this->answers['asset_licence'] = null;
        $this->get('/maps?q=other')->assertOk()->assertSee('The catalog could not be read just now.');
    }

    public function test_a_report_without_a_script_comes_back_to_the_page(): void
    {
        $this->post('/map/comet/report', ['map_id' => 'map-1', 'kind' => 'nonsense', 'reason' => 'x'])
            ->assertRedirect('/map/comet#map-report')
            ->assertSessionHas('mapReport', ['ok' => false, 'message' => 'Choose what is wrong with the map.']);

        $this->postJson('/map/comet/report', ['map_id' => 'map-1', 'kind' => 'other', 'reason' => 'It crashes'])
            ->assertExactJson(['ok' => true, 'message' => 'Reported. Thank you.']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/rest/v1/report') && $request['reporter_id'] === null);
    }
}
