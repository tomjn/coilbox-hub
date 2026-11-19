<?php

namespace Tests\Feature\Gallery;

use App\Domain\Container\Container;
use App\Supabase\Local\ItemRules;
use App\Supabase\Local\ItemStore;
use App\Supabase\Local\MapShadow;
use App\Supabase\Local\Profiles;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Moderation\ModerationTestCase;

/**
 * Published items, reports, profiles and moderator capabilities read and
 * written in MariaDB once they have moved (#601): the gallery, `/i/{id}`,
 * `/export`, the items API, publishing, editing, reporting, featuring, the
 * moderator checks and the account directory, with Supabase asked only who a
 * token belongs to.
 */
class LocalItemsTest extends ModerationTestCase
{
    use RefreshDatabase;

    private const OWNER = 'aaaaaaaa-0000-4000-8000-00000000000a';

    private const STRANGER = 'bbbbbbbb-0000-4000-8000-00000000000b';

    private const ITEM = '11111111-1111-4111-8111-111111111111';

    private const WITHDRAWN = '22222222-2222-4222-8222-222222222222';

    private const MAP = 'dddddddd-0000-4000-8000-00000000000d';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.games_store' => 'mariadb',
            'hub.maps_store' => 'mariadb',
            'hub.pictures_store' => 'mariadb',
            'hub.mirrors_store' => 'mariadb',
            'hub.items_store' => 'mariadb',
            'app.url' => 'https://hub.test',
        ]);
        DB::table('map')->insert([
            'id' => self::MAP, 'map_name' => 'Comet Catcher Remake', 'slug' => 'comet-catcher-remake', 'width_elmos' => 6144, 'height_elmos' => 6144,
            'world_height_min' => -10.0, 'world_height_max' => 300.0, 'appearance' => '{}', 'curated_tags' => '[]', 'source_hash' => 'h',
            'source_archive' => 'Comet Catcher Remake', 'catalog_version' => 1, 'facts_digest' => 'd',
            'seen_at' => '2026-10-01 10:00:00.000000', 'created_at' => '2026-10-01 10:00:00.000000', 'updated_at' => '2026-10-01 10:00:00.000000',
        ]);
        foreach ([self::OWNER => 'Owner', self::STRANGER => 'Stranger', self::MODERATOR => 'Moddy'] as $id => $name) {
            DB::table('profile')->insert(['user_id' => $id, 'display_name' => $name, 'created_at' => '2026-10-01 10:00:00.000000', 'updated_at' => '2026-10-01 10:00:00.000000']);
        }
        DB::table('user_capability')->insert(['user_id' => self::MODERATOR, 'capability' => 'can_moderate', 'granted_at' => '2026-10-01 10:00:00.000000']);
        $this->item(self::ITEM, ['title' => 'Lobster rush', 'description' => 'Fast eco opening', 'tags' => '["eco","8v8"]', 'game_key' => 'bar']);
        $this->item(self::WITHDRAWN, ['title' => 'Old plan', 'deleted_at' => '2026-11-02 10:00:00.000000', 'created_at' => '2026-11-02 09:00:00.000000']);
    }

    /** @param array<string, mixed> $over */
    private function item(string $id, array $over = []): void
    {
        DB::table('item')->insert($over + [
            'id' => $id, 'kind' => 'preset', 'kind_version' => Container::SUPPORTED_KIND_VERSIONS['preset'], 'title' => 'An item',
            'description' => '', 'game_name' => 'Beyond All Reason', 'map_name' => 'Comet Catcher Remake', 'tags' => '[]',
            'container' => json_encode(self::container()), 'author_id' => self::OWNER, 'author_name' => 'Owner',
            'created_at' => '2026-11-01 10:00:00.000000', 'updated_at' => '2026-11-01 10:00:00.000000', 'import_count' => 0,
        ]);
    }

    /** A preset's container, with an empty object in it. */
    private static function container(): object
    {
        return (object) [
            'format' => 'coilbox', 'container' => 1, 'kind' => 'preset', 'kindVersion' => Container::SUPPORTED_KIND_VERSIONS['preset'],
            'payload' => (object) [
                'gameName' => 'Beyond All Reason', 'mapName' => 'Comet Catcher Remake', 'startPosType' => 2,
                'modOptionValues' => new \stdClass, 'participants' => [],
            ],
        ];
    }

    private function presetCode(): string
    {
        return Container::encodeCode('preset', Container::SUPPORTED_KIND_VERSIONS['preset'], self::container()->payload);
    }

    /** A signed in session as somebody, the way HubSession stores one. */
    private function as(string $userId, string $token = 'access'): static
    {
        return $this->withSession(['supabase' => [
            'access_token' => $token, 'refresh_token' => 'refresh', 'expires_at' => time() + 3600, 'user_id' => $userId, 'aal' => 'aal1',
        ]]);
    }

    /** Supabase Auth only: who a token belongs to, with no second factor. */
    private function auth(string $userId = self::OWNER, array $routes = []): void
    {
        $this->supabase($routes + ['/auth/v1/user' => self::reply(['id' => $userId, 'factors' => [], 'email' => 'x@example.test', 'user_metadata' => []])]);
    }

    /** Every request that reached Supabase for something other than sign in. */
    private function dataRequests(): array
    {
        return array_values(array_filter($this->sent, fn (Request $r) => ! str_contains($r->url(), '/auth/v1/')));
    }

    private static function jwt(array $claims): string
    {
        $part = fn (array $value) => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');

        return $part(['alg' => 'none']).'.'.$part($claims).'.sig';
    }

    public function test_the_gallery_the_api_the_container_and_the_export_read_mariadb(): void
    {
        $this->supabase();

        $this->get('/gallery')->assertOk()->assertSee('Lobster rush')->assertDontSee('Old plan');
        $this->getJson('/api/v1/items')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.id', self::ITEM);
        $this->getJson('/api/v1/items/'.self::ITEM)->assertOk()->assertJsonPath('item.title', 'Lobster rush');
        $this->get('/api/v1/items/'.self::WITHDRAWN)->assertNotFound();

        $container = $this->get('/i/'.self::ITEM)->assertOk()->getContent();
        $this->assertStringContainsString('"modOptionValues":{}', $container);
        $this->get('/i/'.self::WITHDRAWN)->assertNotFound();

        $export = $this->get('/export')->assertOk();
        $this->assertSame(1, $export->json('count'));
        $this->assertSame(['eco', '8v8'], $export->json('items.0.tags'));
        $this->assertStringContainsString('"modOptionValues":{}', $export->getContent());

        $this->get('/item/'.self::ITEM)->assertOk()->assertSee('Lobster rush');
        $this->assertSame([], $this->dataRequests());
    }

    public function test_search_tags_and_facets_filter_as_postgres_did(): void
    {
        $this->supabase();
        $this->item('33333333-3333-4333-8333-333333333333', ['title' => 'Turtle', 'tags' => '["tech"]', 'game_key' => 'zk', 'map_name' => '']);
        $ids = fn ($query) => array_column($query->get(), 'id');
        $anon = Supabase::anon();

        $this->assertSame([self::ITEM], $ids($anon->from('item')->select('id')->textSearch('search', 'lobsters', type: 'websearch')));
        $this->assertSame([self::ITEM], $ids($anon->from('item')->select('id')->textSearch('search', 'opening -turtle', type: 'websearch')));
        $this->assertSame([], $ids($anon->from('item')->select('id')->textSearch('search', 'plan', type: 'websearch')));
        $this->assertSame([self::ITEM], $ids($anon->from('item')->select('id')->overlaps('tags', ['8v8', 'nothing'])));
        $this->assertSame([], $ids($anon->from('item')->select('id')->contains('tags', ['eco', 'tech'])));

        $facets = $anon->from('item_facet')->select('facet,value')->order('value')->get();
        $this->assertSame([
            ['facet' => 'game', 'value' => 'bar'],
            ['facet' => 'map', 'value' => 'Comet Catcher Remake'],
            ['facet' => 'game', 'value' => 'zk'],
        ], $facets);
    }

    public function test_publishing_from_coilbox_writes_the_item_with_the_authors_name(): void
    {
        $this->auth();

        $response = $this->call('POST', '/api/v1/items', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer good-token', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['code' => $this->presetCode(), 'title' => 'Fresh', 'tags' => ['Eco']]));

        $response->assertCreated();
        $id = $response->json('item.id');
        $row = DB::table('item')->where('id', $id)->first();
        $this->assertSame('Owner', $row->author_name);
        $this->assertSame(self::OWNER, $row->author_id);
        $this->assertSame(['eco'], json_decode($row->tags, true));
        $this->assertNull($row->mode);
        $this->get('/i/'.$id)->assertOk()->assertJsonPath('payload.mapName', 'Comet Catcher Remake');
        $this->assertSame([], $this->dataRequests());
    }

    public function test_publishing_from_the_website_writes_the_item(): void
    {
        $this->auth();

        $this->as(self::OWNER)->post('/publish', ['code' => $this->presetCode(), 'title' => 'From the site'])
            ->assertRedirect('/publish')->assertSessionHas('publish.publishedId');

        $this->assertSame('From the site', DB::table('item')->where('id', session('publish.publishedId'))->value('title'));
        $this->assertSame([], $this->dataRequests());
    }

    public function test_publishing_past_twenty_in_an_hour_is_refused(): void
    {
        $this->auth();
        $now = now('UTC')->format('Y-m-d H:i:s.u');
        for ($i = 0; $i < ItemRules::PUBLISH_PER_HOUR; $i++) {
            $this->item(sprintf('44444444-4444-4444-8444-%012d', $i), ['created_at' => $now]);
        }

        $response = $this->call('POST', '/api/v1/items', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer good-token', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['code' => $this->presetCode(), 'title' => 'One too many']));

        $response->assertStatus(429);
        $this->assertSame('Could not publish it: Too many published in the last hour. Try again later.', $response->json('error'));
    }

    public function test_somebody_who_is_not_the_author_or_a_moderator_cannot_edit_withdraw_or_delete_an_item(): void
    {
        $this->auth(self::STRANGER);

        $this->as(self::STRANGER)->post('/item/'.self::ITEM.'/edit', ['title' => 'Hacked', 'description' => 'x', 'tags' => ''])->assertRedirect();
        $this->as(self::STRANGER)->post('/item/'.self::ITEM.'/withdraw', ['withdrawn' => 'true'])->assertRedirect();
        $this->as(self::STRANGER)->get('/item/'.self::ITEM.'/edit')->assertNotFound();

        $client = Supabase::forToken('access', self::STRANGER);
        $this->assertSame([], $client->from('item')->update(['title' => 'Hacked'])->eq('id', self::ITEM)->select('id')->get());
        $this->assertSame([], $client->from('item')->delete()->eq('id', self::ITEM)->select('id')->get());
        // Nor may they read the author's withdrawn item.
        $this->assertSame([], $client->from('item')->select('id')->eq('id', self::WITHDRAWN)->get());
        try {
            $client->from('item')->insert(['kind' => 'preset', 'kind_version' => 1, 'title' => 'As them', 'container' => self::container(), 'author_id' => self::OWNER])->execute();
            $this->fail('published as another account');
        } catch (SupabaseException $e) {
            $this->assertSame('42501', $e->errorCode);
        }

        $row = DB::table('item')->where('id', self::ITEM)->first();
        $this->assertSame('Lobster rush', $row->title);
        $this->assertNull($row->deleted_at);
        $this->assertSame(2, DB::table('item')->count());
    }

    public function test_the_author_edits_withdraws_and_puts_back_their_item(): void
    {
        $this->auth();

        $this->as(self::OWNER)->get('/item/'.self::WITHDRAWN.'/edit')->assertOk()->assertSee('Old plan');
        $this->as(self::OWNER)->post('/item/'.self::ITEM.'/edit', ['title' => 'Lobster rush 2', 'description' => 'New words', 'tags' => 'Eco, team'])
            ->assertRedirect('/item/'.self::ITEM);
        $row = DB::table('item')->where('id', self::ITEM)->first();
        $this->assertSame('Lobster rush 2', $row->title);
        $this->assertSame(['eco', 'team'], json_decode($row->tags, true));
        $this->assertNotSame('2026-11-01 10:00:00.000000', $row->updated_at);

        $this->as(self::OWNER)->post('/item/'.self::ITEM.'/withdraw', ['withdrawn' => 'true'])->assertRedirect('/item/'.self::ITEM.'/withdrawn');
        $this->assertNotNull(DB::table('item')->where('id', self::ITEM)->value('deleted_at'));
        $this->as(self::OWNER)->post('/item/'.self::ITEM.'/withdraw', ['withdrawn' => 'false']);
        $this->assertNull(DB::table('item')->where('id', self::ITEM)->value('deleted_at'));

        // A container cannot be changed after publishing.
        $this->expectException(SupabaseException::class);
        Supabase::forToken('access', self::OWNER)->from('item')->update(['container' => self::container()])->eq('id', self::ITEM)->execute();
    }

    public function test_a_moderator_edits_withdraws_and_features_any_item(): void
    {
        $this->auth(self::MODERATOR);
        $moderator = Supabase::forToken('access', self::MODERATOR);

        $this->assertSame([['id' => self::WITHDRAWN]], $moderator->from('item')->select('id')->eq('id', self::WITHDRAWN)->get());
        $this->assertSame([['id' => self::ITEM]], $moderator->from('item')->update(['title' => 'Tidied'])->eq('id', self::ITEM)->select('id')->get());

        $this->as(self::MODERATOR)->post('/item/'.self::ITEM.'/feature', ['id' => self::ITEM, 'featured' => 'true'])->assertRedirect();
        $row = DB::table('item')->where('id', self::ITEM)->first();
        $this->assertSame('Tidied', $row->title);
        $this->assertNotNull($row->featured_at);
        $this->assertSame(self::MODERATOR, $row->featured_by);
        $this->getJson('/api/v1/items')->assertJsonPath('items.0.featured_at', fn ($at) => $at !== null);

        // A withdrawn item is not featured, and taking one off works on any.
        $this->assertFalse($moderator->rpc('set_item_featured', ['item_id' => self::WITHDRAWN, 'featured' => true]));
        $this->assertTrue($moderator->rpc('set_item_featured', ['item_id' => self::ITEM, 'featured' => false]));
        $this->assertNull(DB::table('item')->where('id', self::ITEM)->value('featured_at'));
        $this->assertSame([], $this->dataRequests());
    }

    public function test_somebody_who_is_not_a_moderator_cannot_feature(): void
    {
        $this->auth(self::STRANGER);

        $this->as(self::STRANGER)->post('/item/'.self::ITEM.'/feature', ['id' => self::ITEM, 'featured' => 'true'])
            ->assertSessionHas('featureState', ['ok' => false, 'message' => 'Only a moderator can feature a gallery item.']);
        $this->assertNull(DB::table('item')->where('id', self::ITEM)->value('featured_at'));
    }

    public function test_reports_are_taken_from_anybody_once_and_handled_by_a_moderator(): void
    {
        $this->auth(self::MODERATOR);

        $this->post('/item/'.self::ITEM.'/report', ['reason' => 'Spam'])->assertRedirect();
        $this->post('/item/'.self::ITEM.'/report', ['reason' => 'Spam again'])->assertRedirect();
        $this->assertSame(1, DB::table('report')->count());
        Supabase::anon()->from('report')->insert(['map_id' => self::MAP, 'kind' => 'crashes_engine', 'reason' => 'Crashes'])->execute();
        $this->assertSame(2, DB::table('report')->count());

        try {
            Supabase::anon()->from('report')->select('id')->get();
            $this->fail('anon read reports');
        } catch (SupabaseException $e) {
            $this->assertSame('42501', $e->errorCode);
        }
        $this->assertSame([], Supabase::forToken('access', self::STRANGER)->from('report')->select('id')->get());

        $this->as(self::MODERATOR)->get('/moderation')->assertOk()->assertSee('Spam')->assertSee('Lobster rush')->assertSee('Crashes')->assertSee('Comet Catcher Remake');
        $report = DB::table('report')->whereNotNull('item_id')->value('id');
        $this->as(self::MODERATOR)->post('/moderation/reports', ['report_id' => $report, 'item_id' => self::ITEM, 'withdraw' => 'true'])->assertRedirect('/moderation');

        $this->assertNotNull(DB::table('item')->where('id', self::ITEM)->value('deleted_at'));
        $this->assertSame(self::MODERATOR, DB::table('report')->where('id', $report)->value('handled_by'));
        $this->assertSame([], $this->dataRequests());
    }

    public function test_capability_access_follows_the_session_and_its_second_factor(): void
    {
        $verified = ['id' => self::MODERATOR, 'factors' => [['id' => 'f', 'status' => 'verified', 'factor_type' => 'totp']]];
        $this->supabase(['/auth/v1/user' => self::reply($verified)]);

        $aal1 = Supabase::forToken('access', self::MODERATOR);
        $this->assertSame('verify', $aal1->rpc('capability_access', ['capability' => 'can_moderate']));
        $this->assertFalse($aal1->rpc('is_moderator'));
        $this->assertFalse($aal1->rpc('has_capability', ['capability' => 'can_moderate']));

        $aal2 = Supabase::forToken(self::jwt(['aal' => 'aal2', 'sub' => self::MODERATOR]), self::MODERATOR);
        $this->assertSame('granted', $aal2->rpc('capability_access', ['capability' => 'can_moderate']));
        $this->assertTrue($aal2->rpc('is_moderator'));
        $this->assertSame('none', $aal2->rpc('capability_access', ['capability' => 'can_seed_unit_assets']));

        $this->assertSame('none', Supabase::forToken('access', self::STRANGER)->rpc('capability_access', ['capability' => 'can_moderate']));
        $this->assertFalse(Supabase::anon()->rpc('is_moderator'));
        $this->assertSame([], $this->dataRequests());
    }

    public function test_the_account_directory_lists_accounts_with_names_and_capabilities(): void
    {
        $users = [
            ['id' => self::OWNER, 'created_at' => '2026-10-02T10:00:00.5Z'],
            ['id' => self::MODERATOR, 'created_at' => '2026-10-03T10:00:00Z'],
            ['id' => self::STRANGER, 'created_at' => '2026-10-01T10:00:00Z'],
        ];
        $this->supabase([
            '/auth/v1/admin/users' => fn (Request $r) => self::reply(['users' => str_contains($r->url(), 'page=1&') ? $users : []]),
            '/auth/v1/user' => self::reply(['id' => self::MODERATOR, 'factors' => []]),
        ]);

        $moderator = Supabase::forToken('access', self::MODERATOR);
        $rows = $moderator->rpc('moderator_accounts', ['p_search' => null, 'p_offset' => 0]);
        $this->assertSame([self::MODERATOR, self::OWNER, self::STRANGER], array_column($rows, 'id'));
        $this->assertSame(['id' => self::MODERATOR, 'display_name' => 'Moddy', 'created_at' => '2026-10-03T10:00:00+00:00', 'capabilities' => ['can_moderate']], $rows[0]);
        $this->assertSame('2026-10-02T10:00:00.5+00:00', $rows[1]['created_at']);
        $this->assertSame([self::STRANGER], array_column($moderator->rpc('moderator_accounts', ['p_search' => 'STRAN', 'p_offset' => 0]), 'id'));
        $this->assertSame([self::OWNER], array_column($moderator->rpc('moderator_accounts', ['p_search' => self::OWNER, 'p_offset' => 0]), 'id'));

        $this->as(self::MODERATOR)->get('/moderation/users')->assertOk()->assertSee('Moddy')->assertSee('Stranger');

        try {
            Supabase::forToken('access', self::STRANGER)->rpc('moderator_accounts', ['p_search' => null, 'p_offset' => 0]);
            $this->fail('a stranger listed the accounts');
        } catch (SupabaseException $e) {
            $this->assertSame('42501', $e->errorCode);
        }
    }

    public function test_display_names_are_renamed_once_in_any_case_and_read_by_anybody(): void
    {
        $this->auth();

        $this->as(self::OWNER)->post('/account/rename', ['displayName' => 'STRANGER'])
            ->assertSessionHas('rename.error', 'That name is already in use, in any mix of capital and small letters. Choose another.');
        $this->as(self::OWNER)->post('/account/rename', ['displayName' => 'Owner the Second'])->assertSessionHas('rename.saved', 'Owner the Second');
        $this->assertSame('Owner the Second', DB::table('profile')->where('user_id', self::OWNER)->value('display_name'));

        // Nobody renames somebody else, and when an account was made is not public.
        $this->assertSame([], Supabase::forToken('access', self::OWNER)->from('profile')->update(['display_name' => 'Taken'])->eq('user_id', self::STRANGER)->select('user_id')->get());
        $this->assertSame([['display_name' => 'Stranger']], Supabase::anon()->from('profile')->select('display_name')->eq('user_id', self::STRANGER)->get());
        $this->expectException(SupabaseException::class);
        Supabase::anon()->from('profile')->select('created_at')->get();
    }

    public function test_a_new_account_gets_a_profile_as_the_sign_up_trigger_gave_one(): void
    {
        $fresh = 'eeeeeeee-0000-4000-8000-00000000000e';
        // `Owner` is taken in any case, so the next number.
        $this->assertSame('owner 2', Profiles::seed(['id' => $fresh, 'email' => 'x@example.test', 'user_metadata' => ['full_name' => ' owner ']]));
        $this->assertSame('owner 2', Profiles::seed(['id' => $fresh, 'user_metadata' => ['full_name' => 'Somebody else']]));

        $other = 'ffffffff-0000-4000-8000-00000000000f';
        $this->assertSame('mail', Profiles::seed(['id' => $other, 'email' => 'mail@example.test', 'user_metadata' => ['name' => 'x']]));
        $this->assertSame('Coilbox user 12345678', Profiles::base('12345678-9abc-4000-8000-000000000000', [], 'a@example.test'));

        config(['hub.items_store' => 'supabase']);
        $this->assertNull(Profiles::seed(['id' => '99999999-0000-4000-8000-000000000009']));
        $this->assertSame(0, DB::table('profile')->where('user_id', '99999999-0000-4000-8000-000000000009')->count());
    }

    public function test_a_bearer_request_gives_a_new_coilbox_account_its_profile(): void
    {
        $fresh = 'eeeeeeee-0000-4000-8000-00000000000e';
        $this->supabase(['/auth/v1/user' => self::reply(['id' => $fresh, 'email' => 'pilot@example.test', 'user_metadata' => ['preferred_username' => 'Pilot']])]);

        $this->call('GET', '/api/v1/profile', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer t'])->assertOk()->assertJsonPath('display_name', 'Pilot');
    }

    public function test_deleting_an_account_takes_its_items_profile_and_capabilities(): void
    {
        DB::table('report')->insert(['id' => '55555555-5555-4555-8555-555555555555', 'item_id' => self::ITEM, 'reason' => 'x', 'created_at' => '2026-11-01 10:00:00.000000', 'reporter_id' => self::OWNER]);
        DB::table('report')->insert(['id' => '66666666-6666-4666-8666-666666666666', 'map_id' => self::MAP, 'kind' => 'other', 'reason' => 'y', 'created_at' => '2026-11-01 10:00:00.000000', 'reporter_id' => self::OWNER]);

        ItemRules::forgetAccount(self::OWNER);

        $this->assertSame(0, DB::table('item')->where('author_id', self::OWNER)->count());
        $this->assertSame(0, DB::table('profile')->where('user_id', self::OWNER)->count());
        $this->assertSame(['66666666-6666-4666-8666-666666666666'], DB::table('report')->pluck('id')->all());
        $this->assertNull(DB::table('report')->value('reporter_id'));

        ItemRules::forgetAccount(self::MODERATOR);
        $this->assertSame(0, DB::table('user_capability')->count());
    }

    public function test_map_rows_stay_out_of_supabase_once_reports_and_mirrors_have_moved(): void
    {
        $this->supabase();

        MapShadow::pushMaps([self::MAP]);
        MapShadow::clear(self::MAP);
        $this->assertSame([], $this->sent);

        config(['hub.items_store' => 'supabase']);
        $this->supabase(['POST /rest/v1/map' => self::reply([])]);
        MapShadow::pushMaps([self::MAP]);
        $this->assertCount(1, $this->sent);
    }

    public function test_the_copy_waits_for_maps_then_copies_and_switches_and_going_back_pushes_the_maps(): void
    {
        config(['hub.items_store' => null, 'hub.maps_store' => 'supabase']);
        foreach (['report', 'item', 'profile', 'user_capability'] as $table) {
            DB::table($table)->delete();
        }
        $this->supabase();

        $this->artisan('items:copy')->expectsOutputToContain('Move maps first, with maps:copy.')->assertExitCode(1);
        $this->assertSame(ItemStore::SUPABASE, ItemStore::fresh());

        config(['hub.maps_store' => 'mariadb']);
        $rows = [
            'profile' => [['user_id' => self::OWNER, 'display_name' => 'Owner', 'created_at' => '2026-10-01T10:00:00+00:00', 'updated_at' => '2026-10-01T10:00:00+00:00']],
            'user_capability' => [['user_id' => self::MODERATOR, 'capability' => 'can_moderate', 'granted_at' => '2026-10-01T10:00:00.25+00:00', 'granted_by' => null]],
            'item' => [[
                'id' => self::ITEM, 'kind' => 'preset', 'kind_version' => 3, 'title' => 'Copied', 'description' => '', 'game_name' => null,
                'map_name' => null, 'tags' => ['eco'], 'container' => self::container(), 'author_id' => self::OWNER, 'author_name' => 'Owner',
                'created_at' => '2026-11-01T10:00:00+00:00', 'updated_at' => '2026-11-01T10:00:00+00:00', 'deleted_at' => null, 'mode' => null,
                'game_key' => null, 'import_count' => 4, 'featured_at' => null, 'featured_by' => null,
            ]],
            'report' => [[
                'id' => '55555555-5555-4555-8555-555555555555', 'item_id' => null, 'reporter_id' => null, 'reason' => 'Crashes',
                'created_at' => '2026-11-01T11:00:00+00:00', 'handled_at' => null, 'handled_by' => null, 'map_id' => self::MAP, 'kind' => 'crashes_engine',
            ]],
        ];
        $this->supabase(['/rest/v1/' => function (Request $r) use ($rows) {
            preg_match('#/rest/v1/(\w+)\?#', $r->url(), $m);
            $table = $m[1];
            $range = ['Content-Range' => '0-0/'.count($rows[$table])];
            if ($r->method() === 'HEAD') {
                return Http::response('', 200, $range);
            }
            $sample = str_contains(urldecode($r->url()), 'select=*') ? array_map(fn ($row) => $row + ['search' => "'x':1"], $rows[$table]) : $rows[$table];

            return self::reply(str_contains($r->url(), 'offset=') && ! str_contains($r->url(), 'offset=0') ? [] : $sample, 200, $range);
        }]);

        $this->artisan('items:copy --dry-run')->expectsOutputToContain('item: Supabase 1, MariaDB 0')->assertExitCode(0);
        $this->assertSame(0, DB::table('item')->count());

        $this->artisan('items:copy')->expectsOutputToContain('The hub now reads and writes published items')->assertExitCode(0);
        $this->assertSame(ItemStore::MARIADB, ItemStore::fresh());
        $this->assertSame('{"kind": "preset", "format": "coilbox", "payload": {"mapName": "Comet Catcher Remake", "gameName": "Beyond All Reason", "participants": [], "startPosType": 2, "modOptionValues": {}}, "container": 1, "kindVersion": 1}',
            ItemRules::jsonbText(json_decode(DB::table('item')->value('container'))));
        $this->assertSame('2026-10-01 10:00:00.250000', DB::table('user_capability')->value('granted_at'));
        $this->assertSame(self::MAP, DB::table('report')->value('map_id'));

        $this->artisan('items:copy')->expectsOutputToContain('already reads published items from MariaDB')->assertExitCode(1);
        config(['hub.mirrors_store' => 'supabase']);
        $this->artisan('maps:store supabase')->expectsOutputToContain('Run items:store supabase first.')->assertExitCode(1);

        $this->supabase(['POST /rest/v1/map?' => self::reply([])]);
        $this->artisan('items:store supabase')->expectsOutputToContain('The hub reads published items from supabase.')->assertExitCode(0);
        $this->assertSame(ItemStore::SUPABASE, ItemStore::fresh());
        $pushed = array_values(array_filter($this->sent, fn (Request $r) => str_contains($r->url(), '/rest/v1/map?')));
        $this->assertSame(self::MAP, $pushed[0]->data()[0]['id']);
    }
}
