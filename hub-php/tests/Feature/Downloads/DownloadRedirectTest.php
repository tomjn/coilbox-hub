<?php

namespace Tests\Feature\Downloads;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The counted download links on the map and game pages. */
class DownloadRedirectTest extends TestCase
{
    private const COMET = 'Comet Catcher Remake 1.8';

    private int $rpcStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk', 'hub.supabase.service_role_key' => 'sk', 'cache.default' => 'array']);
        Http::fake(function (Request $request) {
            $name = preg_replace('#^/rest/v1/(rpc/)?#', '', (string) parse_url($request->url(), PHP_URL_PATH));
            if ($name === 'record_download') {
                return Http::response($this->rpcStatus === 200 ? 'true' : ['message' => 'down'], $this->rpcStatus, ['Content-Type' => 'application/json']);
            }
            $rows = match ($name) {
                'map' => ! str_contains(urldecode((string) parse_url($request->url(), PHP_URL_QUERY)), 'slug=eq.comet') ? [] : [['id' => 'map-1', 'map_name' => self::COMET, 'archive_filename' => 'comet.sd7']],
                'map_facts' => [['map_name' => self::COMET, 'facts' => $this->facts()]],
                'asset_licence' => [['all_maps' => true, 'map_name' => null, 'redistribute_extracted' => 'allowed', 'redistribute_rendered' => 'allowed']],
                'game' => [$this->game()],
                'game_browse' => str_contains((string) parse_url($request->url(), PHP_URL_QUERY), 'select=faction_count') ? [['faction_count' => 0, 'unit_count' => 0, 'item_count' => 0]] : [],
                default => [],
            };

            return Http::response($rows, 200, ['Content-Range' => '0-0/'.count($rows)]);
        });
    }

    private function facts(): array
    {
        return [
            'slug' => 'comet', 'display_name' => 'Comet Catcher Remake', 'description' => 'A remake', 'authors' => [],
            'width_elmos' => 6144, 'height_elmos' => 10240, 'tags' => [],
            'points' => ['start' => [], 'metal' => [], 'geo' => []], 'appearance' => [],
            'downloads' => [
                ['kind' => 'url', 'value' => 'https://owner.test/comet.sd7', 'filename' => 'comet.sd7'],
                ['kind' => 'github', 'value' => 'owner/comet'],
            ],
        ];
    }

    private function game(): array
    {
        return [
            'shortname' => 'BA', 'display_name' => 'Balanced Annihilation', 'description' => null, 'links' => [], 'conquest_factions' => null,
            'owner_user_id' => null, 'hidden_at' => null, 'base_game' => null,
            'logo_path' => null, 'logo_hash' => null, 'logo_staged_tier' => null,
            'banner_path' => null, 'banner_hash' => null, 'banner_staged_tier' => null,
            'card_path' => null, 'card_hash' => null, 'card_staged_tier' => null,
            'game_download_source' => [
                ['kind' => 'rapid', 'value' => 'ba:stable', 'asset' => null, 'filename' => null, 'sort_order' => 0],
                ['kind' => 'url', 'value' => 'https://ba.test/ba.sdz', 'asset' => null, 'filename' => 'ba.sdz', 'sort_order' => 1],
            ],
            'game_faction' => [], 'game_version' => [],
        ];
    }

    private function rpcCalls(): int
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/rpc/record_download'))->count();
    }

    public function test_a_map_download_redirects_to_its_href_and_counts_once(): void
    {
        $this->get('/map/comet/download/0')->assertRedirect('https://owner.test/comet.sd7')->assertStatus(302);
        $this->assertSame(1, $this->rpcCalls());
        $this->get('/map/comet/download/1')->assertRedirect('https://github.com/owner/comet/releases');
    }

    public function test_a_map_download_past_the_end_or_for_an_unknown_map_is_not_found(): void
    {
        $this->get('/map/comet/download/2')->assertNotFound();
        $this->assertSame(0, $this->rpcCalls());
        $this->get('/map/nothing/download/0')->assertNotFound();
    }

    public function test_the_redirect_happens_when_counting_fails(): void
    {
        $this->rpcStatus = 500;
        $this->get('/map/comet/download/0')->assertRedirect('https://owner.test/comet.sd7');
    }

    public function test_the_map_page_links_through_the_counter(): void
    {
        $this->get('/map/comet')->assertOk()
            ->assertSee('/map/comet/download/0', false)
            ->assertSee('nofollow noreferrer', false);
    }

    public function test_a_game_download_redirects_by_index_and_rapid_is_not_found(): void
    {
        $this->get('/games/BA/download/1')->assertRedirect('https://ba.test/ba.sdz');
        $this->assertSame(1, $this->rpcCalls());
        $this->get('/games/BA/download/0')->assertNotFound();
        $this->get('/games/BA/download/2')->assertNotFound();
        $this->get('/games/nothing/download/0')->assertNotFound();
    }

    public function test_the_game_page_links_through_the_counter(): void
    {
        $this->get('/games/BA')->assertOk()
            ->assertSee('/games/BA/download/1', false)
            ->assertDontSee('/games/BA/download/0', false)
            ->assertSee('nofollow noreferrer', false);
    }
}
