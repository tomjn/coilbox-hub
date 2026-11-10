<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** `/robots.txt`, `/sitemap.xml` and the link preview cards, against a faked Supabase. */
class SeoTest extends TestCase
{
    private const URL = 'https://sb.test';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.supabase.url' => self::URL,
            'hub.supabase.publishable_key' => 'pk',
            'hub.supabase.service_role_key' => 'service-key',
            'app.url' => 'https://hub.test',
        ]);
    }

    public function test_robots_names_the_sitemap_on_this_host(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSee('Sitemap: https://hub.test/sitemap.xml', false);
    }

    public function test_the_sitemap_lists_items_maps_and_games(): void
    {
        Http::fake(function ($request) {
            $url = urldecode($request->url());
            $first = str_contains($url, 'offset=0') || ! str_contains($url, 'offset=');

            return match (true) {
                ! $first => Http::response([]),
                str_contains($url, '/rest/v1/item') => Http::response([['id' => 'i1', 'updated_at' => '2026-10-03T09:00:00+00:00']]),
                str_contains($url, '/rest/v1/map') => Http::response([['map_name' => 'Seed', 'slug' => 'seed'], ['map_name' => 'Gone', 'slug' => 'gone']]),
                str_contains($url, '/rest/v1/asset_licence') => Http::response([['map_name' => 'Seed', 'all_maps' => false, 'redistribute_rendered' => 'allowed']]),
                str_contains($url, '/rest/v1/game_unit') => Http::response([['unit_name' => 'armcom', 'game' => ['shortname' => 'BAR']]]),
                str_contains($url, '/rest/v1/game_version') => Http::response([['game' => ['shortname' => 'BAR']], ['game' => ['shortname' => 'BAR']]]),
                str_contains($url, '/rest/v1/game') => Http::response([['shortname' => 'BAR']]),
                default => Http::response([]),
            };
        });

        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml');
        $body = $response->getContent();
        $this->assertStringContainsString('<loc>https://hub.test/item/i1</loc>', $body);
        $this->assertStringContainsString('<lastmod>2026-10-03T09:00:00.000Z</lastmod>', $body);
        $this->assertStringContainsString('<loc>https://hub.test/map/seed</loc>', $body);
        $this->assertStringNotContainsString('/map/gone', $body);
        $this->assertStringContainsString('<loc>https://hub.test/games/BAR/changes</loc>', $body);
        $this->assertStringContainsString('<loc>https://hub.test/games/BAR/units/armcom</loc>', $body);
    }

    public function test_a_failed_read_is_an_error_rather_than_a_short_sitemap(): void
    {
        Http::fake(['*' => Http::response(['message' => 'down'], 500)]);

        $this->get('/sitemap.xml')->assertStatus(500);
    }

    public function test_an_unknown_item_gets_the_generic_card(): void
    {
        Http::fake(['*' => Http::response([])]);

        $response = $this->get('/item/nope/opengraph-image')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'public, s-maxage=3600, stale-while-revalidate=86400');
        $this->assertSame([1200, 630], array_slice(getimagesizefromstring($response->getContent()), 0, 2));
    }

    public function test_the_home_card_needs_no_database(): void
    {
        Http::fake();

        $this->get('/opengraph-image')->assertOk()->assertHeader('Content-Type', 'image/png');
        Http::assertNothingSent();
    }

    public function test_pages_link_their_card(): void
    {
        Http::fake();

        $this->get('/publish')->assertSee('<meta property="og:image" content="https://hub.test/opengraph-image">', false);
    }
}
