<?php

namespace Tests\Feature\Gallery;

use App\Domain\Container\Container;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The gallery's public pages, with PostgREST faked. */
class PagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'hub.supabase.url' => 'https://sb.test',
            'hub.supabase.publishable_key' => 'pk',
            'app.url' => 'https://hub.test',
        ]);
    }

    /** Every read answers with no rows. */
    private function fakeEmpty(): void
    {
        Http::fake(['https://sb.test/*' => Http::response([], 200, ['Content-Range' => '*/0'])]);
    }

    /** @return list<string> */
    private function itemUrls(): array
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), '/rest/v1/item?'))
            ->map(fn ($pair) => urldecode($pair[0]->url()))->values()->all();
    }

    public function test_the_landing_page_renders_with_no_items(): void
    {
        $this->fakeEmpty();

        $this->get('/')->assertOk();
    }

    public function test_the_gallery_with_an_unknown_kind_still_renders(): void
    {
        $this->fakeEmpty();

        $this->get('/gallery?kind=nope')->assertOk();
    }

    public function test_two_kinds_reach_postgrest_as_one_in_filter(): void
    {
        $this->fakeEmpty();

        $this->get('/gallery?kind=preset&kind=blueprint')->assertOk();

        $matching = array_filter($this->itemUrls(), fn ($url) => str_contains($url, 'kind=in.(preset,blueprint)'));
        $this->assertNotEmpty($matching, 'No item read carried kind=in.(preset,blueprint). Saw: '.implode(' | ', $this->itemUrls()));
    }

    public function test_the_feed_with_an_unknown_kind_is_a_400_naming_the_kinds(): void
    {
        Http::fake();

        $response = $this->get('/feed.xml?kind=nope');

        $response->assertStatus(400);
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
        foreach (Container::GALLERY_KINDS as $kind) {
            $this->assertStringContainsString($kind, $response->getContent());
        }
        Http::assertNothingSent();
    }

    public function test_a_profile_address_that_is_not_a_uuid_is_a_404(): void
    {
        Http::fake();

        $this->get('/profile/not-a-uuid')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_an_item_page_is_a_404_when_postgrest_returns_nothing(): void
    {
        $this->fakeEmpty();

        $this->get('/item/11111111-1111-4111-8111-111111111111')->assertNotFound();
    }
}
