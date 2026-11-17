<?php

namespace Tests\Feature\Maps;

use Illuminate\Http\Client\Request;
use App\Domain\Maps\Listing;
use Illuminate\Support\Facades\Blade;
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
            // A closure answers by query string, for tests that need two answers from one table.
            if ($rows instanceof \Closure) {
                $rows = $rows(urldecode((string) parse_url($request->url(), PHP_URL_QUERY)));
            }
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

    public function test_a_mirror_source_is_labelled_with_its_host(): void
    {
        $this->holdComet();
        $facts = self::facts() + ['downloads' => [
            ['kind' => 'url', 'value' => 'https://owner.test/comet.sd7', 'filename' => 'comet.sd7'],
            ['kind' => 'url', 'value' => 'https://mirror-a.test/maps/comet.sd7', 'filename' => 'comet.sd7', 'sha256' => str_repeat('ab', 32)],
            ['kind' => 'url', 'value' => 'https://mirror-b.test/maps/comet.sd7', 'filename' => 'comet.sd7', 'sha256' => str_repeat('ab', 32)],
        ]];
        $this->answers['map_facts'] = [['map_name' => self::COMET, 'facts' => $facts]];

        $this->get('/map/comet')
            ->assertOk()
            ->assertSee('Download comet.sd7 from mirror-a.test', false)
            ->assertSee('Download comet.sd7 from mirror-b.test', false)
            ->assertDontSee('Download comet.sd7 from owner.test', false);
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
            ->assertSee('<dt class="text-sm uppercase tracking-wide text-neutral-400">Water</dt>', false)
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
            ->assertSee('<option value="players" selected>Players most first</option>', false)
            ->assertSee('href="/map/comet"', false)
            ->assertSee('8 players')
            ->assertSee('Featured')
            ->assertSee('href="/maps?author=beherith&amp;size=medium&amp;sort=players"', false)
            ->assertSee('Page 1 of 1, 1 map')
            ->assertSee('>Clear</a>', false);
    }

    public function test_the_filter_bar(): void
    {
        $html = $this->get('/maps?play=1v1&tag=tidal')->assertOk()
            ->assertSee('<input type="hidden" name="play" value="1v1">', false)
            ->assertSee('<input type="hidden" name="tag" value="tidal">', false)
            ->assertSee('data-submit-on-change', false)
            ->assertSee('<details', false)
            ->assertDontSee('<details open', false)
            ->getContent();

        // The pager also uses aria-current, so each chip anchor is matched whole.
        foreach (['1v1', 'Tidal'] as $label) {
            $this->assertMatchesRegularExpression('#<a href="[^"]*"[^>]*aria-current="true"[^>]*>'.$label.'</a>#', $html);
        }
        $this->assertDoesNotMatchRegularExpression('#<a [^>]*aria-current="true"[^>]*>Team</a>#', $html);
        $this->assertMatchesRegularExpression('#<a [^>]*>Team</a>#', $html);

        $this->get('/maps?author=x')->assertOk()->assertSee('<details open', false);
    }

    public function test_the_search_box_takes_its_own_row_on_phones(): void
    {
        $this->get('/maps')->assertOk()->assertSee('w-full min-w-0 sm:w-auto sm:min-w-48 sm:max-w-sm sm:flex-1', false);
    }

    public function test_the_form_wraps_below_the_chips_when_the_search_would_be_too_narrow(): void
    {
        $this->get('/maps?author=x')->assertOk()
            ->assertSee('flex flex-wrap items-baseline gap-2', false)
            ->assertSee('w-full min-w-0 flex-wrap items-center gap-2 sm:w-auto sm:min-w-auto sm:flex-auto', false)
            ->assertSee('[contain:inline-size]', false);
    }

    public function test_the_listing_grid_has_two_columns_on_phones(): void
    {
        $this->answers['map_browse'] = [self::row('plain', 'Plain Map')];

        $this->get('/maps?sort=size')->assertOk()
            ->assertSee('grid-cols-[repeat(auto-fill,minmax(min(200px,calc(50%_-_8px)),1fr))]', false);
    }

    public function test_extra_non_chip_tags_survive_a_search(): void
    {
        $this->get('/maps?tag=foo&tag=bar')->assertOk()
            ->assertSee('<input type="hidden" name="tag" value="bar">', false)
            ->assertDontSee('<input type="hidden" name="tag" value="foo">', false)
            ->assertSee('id="maps-tag" type="text" class="', false)
            ->assertSee('name="tag" value="foo"', false);
    }

    public function test_the_sort_has_an_apply_button_without_scripting(): void
    {
        $this->get('/maps')->assertOk()->assertSeeHtml('<noscript><button type="submit"');
    }

    public function test_section_rows_hide_overflow_with_css(): void
    {
        $this->holdSections([self::row('fresh', 'Fresh Map')]);

        $this->get('/maps')->assertOk()->assertSee('class="map-row', false);
        $this->get('/maps')->assertSee('grid-cols-[repeat(auto-fill,minmax(min(200px,calc(50%_-_8px)),1fr))]', false);
        $this->assertMatchesRegularExpression('#<div class="map-row[^"]*">\s*<ul[ >]#', $this->get('/maps')->getContent());
    }

    public function test_uploaded_sort_with_nothing_uploaded_says_no_match(): void
    {
        $this->get('/maps?sort=uploaded')->assertOk()->assertSee('No map matches that.');
    }

    /** @return array<string, mixed> */
    private static function row(string $slug, string $name, ?string $featured = null): array
    {
        return [
            'id' => 'id-'.$slug, 'map_name' => $name, 'slug' => $slug, 'display_name' => null,
            'width_elmos' => 6144, 'height_elmos' => 10240, 'tags' => ['medium'], 'start_positions' => 8,
            'author_keys' => [], 'author_names' => [], 'featured_at' => $featured,
        ];
    }

    /** @param list<array<string, mixed>>|null $uploaded */
    private function holdSections(?array $uploaded): void
    {
        $this->answers['map_browse'] = fn (string $query) => match (true) {
            str_contains($query, 'featured_at=not.is.null') => [self::row('star', 'Star Map', '2026-01-01T00:00:00Z')],
            str_contains($query, 'uploaded_at=not.is.null') => $uploaded,
            default => [self::row('plain', 'Plain Map')],
        };
    }

    public function test_the_first_page_shows_the_sections(): void
    {
        $this->holdSections([self::row('fresh', 'Fresh Map')]);

        $this->get('/maps')->assertOk()
            ->assertSee('Featured')
            ->assertSee('View all featured')
            ->assertSee('href="/maps?featured=1"', false)
            ->assertSee('Newest uploads')
            ->assertSee('View all new uploads')
            ->assertSee('href="/maps?sort=uploaded"', false)
            ->assertSee('href="/map/fresh"', false);
    }

    public function test_sections_only_show_on_the_unfiltered_first_page(): void
    {
        $this->holdSections([self::row('fresh', 'Fresh Map')]);

        foreach (['/maps?page=2', '/maps?sort=size', '/maps?featured=1'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('View all featured');
        }
    }

    public function test_an_empty_or_failed_section_is_left_out(): void
    {
        $this->holdSections([]);
        $this->get('/maps')->assertOk()->assertDontSee('Newest uploads')->assertSee('View all featured');

        \Illuminate\Support\Facades\Cache::flush();
        $this->holdSections(null);
        $this->get('/maps')->assertOk()->assertDontSee('Newest uploads')->assertSee('href="/map/plain"', false);
    }

    public function test_a_failed_section_read_is_not_cached(): void
    {
        $this->holdSections(null);
        $this->get('/maps')->assertOk()->assertDontSee('Newest uploads');

        $this->holdSections([self::row('fresh', 'Fresh Map')]);
        $this->get('/maps')->assertOk()->assertSee('Newest uploads')->assertSee('href="/map/fresh"', false);
    }

    public function test_the_sections_sit_above_the_filter_bar(): void
    {
        $this->holdSections([self::row('fresh', 'Fresh Map')]);

        $html = $this->get('/maps')->assertOk()->getContent();
        $this->assertLessThan(strpos($html, 'type="search"'), strpos($html, 'View all featured'));
    }

    public function test_only_the_first_two_section_cards_load_eagerly(): void
    {
        $maps = [];
        $pictures = [];
        foreach (range(1, 5) as $n) {
            $maps[] = self::row('m'.$n, 'Map '.$n);
            $pictures['Map '.$n] = ['from' => 'held', 'url' => 'https://img.test/'.$n.'.png'];
        }
        $filters = Listing::parseFilters([]);

        $html = Blade::render('<x-maps.section title="Featured" more-href="/maps?featured=1" more-label="View all" :maps="$maps" :pictures="$pictures" :filters="$filters" />', compact('maps', 'pictures', 'filters'));

        $this->assertSame(5, substr_count($html, '<img'));
        $this->assertSame(3, substr_count($html, 'loading="lazy"'));
        $this->assertDoesNotMatchRegularExpression('#img\.test/[12]\.png"[^>]*loading="lazy"#', $html);
    }

    public function test_a_failed_listing_read_is_not_cached(): void
    {
        $this->answers['map_browse'] = null;
        $this->get('/maps?play=1v1')->assertOk()->assertSee('The catalog could not be read just now.');

        $this->answers['map_browse'] = [self::row('duel', 'Duel Map')];
        $this->get('/maps?play=1v1')->assertOk()->assertSee('href="/map/duel"', false);
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
