<?php

namespace Tests\Feature\Games;

use App\Domain\Games\GameEdits;
use App\Domain\Games\ImageUpload;
use App\Domain\Moderation\GameDecisions;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A game's edit page and the writes behind it, from `app/games/editing.test.ts`
 * and `app/games/formActions.test.ts`. The owner or a moderator gets through,
 * anybody else gets a 404 or a refusal and nothing is written. Supabase and
 * the session are faked, so nothing reaches the network.
 */
class GameEditTest extends TestCase
{
    private const URL = 'https://sb.test';

    private const OWNER = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    private const STRANGER = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

    private const MODERATOR = 'cccccccc-cccc-cccc-cccc-cccccccccccc';

    /** 2x2, 8 bit RGB, the frozen PNG the Next tests use. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAAPoAAAD6AG1e1JrAAAAEklEQVQImWMQqTghUnGCAUIBACJuBVHwJsa1AAAAAElFTkSuQmCC';

    private const GAME = [
        'shortname' => 'BA', 'display_name' => 'Balanced Annihilation', 'description' => null,
        'links' => [], 'conquest_factions' => [['name' => 'Arm']],
        'owner_user_id' => self::OWNER, 'hidden_at' => null, 'base_game' => null,
        'logo_path' => 'games/BA/logo.png', 'logo_hash' => 'h', 'logo_staged_tier' => 'bucket',
        'banner_path' => null, 'banner_hash' => null, 'banner_staged_tier' => null,
        'card_path' => null, 'card_hash' => null, 'card_staged_tier' => null,
        'game_download_source' => [['kind' => 'rapid', 'value' => 'ba:stable', 'asset' => null, 'filename' => null, 'sort_order' => 0]],
        'game_faction' => [], 'game_version' => [],
    ];

    /** @var list<Request> */
    private array $sent = [];

    /** Who owns the faked game. */
    private ?string $owner = self::OWNER;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.supabase.url' => self::URL,
            'hub.supabase.publishable_key' => 'pk',
            'hub.supabase.service_role_key' => 'service-key',
        ]);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function as(string $userId): static
    {
        return $this->withSession(['supabase' => [
            'access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600,
            'user_id' => $userId, 'aal' => 'aal2',
        ]]);
    }

    /**
     * Supabase as the given visitor sees it. `game` rows filtered by owner
     * answer only for the owner, and `is_moderator` only for the moderator.
     * `$routes` maps `METHOD fragment` to a response, checked first.
     */
    private function supabase(string $visitor, array $routes = []): void
    {
        $this->sent = [];
        Http::fake(function (Request $request) use ($visitor, $routes) {
            $this->sent[] = $request;
            $url = urldecode($request->url());
            foreach ($routes as $fragment => $response) {
                [$method, $needle] = explode(' ', $fragment, 2);
                if ($method === $request->method() && str_contains($url, $needle)) {
                    return is_callable($response) ? $response($request) : $response;
                }
            }
            if (str_contains($url, '/rpc/is_moderator')) {
                return self::reply($visitor === self::MODERATOR);
            }
            if ($request->method() === 'GET' && str_contains($url, '/rest/v1/game?')) {
                if (! str_contains($url, 'shortname=eq.BA')) {
                    return self::reply([]);
                }
                if (str_contains($url, 'owner_user_id=eq.') && ! str_contains($url, 'owner_user_id=eq.'.self::OWNER)) {
                    return self::reply([]);
                }

                return self::reply([['id' => 'game-ba', 'owner_user_id' => $this->owner] + self::GAME]);
            }
            if ($request->method() === 'GET') {
                return self::reply(str_contains($url, '/rest/v1/game_browse') && str_contains($url, 'shortname=eq.BA') ? [['faction_count' => 1, 'unit_count' => 2, 'item_count' => 0]] : []);
            }

            return self::reply(['message' => 'unexpected '.$request->method().' '.$url], 500);
        });
    }

    private static function reply(mixed $value, int $status = 200): mixed
    {
        return Http::response(json_encode($value), $status, ['Content-Type' => 'application/json']);
    }

    /** @return list<Request> */
    private function writes(): array
    {
        return array_values(array_filter($this->sent, fn (Request $r) => in_array($r->method(), ['PATCH', 'DELETE'], true)
            || ($r->method() === 'POST' && ! str_contains($r->url(), '/rpc/is_moderator'))));
    }

    private static function png(string $name = 'banner.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    // The edit page.

    public function test_a_signed_out_visitor_is_sent_to_sign_in_and_back(): void
    {
        $this->get('/games/BA/edit')->assertRedirect('/sign-in?next=%2Fgames%2FBA%2Fedit');
    }

    public function test_a_signed_in_account_that_is_neither_owner_nor_moderator_gets_404_on_the_edit_page(): void
    {
        $this->supabase(self::STRANGER);
        $this->as(self::STRANGER)->get('/games/BA/edit')->assertNotFound();
    }

    public function test_a_moderator_who_does_not_own_the_game_gets_the_edit_page_with_offers_and_releases(): void
    {
        $this->supabase(self::MODERATOR, [
            'GET /rest/v1/game_download_offer' => self::reply([['id' => 9, 'kind' => 'github', 'value' => 'beyond/ba', 'asset' => 'BA_v2', 'filename' => null, 'created_at' => '2026-01-01', 'game' => ['shortname' => 'BA']]]),
            'GET /rest/v1/game_version' => self::reply([['version' => '1.2', 'hidden_at' => null], ['version' => '1.1', 'hidden_at' => '2026-01-01']]),
        ]);

        $this->as(self::MODERATOR)->get('/games/BA/edit')->assertOk()
            ->assertSee('Edit BA')
            ->assertSee('Coilbox has offered a place to fetch BA from.')
            ->assertSee('beyond/ba, picking BA_v2')
            ->assertSeeInOrder(['1.2', 'Hide release', '1.1', 'Unhide release'])
            ->assertSee('Remove logo')
            ->assertDontSee('Remove banner');
        // Offers are read with the secret key, and only for this game.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'game_download_offer') && str_contains(urldecode($r->url()), 'game_id=eq.game-ba')
            && $r->header('apikey')[0] === 'service-key');
    }

    public function test_the_owner_still_gets_the_edit_page(): void
    {
        $this->supabase(self::OWNER);
        $this->as(self::OWNER)->get('/games/BA/edit')->assertOk()->assertSee('Save downloads');
    }

    public function test_a_flash_key_nobody_wrote_puts_no_words_on_the_edit_page(): void
    {
        $this->supabase(self::OWNER);
        $this->as(self::OWNER)->get('/games/BA/edit?visibility=game-hidden')->assertSee('Game hidden.');
        $this->as(self::OWNER)->get('/games/BA/edit?visibility=Hacked')->assertDontSee('Hacked');
    }

    // Details.

    public function test_a_saved_edit_tells_the_form(): void
    {
        $this->supabase(self::OWNER, ['PATCH /rest/v1/game?' => self::reply([['shortname' => 'BA']])]);

        $this->as(self::OWNER)->from('/games/BA/edit')->post('/games/BA/edit/details', [
            '_form' => 'details', 'display_name' => ' Blood Angels ', 'description' => '', 'label' => ['Home', ''], 'url' => ['https://ba.test', 'x'],
        ])->assertRedirect('/games/BA/edit')->assertSessionHas('toggle.message', GameEdits::EDIT_MESSAGES['saved']);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains(urldecode($r->url()), 'shortname=eq.BA')
            && $r->data() === ['display_name' => 'Blood Angels', 'description' => null, 'links' => [['label' => 'Home', 'url' => 'https://ba.test']]]
            && $r->header('Authorization')[0] === 'Bearer access');
    }

    public function test_a_signed_out_edit_says_so_and_writes_nothing(): void
    {
        $this->supabase(self::OWNER);
        $this->from('/games/BA/edit')->post('/games/BA/edit/details', ['display_name' => 'x'])
            ->assertSessionHas('toggle.message', GameEdits::EDIT_MESSAGES['signedOut']);
        $this->assertSame([], $this->writes());
    }

    public function test_a_stranger_edit_is_refused_before_any_write(): void
    {
        $this->supabase(self::STRANGER);
        $this->as(self::STRANGER)->from('/games/BA/edit')->post('/games/BA/edit/details', ['display_name' => 'x'])
            ->assertSessionHas('toggle.message', GameEdits::EDIT_MESSAGES['notAllowed'])
            ->assertSessionHasInput('display_name', 'x');
        $this->assertSame([], $this->writes());
    }

    public function test_an_edit_the_ownership_policy_filters_out_to_zero_rows_is_refused(): void
    {
        $this->supabase(self::OWNER, ['PATCH /rest/v1/game?' => self::reply([])]);
        $this->as(self::OWNER)->from('/games/BA/edit')->post('/games/BA/edit/details', [])
            ->assertSessionHas('toggle.message', GameEdits::EDIT_MESSAGES['notAllowed']);
    }

    public function test_a_write_the_database_refuses_is_reported_as_not_saved(): void
    {
        $this->supabase(self::OWNER, ['PATCH /rest/v1/game?' => self::reply(['code' => '42501', 'message' => 'no'], 403)]);
        $this->as(self::OWNER)->from('/games/BA/edit')->post('/games/BA/edit/details', [])
            ->assertSessionHas('toggle.message', GameEdits::EDIT_MESSAGES['notSaved']);
    }

    // Conquest factions.

    public function test_a_saved_faction_list_keeps_its_order(): void
    {
        $this->supabase(self::OWNER, ['PATCH /rest/v1/game?' => self::reply([['shortname' => 'BA']])]);
        $this->as(self::OWNER)->from('/games/BA/edit')->post('/games/BA/edit/factions', ['factions' => '[{"name":"Core","color":"#aa0000"},{"name":"Arm"}]'])
            ->assertSessionHas('toggle.message', GameEdits::CONQUEST_FACTIONS_MESSAGES['saved']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['conquest_factions' => [['name' => 'Core', 'color' => '#aa0000'], ['name' => 'Arm']]]);
    }

    public function test_factions_that_fail_to_parse_are_saved_as_an_empty_list_rather_than_a_crash(): void
    {
        $this->supabase(self::OWNER, ['PATCH /rest/v1/game?' => self::reply([['shortname' => 'BA']])]);
        $this->as(self::OWNER)->from('/games/BA/edit')->post('/games/BA/edit/factions', ['factions' => 'not json'])
            ->assertSessionHas('toggle.message', GameEdits::CONQUEST_FACTIONS_MESSAGES['saved']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['conquest_factions' => []]);
    }

    // Download sources.

    public function test_a_saved_download_list_goes_in_before_the_old_rows_come_out(): void
    {
        $this->supabase(self::OWNER, [
            'GET /rest/v1/game_download_source' => self::reply([['id' => 1], ['id' => 2]]),
            'POST /rest/v1/game_download_source' => self::reply([]),
            'DELETE /rest/v1/game_download_source' => self::reply([]),
        ]);

        $this->as(self::OWNER)->from('/games/BA/edit')->post('/games/BA/edit/downloads', [
            'downloads' => '[{"kind":"github","value":"beyond/ba","asset":"BA","filename":""},{"kind":"rapid","value":"ba:test","asset":"","filename":""}]',
        ])->assertSessionHas('toggle.message', GameEdits::DOWNLOADS_MESSAGES['saved']);

        $writes = $this->writes();
        $this->assertSame(['POST', 'DELETE'], array_map(fn (Request $r) => $r->method(), $writes));
        $this->assertSame([
            ['game_id' => 'game-ba', 'kind' => 'github', 'value' => 'beyond/ba', 'asset' => 'BA', 'filename' => null, 'sort_order' => 0],
            ['game_id' => 'game-ba', 'kind' => 'rapid', 'value' => 'ba:test', 'asset' => null, 'filename' => null, 'sort_order' => 1],
        ], $writes[0]->data());
        $this->assertStringContainsString('id=in.(1,2)', urldecode($writes[1]->url()));
    }

    public function test_a_bad_download_row_is_named_and_nothing_is_written(): void
    {
        $this->supabase(self::OWNER);
        $this->as(self::OWNER)->from('/games/BA/edit')->post('/games/BA/edit/downloads', ['downloads' => '[{"kind":"rapid","value":"no colon"}]'])
            ->assertSessionHas('toggle.ok', false)
            ->assertSessionHas('toggle.message', fn (string $m) => str_starts_with($m, 'Source 1: '));
        $this->assertSame([], $this->writes());
    }

    public function test_a_stranger_cannot_empty_a_download_list(): void
    {
        $this->supabase(self::STRANGER);
        $this->as(self::STRANGER)->from('/games/BA/edit')->post('/games/BA/edit/downloads', ['downloads' => '[]'])
            ->assertSessionHas('toggle.message', GameEdits::DOWNLOADS_MESSAGES['notAllowed']);
        $this->assertSame([], $this->writes());
    }

    // Snippets.

    public function test_a_saved_snippet_tells_the_form(): void
    {
        $this->supabase(self::OWNER, ['PATCH /rest/v1/game_unit' => self::reply([['unit_name' => 'armcom']])]);
        $this->as(self::OWNER)->from('/games/BA/units/armcom')->post('/games/BA/edit/snippet', ['unit_name' => 'armcom', 'snippet' => ' Elite. '])
            ->assertRedirect('/games/BA/units/armcom')
            ->assertSessionHas('toggle.message', GameEdits::SNIPPET_MESSAGES['saved']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains(urldecode($r->url()), 'game_id=eq.game-ba')
            && str_contains(urldecode($r->url()), 'unit_name=eq.armcom') && $r->data() === ['snippet' => 'Elite.']);
    }

    public function test_a_snippet_for_a_game_nobody_may_change_is_refused(): void
    {
        $this->supabase(self::STRANGER);
        $this->as(self::STRANGER)->from('/games/BA/units/armcom')->post('/games/BA/edit/snippet', ['unit_name' => 'armcom'])
            ->assertSessionHas('toggle.message', GameEdits::SNIPPET_MESSAGES['notAllowed']);
        $this->assertSame([], $this->writes());
    }

    public function test_a_snippet_write_filtered_out_to_zero_rows_is_refused_and_one_missing_the_unit_never_writes(): void
    {
        $this->supabase(self::OWNER, ['PATCH /rest/v1/game_unit' => self::reply([])]);
        $this->as(self::OWNER)->from('/games/BA')->post('/games/BA/edit/snippet', ['unit_name' => 'armcom'])
            ->assertSessionHas('toggle.message', GameEdits::SNIPPET_MESSAGES['notAllowed']);
        $this->as(self::OWNER)->from('/games/BA')->post('/games/BA/edit/snippet', [])
            ->assertSessionHas('toggle.message', GameEdits::SNIPPET_MESSAGES['notSent']);
    }

    // Pictures.

    public function test_a_strangers_banner_upload_writes_nothing_and_says_why(): void
    {
        $this->supabase(self::STRANGER);
        $this->as(self::STRANGER)->from('/games/BA/edit')->post('/games/BA/edit/image', ['kind' => 'banner', 'image' => self::png()])
            ->assertSessionHas('toggle.message', ImageUpload::UPLOAD_MESSAGES['notAllowed']);
        $this->assertSame([], $this->writes());
    }

    public function test_a_moderators_banner_upload_lands_in_the_bucket_and_marks_the_row_staged_there(): void
    {
        $this->supabase(self::MODERATOR, [
            'POST /storage/v1/object/staged-pictures/games/BA/banner.png' => self::reply(['Key' => 'x']),
            'PATCH /rest/v1/game?' => self::reply([]),
        ]);

        $this->as(self::MODERATOR)->from('/games/BA/edit')->post('/games/BA/edit/image', [
            '_form' => 'image-banner', 'kind' => 'banner', 'image' => self::png(), 'note' => 'Shrunk from 704 KB to 1 KB.',
        ])->assertSessionHas('toggle', ['form' => 'image-banner', 'ok' => true, 'message' => 'Shrunk from 704 KB to 1 KB. Banner uploaded.']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/storage/v1/object/staged-pictures/games/BA/banner.png')
            && $r->header('x-upsert')[0] === 'true' && $r->header('apikey')[0] === 'service-key');
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains(urldecode($r->url()), 'id=eq.game-ba') && $r->data() === [
            'banner_path' => 'games/BA/banner.png',
            'banner_hash' => hash('sha256', base64_decode(self::PNG)),
            'banner_staged_tier' => 'bucket',
        ]);
    }

    public function test_a_note_that_is_not_the_browsers_own_sentence_is_dropped(): void
    {
        $this->supabase(self::OWNER, [
            'POST /storage/v1/object' => self::reply(['Key' => 'x']),
            'PATCH /rest/v1/game?' => self::reply([]),
        ]);
        $this->as(self::OWNER)->from('/games/BA/edit')->post('/games/BA/edit/image', ['kind' => 'logo', 'image' => self::png('logo.png'), 'note' => 'You have been hacked.'])
            ->assertSessionHas('toggle.message', 'Logo uploaded.');
    }

    public function test_an_upload_with_no_file_asks_for_one_and_a_jpeg_is_refused(): void
    {
        $this->supabase(self::OWNER);
        $this->as(self::OWNER)->from('/games/BA/edit')->post('/games/BA/edit/image', ['kind' => 'banner'])
            ->assertSessionHas('toggle.message', ImageUpload::UPLOAD_MESSAGES['noFile']);
        $jpeg = UploadedFile::fake()->createWithContent('photo.jpg', "\xFF\xD8\xFF\xE0".str_repeat("\0", 60));
        $this->as(self::OWNER)->from('/games/BA/edit')->post('/games/BA/edit/image', ['kind' => 'banner', 'image' => $jpeg])
            ->assertSessionHas('toggle.message', ImageUpload::UPLOAD_MESSAGES['wrongType']);
        $this->assertSame([], $this->writes());
    }

    public function test_a_file_over_512_kb_is_refused_with_its_size_before_anything_is_asked(): void
    {
        $this->supabase(self::OWNER);
        $big = UploadedFile::fake()->createWithContent('big.png', str_repeat("\0", 720_583));
        $this->as(self::OWNER)->from('/games/BA/edit')->post('/games/BA/edit/image', ['kind' => 'banner', 'image' => $big])
            ->assertSessionHas('toggle.message', 'This file is 704 KB. The largest picture you can upload is 512 KB. Make it smaller and try again.');
        $this->assertSame([], $this->sent);
    }

    public function test_a_signed_out_upload_asks_the_visitor_to_sign_in(): void
    {
        $this->supabase(self::OWNER);
        $this->from('/games/BA/edit')->post('/games/BA/edit/image', ['kind' => 'banner', 'image' => self::png()])
            ->assertSessionHas('toggle.message', ImageUpload::UPLOAD_MESSAGES['signedOut']);
    }

    public function test_a_bucket_that_refuses_the_upload_leaves_the_row_alone(): void
    {
        $this->supabase(self::OWNER, ['POST /storage/v1/object' => self::reply(['statusCode' => '500', 'message' => 'down'], 500)]);
        $this->as(self::OWNER)->from('/games/BA/edit')->post('/games/BA/edit/image', ['kind' => 'banner', 'image' => self::png()])
            ->assertSessionHas('toggle.message', ImageUpload::UPLOAD_MESSAGES['notSaved']);
        $this->assertSame([], array_filter($this->writes(), fn (Request $r) => $r->method() === 'PATCH'));
    }

    public function test_a_row_that_will_not_take_the_new_path_is_reported(): void
    {
        $this->supabase(self::OWNER, [
            'POST /storage/v1/object' => self::reply(['Key' => 'x']),
            'PATCH /rest/v1/game?' => self::reply(['message' => 'row refused'], 400),
        ]);
        $this->as(self::OWNER)->from('/games/BA/edit')->post('/games/BA/edit/image', ['kind' => 'banner', 'image' => self::png()])
            ->assertSessionHas('toggle.message', ImageUpload::UPLOAD_MESSAGES['notSaved']);
    }

    public function test_a_moderator_removes_a_banner_by_clearing_all_three_columns(): void
    {
        $this->supabase(self::MODERATOR, ['PATCH /rest/v1/game?' => self::reply([])]);
        $this->as(self::MODERATOR)->from('/games/BA/edit')->post('/games/BA/edit/image/remove', ['kind' => 'banner'])
            ->assertSessionHas('toggle.message', 'Banner removed.');
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains(urldecode($r->url()), 'id=eq.game-ba')
            && $r->data() === ['banner_path' => null, 'banner_hash' => null, 'banner_staged_tier' => null] && $r->header('apikey')[0] === 'service-key');
        $this->assertSame([], array_filter($this->sent, fn (Request $r) => str_contains($r->url(), '/storage/')));
    }

    public function test_a_stranger_cannot_remove_a_logo_and_a_removal_naming_no_kind_writes_nothing(): void
    {
        $this->supabase(self::STRANGER);
        $this->as(self::STRANGER)->from('/games/BA/edit')->post('/games/BA/edit/image/remove', ['kind' => 'logo'])
            ->assertSessionHas('toggle.message', ImageUpload::REMOVE_MESSAGES['notAllowed']);
        $this->as(self::OWNER)->from('/games/BA/edit')->post('/games/BA/edit/image/remove', ['kind' => 'faction_logo'])
            ->assertSessionHas('toggle.message', ImageUpload::REMOVE_MESSAGES['notSent']);
        $this->assertSame([], $this->writes());
    }

    // Visibility and offers, through GameDecisions.

    public function test_hiding_a_game_from_its_own_page_redirects_to_its_edit_page_with_the_key(): void
    {
        $this->supabase(self::OWNER, ['PATCH /rest/v1/game?' => self::reply([])]);
        $this->as(self::OWNER)->from('/games/BA')->post('/games/BA/edit/visibility', ['hidden' => 'true', 'onSuccess' => 'edit'])
            ->assertRedirect('/games/BA/edit?visibility=game-hidden');
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data()['hidden_by'] === self::OWNER);
    }

    public function test_a_refused_hide_with_on_success_set_still_answers_in_place(): void
    {
        $this->supabase(self::STRANGER);
        $this->as(self::STRANGER)->from('/games/BA')->post('/games/BA/edit/visibility', ['hidden' => 'true', 'onSuccess' => 'edit'])
            ->assertRedirect('/games/BA')
            ->assertSessionHas('toggle.message', GameDecisions::VISIBILITY_MESSAGES['notAllowed']);
        $this->assertSame([], $this->writes());
    }

    public function test_showing_a_release_again_tells_the_form_and_one_missing_its_version_never_writes(): void
    {
        $this->supabase(self::OWNER, ['PATCH /rest/v1/game_version' => self::reply([])]);
        $this->as(self::OWNER)->from('/games/BA/edit')->post('/games/BA/edit/version-visibility', ['version' => '1.1', 'hidden' => 'false'])
            ->assertSessionHas('toggle.message', 'Release shown again.');
        $this->as(self::OWNER)->from('/games/BA/edit')->post('/games/BA/edit/version-visibility', ['hidden' => 'true'])
            ->assertSessionHas('toggle.message', GameDecisions::VISIBILITY_MESSAGES['notSent']);
    }

    public function test_an_offer_is_decided_by_the_database_from_the_edit_page(): void
    {
        $this->supabase(self::OWNER, ['POST /rpc/decide_game_download_offer' => self::reply(true)]);
        $this->as(self::OWNER)->from('/games/BA/edit')->post('/games/BA/edit/offer', ['offer' => '9', 'accept' => 'true'])
            ->assertRedirect('/games/BA/edit')
            ->assertSessionHas('toggle.message', GameDecisions::OFFER_MESSAGES['accepted']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'decide_game_download_offer') && $r->data() === ['p_offer_id' => 9, 'p_accept' => true]
            && $r->header('Authorization')[0] === 'Bearer access');
    }

    // Ownership.

    public function test_asking_to_own_a_game_inserts_the_request_as_the_visitor(): void
    {
        $this->supabase(self::STRANGER, ['POST /rest/v1/game_ownership_request' => self::reply([])]);
        $this->as(self::STRANGER)->post('/games/BA/ownership', ['note' => ' I made it. '])->assertRedirect('/games/BA');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), 'game_ownership_request')
            && $r->data() === ['game_id' => 'game-ba', 'requested_by' => self::STRANGER, 'note' => 'I made it.'] && $r->header('Authorization')[0] === 'Bearer access');
    }

    public function test_a_signed_out_ownership_ask_is_sent_to_sign_in(): void
    {
        $this->post('/games/BA/ownership', ['note' => 'x'])->assertRedirect('/sign-in?next=%2Fgames%2FBA');
    }

    // The game page's own controls.

    public function test_the_owner_sees_the_pen_and_the_hide_switch_and_a_stranger_does_not(): void
    {
        $this->supabase(self::OWNER);
        $this->withSession(['nav' => ['id' => self::OWNER, 'name' => 'Owner', 'moderation' => 'none']])->get('/games/BA')
            ->assertSee('Edit this game')->assertSee('Hide this game')->assertDontSee('Request ownership');
        $this->withSession(['nav' => ['id' => self::STRANGER, 'name' => 'S', 'moderation' => 'verify']])->get('/games/BA')
            ->assertDontSee('Edit this game');
        $this->withSession(['nav' => ['id' => self::MODERATOR, 'name' => 'M', 'moderation' => 'granted']])->get('/games/BA')
            ->assertSee('Edit this game');
    }

    public function test_a_game_nobody_owns_asks_a_signed_in_visitor_and_nobody_else(): void
    {
        $this->owner = null;
        $this->supabase(self::STRANGER);
        $this->withSession(['nav' => ['id' => self::STRANGER, 'name' => 'S', 'moderation' => 'none']])->get('/games/BA')
            ->assertSee('Request ownership')->assertDontSee('Edit this game');
        $this->flushSession();
        $this->get('/games/BA')->assertDontSee('Request ownership');
    }
}
