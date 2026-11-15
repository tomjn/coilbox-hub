<?php

namespace Tests\Feature\Maps;

use App\Domain\Games\Download;
use App\Domain\Maps\MapEdits;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A map's download sources: the edit page, the save, the map page's Download
 * section and edit link, asking to own a map or an author, and a moderator
 * deciding that. Supabase and the session are faked. `can_edit_map` answers
 * whatever `$canEdit` says, because the rule itself is proved in pgTAP.
 */
class MapEditTest extends TestCase
{
    private const COMET = 'Comet Catcher Remake 1.8';

    private const VISITOR = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    /** @var array<string, mixed> Rows by table or function name. */
    private array $answers = [];

    private bool $canEdit = false;

    private bool $moderator = false;

    /** @var list<Request> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk', 'hub.supabase.service_role_key' => 'service-key']);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $this->sent[] = $request;
            $name = preg_replace('#^/rest/v1/(rpc/)?#', '', (string) parse_url($request->url(), PHP_URL_PATH));
            $value = match (true) {
                $name === 'can_edit_map' => $this->canEdit,
                $name === 'is_moderator' => $this->moderator,
                $request->method() !== 'GET' && ! str_contains($request->url(), '/rpc/') => [],
                default => $this->answers[$name] ?? [],
            };

            return Http::response(json_encode($value), $request->method() === 'POST' && ! str_contains($request->url(), '/rpc/') ? 201 : 200, ['Content-Type' => 'application/json', 'Content-Range' => '0-0/1']);
        });

        $this->answers['asset_licence'] = [['all_maps' => true, 'map_name' => null, 'redistribute_extracted' => 'allowed', 'redistribute_rendered' => 'allowed']];
        $this->answers['map'] = [['id' => 'map-1', 'map_name' => self::COMET, 'archive_filename' => 'comet.sd7', 'owner_user_id' => null]];
        $this->answers['map_facts'] = [['map_name' => self::COMET, 'facts' => [
            'slug' => 'comet', 'display_name' => 'Comet Catcher Remake', 'description' => null,
            'authors' => [['key' => 'beherith', 'name' => 'Beherith'], ['key' => 'jools', 'name' => 'Jools']],
            'width_elmos' => 6144, 'height_elmos' => 10240, 'world_height_min' => 0, 'world_height_max' => 890,
            'min_wind' => null, 'max_wind' => null, 'tidal_strength' => null, 'void_water' => null, 'water_coverage' => null,
            'tags' => [], 'points' => ['start' => [], 'metal' => [], 'geo' => []], 'appearance' => [],
            'downloads' => [
                ['kind' => 'url', 'value' => 'https://maps.example.test/comet.sd7', 'filename' => 'comet.sd7'],
                ['kind' => 'github', 'value' => 'beherith/comet'],
            ],
        ]]];
        $this->answers['map_mirror_host'] = [['name' => 'hakora', 'url_template' => 'http://hakora.xyz/{filename}', 'enabled' => true]];
        $this->answers['author_owner'] = [['key' => 'jools']];
        $this->answers['map_download_source'] = [['id' => 7, 'kind' => 'url', 'value' => 'https://old.example.test/comet.sd7', 'asset' => null, 'filename' => 'comet.sd7']];
    }

    private function signedIn(string $moderation = 'none'): static
    {
        return $this->withSession([
            'supabase' => ['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600, 'user_id' => self::VISITOR, 'aal' => 'aal2'],
            'nav' => ['id' => self::VISITOR, 'name' => 'Visitor', 'moderation' => $moderation],
        ]);
    }

    /** @return list<Request> */
    private function writes(): array
    {
        return array_values(array_filter($this->sent, fn (Request $r) => in_array($r->method(), ['POST', 'PATCH', 'DELETE'], true) && ! str_contains($r->url(), '/rpc/')));
    }

    private static function payload(array $rows): string
    {
        return json_encode($rows);
    }

    // The map page.

    public function test_the_map_page_lists_the_sources_above_the_mirror_guesses(): void
    {
        $this->get('/map/comet')->assertOk()
            ->assertSeeInOrder(['Download', 'https://maps.example.test/comet.sd7', 'Download comet.sd7', 'https://github.com/beherith/comet/releases', 'Look for it on hakora'], false)
            ->assertDontSee('Edit this map')
            ->assertDontSee('Did you make this map?');
        // A signed out visitor costs no permission check.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'can_edit_map'));
    }

    public function test_a_map_with_no_sources_shows_no_download_section(): void
    {
        $this->answers['map_facts'][0]['facts']['downloads'] = [];
        $this->get('/map/comet')->assertOk()->assertDontSee('<h2 class="text-xl font-semibold tracking-tight">Download</h2>', false)->assertSee('Look for it on hakora');
    }

    public function test_an_editor_sees_the_edit_link_and_no_ownership_ask(): void
    {
        $this->canEdit = true;
        $this->signedIn()->get('/map/comet')->assertOk()
            ->assertSee('href="/map/comet/edit"', false)
            ->assertDontSee('Did you make this map?');
    }

    public function test_a_signed_in_stranger_is_offered_the_map_and_each_unowned_author(): void
    {
        $this->signedIn()->get('/map/comet')->assertOk()
            ->assertDontSee('Edit this map')
            ->assertSee('Did you make this map?')
            ->assertSee('value="map"', false)
            ->assertSee('value="author:beherith"', false)
            ->assertSee('Every map by Beherith')
            // Jools already has an owner.
            ->assertDontSee('value="author:jools"', false);
    }

    public function test_an_owned_map_is_not_offered(): void
    {
        $this->answers['map'][0]['owner_user_id'] = 'someone-else';
        $this->signedIn()->get('/map/comet')->assertOk()->assertDontSee('value="map"', false)->assertSee('value="author:beherith"', false);
    }

    // Asking to own.

    public function test_asking_for_an_author_files_a_request_for_that_key(): void
    {
        $this->signedIn()->post('/map/comet/ownership', ['target' => 'author:beherith', 'note' => 'I made it'])
            ->assertRedirect('/map/comet')->assertSessionHas('mapOwnership', 'sent');

        $writes = $this->writes();
        $this->assertCount(1, $writes);
        $this->assertStringContainsString('/rest/v1/map_ownership_request', $writes[0]->url());
        $this->assertSame([['requested_by' => self::VISITOR, 'note' => 'I made it', 'author_key' => 'beherith']], $writes[0]->data());
    }

    public function test_a_target_the_page_did_not_offer_files_nothing(): void
    {
        $this->signedIn()->post('/map/comet/ownership', ['target' => 'author:jools'])->assertSessionHas('mapOwnership', 'notSent');
        $this->signedIn()->post('/map/comet/ownership', ['target' => 'author:somebody'])->assertSessionHas('mapOwnership', 'notSent');
        $this->assertSame([], $this->writes());
    }

    public function test_asking_signed_out_goes_to_sign_in(): void
    {
        $this->post('/map/comet/ownership', ['target' => 'map'])->assertRedirect('/sign-in?next=%2Fmap%2Fcomet');
    }

    // The edit page.

    public function test_the_edit_page_sends_a_signed_out_visitor_to_sign_in(): void
    {
        $this->get('/map/comet/edit')->assertRedirect('/sign-in?next=%2Fmap%2Fcomet%2Fedit');
    }

    public function test_the_edit_page_is_not_found_for_somebody_who_cannot_edit(): void
    {
        $this->signedIn()->get('/map/comet/edit')->assertNotFound();
    }

    public function test_an_editor_gets_the_edit_page_with_the_current_sources(): void
    {
        $this->canEdit = true;
        $this->signedIn()->get('/map/comet/edit')->assertOk()
            ->assertSee('Edit Comet Catcher Remake')
            ->assertSee('data-list-editor="map-downloads"', false)
            ->assertSee('https:\/\/old.example.test\/comet.sd7', false)
            ->assertSee('Save downloads');
    }

    // Saving.

    public function test_saving_inserts_the_new_list_in_order_then_removes_the_old_rows(): void
    {
        $this->canEdit = true;
        $this->signedIn()->post('/map/comet/edit/downloads', ['_form' => 'downloads', 'downloads' => self::payload([
            ['kind' => 'github', 'value' => 'beherith/comet', 'asset' => '', 'filename' => ''],
            ['kind' => 'url', 'value' => 'https://maps.example.test/comet.sd7', 'asset' => '', 'filename' => 'comet.sd7'],
        ])])->assertSessionHas('toggle', ['form' => 'downloads', 'ok' => true, 'message' => 'Downloads saved.']);

        $writes = $this->writes();
        $this->assertCount(2, $writes);
        $this->assertSame('POST', $writes[0]->method());
        $this->assertSame([
            ['map_id' => 'map-1', 'kind' => 'github', 'value' => 'beherith/comet', 'asset' => null, 'filename' => null, 'sort_order' => 0],
            ['map_id' => 'map-1', 'kind' => 'url', 'value' => 'https://maps.example.test/comet.sd7', 'asset' => null, 'filename' => 'comet.sd7', 'sort_order' => 1],
        ], $writes[0]->data());
        $this->assertSame('DELETE', $writes[1]->method());
        $this->assertStringContainsString('id=in.(7)', urldecode($writes[1]->url()));
    }

    public function test_a_rapid_source_is_refused_naming_the_row_and_nothing_is_written(): void
    {
        $this->canEdit = true;
        $this->signedIn()->post('/map/comet/edit/downloads', ['_form' => 'downloads', 'downloads' => self::payload([
            ['kind' => 'url', 'value' => 'https://maps.example.test/comet.sd7', 'filename' => 'comet.sd7'],
            ['kind' => 'rapid', 'value' => 'comet:stable'],
        ])])->assertSessionHas('toggle', ['form' => 'downloads', 'ok' => false, 'message' => 'Source 2: Pick how the map is downloaded.']);
        $this->assertSame([], $this->writes());
    }

    public function test_somebody_who_cannot_edit_is_refused_and_nothing_is_written(): void
    {
        $this->signedIn()->post('/map/comet/edit/downloads', ['_form' => 'downloads', 'downloads' => '[]'])
            ->assertSessionHas('toggle', ['form' => 'downloads', 'ok' => false, 'message' => MapEdits::DOWNLOADS_MESSAGES['notAllowed']]);
        $this->assertSame([], $this->writes());
    }

    // Deciding.

    public function test_approving_an_author_request_makes_the_requester_its_owner(): void
    {
        $this->moderator = true;
        $this->answers['map_ownership_request'] = [['map_id' => null, 'author_key' => 'beherith', 'state' => 'open', 'requested_by' => self::VISITOR, 'map' => null]];

        $this->signedIn('granted')->post('/moderation/games/decide-map', ['request_id' => '3', 'approve' => 'true'])->assertRedirect('/moderation/games');

        $writes = $this->writes();
        $this->assertCount(2, $writes);
        $this->assertSame('PATCH', $writes[0]->method());
        $this->assertSame('approved', $writes[0]->data()['state']);
        $this->assertStringContainsString('/rest/v1/author_owner', $writes[1]->url());
        $this->assertSame([['key' => 'beherith', 'user_id' => self::VISITOR]], $writes[1]->data());
    }

    public function test_approving_a_map_request_sets_the_map_owner(): void
    {
        $this->moderator = true;
        $this->answers['map_ownership_request'] = [['map_id' => 'map-1', 'author_key' => null, 'state' => 'open', 'requested_by' => self::VISITOR, 'map' => ['slug' => 'comet']]];

        $this->signedIn('granted')->post('/moderation/games/decide-map', ['request_id' => '3', 'approve' => 'true']);

        $writes = $this->writes();
        $this->assertCount(2, $writes);
        $this->assertStringContainsString('/rest/v1/map?', $writes[1]->url());
        $this->assertSame(['owner_user_id' => self::VISITOR], $writes[1]->data());
    }

    public function test_a_non_moderator_decides_nothing(): void
    {
        $this->answers['map_ownership_request'] = [['map_id' => 'map-1', 'author_key' => null, 'state' => 'open', 'requested_by' => self::VISITOR, 'map' => ['slug' => 'comet']]];
        $this->signedIn()->post('/moderation/games/decide-map', ['request_id' => '3', 'approve' => 'true']);
        $this->assertSame([], $this->writes());
    }

    // Parsing.

    public function test_the_map_kinds_refuse_rapid_and_the_game_kinds_still_take_it(): void
    {
        $rapid = json_encode([['kind' => 'rapid', 'value' => 'comet:stable']]);
        $this->assertSame(['ok' => false, 'message' => 'Source 1: Pick how the map is downloaded.'], Download::parseDownloads($rapid, MapEdits::KINDS, 'map'));
        $this->assertTrue(Download::parseDownloads($rapid)['ok']);
    }
}
