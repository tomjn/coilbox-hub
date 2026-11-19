<?php

namespace Tests\Feature\Maps;

use App\Supabase\Local\MapStore;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Moderation\ModerationTestCase;

/**
 * Maps read and written in MariaDB once they have moved (#599): submissions,
 * lookups, the listing, the author views, editing and moderation, with
 * Supabase asked only for what has not moved and its `map` rows kept in step.
 */
class LocalMapsTest extends ModerationTestCase
{
    use RefreshDatabase;

    private const USER = '11111111-1111-1111-1111-111111111111';

    private const STRANGER = '22222222-2222-2222-2222-222222222222';

    private const COMET = 'aaaaaaaa-0000-4000-8000-000000000001';

    private const DUNE = 'aaaaaaaa-0000-4000-8000-000000000002';

    private const ENTRY = [
        'map_name' => 'Comet Catcher Remake 1.8',
        'source_archive' => 'Comet Catcher Remake 1.8',
        'source_hash' => 'src-comet',
        'catalog_version' => 3,
        'width_elmos' => 6144,
        'height_elmos' => 10240,
        'world_height_min' => -120.5,
        'world_height_max' => 890,
        'author' => '[teh]Beherith & IceXuick',
        'points' => [
            'start' => [['x' => 512, 'z' => 512], ['x' => 5000, 'z' => 9000]],
            'metal' => [['x' => 100.25, 'z' => 200, 'y' => 15, 'meta' => ['amount' => 2.5, 'radius' => 80]]],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.maps_store' => 'mariadb']);
    }

    /** @param array<string, mixed> $over */
    private function map(string $id, string $name, string $slug, array $over = []): void
    {
        DB::table('map')->insert([
            'id' => $id, 'map_name' => $name, 'slug' => $slug, 'display_name' => null, 'description' => null, 'map_version' => null,
            'archive_filename' => null, 'width_elmos' => 6144, 'height_elmos' => 6144, 'world_height_min' => -10.0,
            'world_height_max' => 300.0, 'min_wind' => null, 'max_wind' => null, 'tidal_strength' => null, 'void_water' => null,
            'void_ground' => null, 'water_coverage' => null, 'appearance' => '{}', 'curated_tags' => '[]', 'source_hash' => 'h',
            'source_archive' => $name, 'catalog_version' => 1, 'facts_digest' => 'd', 'submitted_by' => null,
            'seen_at' => '2026-10-01 10:00:00.000000', 'created_at' => '2026-10-01 10:00:00.000000',
            'updated_at' => '2026-10-01 10:00:00.000000', 'featured_at' => null, 'featured_by' => null, 'owner_user_id' => null, ...$over,
        ]);
    }

    /** @param list<array{0: string, 1: string}> $credits raw and key, in credit order */
    private function credit(string $mapId, array $credits): void
    {
        foreach ($credits as $index => [$raw, $key]) {
            DB::table('map_author')->insert(['map_id' => $mapId, 'credit_index' => $index, 'raw' => $raw, 'key' => $key]);
        }
    }

    private function starts(string $mapId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('map_point')->insert(['map_id' => $mapId, 'kind' => 'start', 'ordinal' => $i, 'x' => 1.0, 'z' => 1.0]);
        }
    }

    /** @return list<Request> requests that reached Supabase's map tables or functions */
    private function mapRequests(string $pattern = '#/rest/v1/(map|author_|rpc/(map_facts|submit_map_facts|author_key|resolved_author_key|merge_author_keys|can_edit_map|add_maps_to_pack))#'): array
    {
        return array_values(array_filter($this->sent, fn (Request $r) => preg_match($pattern, urldecode($r->url())) === 1));
    }

    private function submit(array $entry): array
    {
        $body = json_encode(['format' => 'coilbox-hub-maps', 'version' => 1, 'maps' => [$entry]]);
        $response = $this->call('POST', '/api/v1/maps', [], [], [], $this->transformHeadersToServerVars(['Content-Type' => 'application/json', 'Authorization' => 'Bearer good']), $body);
        $response->assertOk();

        return $response->json('results.0');
    }

    public function test_a_submission_is_written_here_and_its_map_row_kept_in_step_in_supabase(): void
    {
        $this->supabase([
            'GET /auth/v1/user' => self::reply(['id' => self::USER]),
            'POST /rest/v1/map_source_conflict' => self::reply([], 201),
            'POST /rest/v1/map?' => self::reply([], 201),
        ]);
        DB::table('author_alias')->insert(['from_key' => 'icexuick', 'to_key' => 'ice', 'set_at' => '2026-10-01 10:00:00.000000']);

        $this->assertSame(['map_name' => 'Comet Catcher Remake 1.8', 'outcome' => 'stored'], $this->submit(self::ENTRY));

        $map = DB::table('map')->first();
        $this->assertSame(['comet-catcher-remake-1-8', self::USER, 3], [$map->slug, $map->submitted_by, (int) $map->catalog_version]);
        $this->assertSame(3, DB::table('map_point')->where('map_id', $map->id)->count());
        $this->assertSame('{"amount":2.5,"radius":80}', DB::table('map_point')->where('kind', 'metal')->value('meta'));
        $this->assertSame(
            [['[teh]Beherith', 'beherith'], ['IceXuick', 'ice']],
            DB::table('map_author')->orderBy('credit_index')->get()->map(fn ($a) => [$a->raw, $a->key])->all(),
        );
        $shadows = $this->mapRequests('#/rest/v1/map\?#');
        $this->assertCount(1, $shadows);
        $this->assertStringContainsString('on_conflict=map_name', urldecode($shadows[0]->url()));
        $this->assertStringContainsString('resolution=merge-duplicates', $shadows[0]->header('Prefer')[0]);
        $this->assertSame($map->id, $shadows[0]->data()[0]['id']);
        $this->assertSame([], $this->mapRequests('#/rpc/#'));

        // The same facts again move seen_at only, which Supabase is not told.
        $this->assertSame('unchanged', $this->submit(self::ENTRY)['outcome']);
        $this->assertCount(1, $this->mapRequests('#/rest/v1/map\?#'));

        // Other bytes under the same name are a conflict, kept here and there.
        $this->assertSame('conflict', $this->submit(['source_hash' => 'src-other'] + self::ENTRY)['outcome']);
        $this->assertSame('src-other', DB::table('map_source_conflict')->value('reported_source_hash'));
        $this->assertCount(1, $this->mapRequests('#/rest/v1/map_source_conflict#'));

        // A newer extraction replaces the facts and the credits.
        $this->assertSame('replaced', $this->submit(['catalog_version' => 4, 'author' => 'Nixa'] + self::ENTRY)['outcome']);
        $this->assertSame(['nixa'], DB::table('map_author')->pluck('key')->all());
        $this->assertCount(2, $this->mapRequests('#/rest/v1/map\?#'));
    }

    public function test_one_bad_entry_is_refused_alone_and_the_rate_limit_refuses_the_batch(): void
    {
        $this->supabase([
            'GET /auth/v1/user' => self::reply(['id' => self::USER]),
            'POST /rest/v1/map?' => self::reply([], 201),
        ]);
        $this->map(self::DUNE, 'Dune 1.0', 'comet-catcher-remake-1-8');

        // The slug is taken, so the alternative is used.
        $this->assertSame('stored', $this->submit(self::ENTRY)['outcome']);
        $this->assertNotSame('comet-catcher-remake-1-8', DB::table('map')->where('map_name', self::ENTRY['map_name'])->value('slug'));

        $refused = $this->submit(['map_name' => 'Long 1.0', 'author' => str_repeat('x', 300)] + self::ENTRY);
        $this->assertSame(['refused', 'new row for relation "map_author" violates check constraint "map_author_key_check"'], [$refused['outcome'], $refused['said']]);
        $this->assertFalse(DB::table('map')->where('map_name', 'Long 1.0')->exists());

        for ($i = 0; $i < 5000; $i++) {
            $rows[] = ['id' => sprintf('bbbbbbbb-0000-4000-8000-%012d', $i), 'map_name' => 'Bulk '.$i, 'slug' => 'bulk-'.$i, 'width_elmos' => 1,
                'height_elmos' => 1, 'world_height_min' => 0, 'world_height_max' => 0, 'appearance' => '{}', 'curated_tags' => '[]',
                'source_hash' => 'h', 'source_archive' => 'a', 'catalog_version' => 1, 'facts_digest' => 'd', 'submitted_by' => self::USER,
                'seen_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'created_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'updated_at' => now('UTC')->format('Y-m-d H:i:s.u')];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('map')->insert($chunk);
        }
        $body = json_encode(['format' => 'coilbox-hub-maps', 'version' => 1, 'maps' => [['map_name' => 'One More 1.0'] + self::ENTRY]]);
        $this->call('POST', '/api/v1/maps', [], [], [], $this->transformHeadersToServerVars(['Content-Type' => 'application/json', 'Authorization' => 'Bearer good']), $body)
            ->assertStatus(429);
    }

    public function test_a_lookup_answers_from_here_with_mirror_copies_from_supabase(): void
    {
        $sha = str_repeat('ab', 32);
        $this->supabase([
            'GET /rest/v1/asset_licence' => self::reply([['all_maps' => true, 'map_name' => null, 'redistribute_extracted' => 'allowed', 'redistribute_rendered' => 'allowed']]),
            'GET /rest/v1/map_browse' => fn (Request $r) => self::reply(str_contains(urldecode($r->url()), 'offset=0')
                ? [['map_name' => 'Comet 1.0', 'uploaded_at' => '2026-11-01T10:00:00+00:00', 'mirror_urls' => ['https://m.test/maps/comet.sd7']]] : []),
            'POST /rest/v1/rpc/map_mirror_sources' => self::reply([['kind' => 'url', 'value' => 'https://m.test/maps/comet.sd7', 'filename' => 'comet.sd7', 'sha256' => $sha]]),
        ]);
        $this->map(self::COMET, 'Comet 1.0', 'comet', ['display_name' => 'Comet', 'water_coverage' => 0.5, 'curated_tags' => '["classic"]', 'appearance' => '{"sky":"blue"}']);
        $this->map(self::DUNE, 'Dune 1.0', 'dune');
        $this->credit(self::COMET, [['[teh]Beherith', 'beherith'], ['Beh', 'beh'], ['IceXuick', 'icexuick']]);
        DB::table('author_alias')->insert(['from_key' => 'beh', 'to_key' => 'beherith', 'set_at' => '2026-10-01 10:00:00.000000']);
        DB::table('author_name')->insert(['key' => 'beherith', 'name' => 'Beherith', 'set_at' => '2026-10-01 10:00:00.000000']);
        DB::table('map_point')->insert(['map_id' => self::COMET, 'kind' => 'metal', 'ordinal' => 0, 'x' => 1.5, 'z' => 2.0, 'y' => null, 'meta' => '{"amount":2}']);
        DB::table('map_download_source')->insert(['map_id' => self::COMET, 'kind' => 'url', 'value' => 'https://owner.test/comet.sd7', 'filename' => 'comet.sd7', 'sort_order' => 0]);

        $response = $this->postJson('/api/v1/maps/lookup', ['names' => ['Comet 1.0', 'Nothing 1.0']])->assertOk();

        $facts = $response->json('results.0.map');
        $this->assertSame([['key' => 'beherith', 'name' => 'Beherith'], ['key' => 'icexuick', 'name' => 'IceXuick']], $facts['authors']);
        $this->assertSame(['classic', 'small', 'water map'], $facts['tags']);
        $this->assertSame(['geo' => [], 'metal' => [['x' => 1.5, 'y' => null, 'z' => 2, 'meta' => ['amount' => 2]]], 'start' => []], $facts['points']);
        $this->assertSame(['sky' => 'blue'], $facts['appearance']);
        $this->assertSame([
            ['kind' => 'url', 'value' => 'https://owner.test/comet.sd7', 'filename' => 'comet.sd7'],
            ['kind' => 'url', 'value' => 'https://m.test/maps/comet.sd7', 'sha256' => $sha, 'filename' => 'comet.sd7'],
        ], $facts['downloads']);
        $this->assertNull($response->json('results.1.map'));
        $this->assertSame([], $this->mapRequests('#/rest/v1/(map\?|map_point|map_author|rpc/map_facts)#'));
        // Only the mirrored map is asked about its copies.
        $this->assertCount(1, $this->mapRequests('#/rpc/map_mirror_sources#'));
    }

    public function test_the_listing_filters_here_and_asks_supabase_only_for_upload_dates(): void
    {
        $this->supabase([
            'GET /rest/v1/asset_licence' => self::reply([['all_maps' => true, 'map_name' => null, 'redistribute_extracted' => 'allowed', 'redistribute_rendered' => 'allowed']]),
            'GET /rest/v1/map_browse' => fn (Request $r) => self::reply(str_contains(urldecode($r->url()), 'offset=0')
                ? [['map_name' => 'Dune 1.0', 'uploaded_at' => '2026-11-01T10:00:00+00:00', 'mirror_urls' => []]] : []),
            'GET /rest/v1/author_alias' => self::reply([]),
            'GET /rest/v1/asset' => self::reply([]),
        ]);
        $this->map(self::COMET, 'Comet 1.0', 'comet', ['description' => 'Rocky islands in a frozen sea', 'water_coverage' => 0.5]);
        $this->map(self::DUNE, 'Dune 1.0', 'dune', ['description' => 'Running sand', 'width_elmos' => 20000]);
        $this->credit(self::COMET, [['[teh]Beherith', 'beherith']]);
        $this->credit(self::DUNE, [['IceXuick', 'icexuick']]);
        $this->starts(self::COMET, 2);
        $this->starts(self::DUNE, 8);

        $this->get('/maps?author=%5BBAR%5D+Beherith')->assertOk()->assertSee('/map/comet', false)->assertDontSee('/map/dune', false);
        $this->get('/maps?q=island')->assertOk()->assertSee('/map/comet', false)->assertDontSee('/map/dune', false);
        $this->get('/maps?q=run+-frozen')->assertOk()->assertSee('/map/dune', false)->assertDontSee('/map/comet', false);
        $this->get('/maps?tag=water+map')->assertOk()->assertSee('/map/comet', false)->assertDontSee('/map/dune', false);
        $this->get('/maps?play=1v1')->assertOk()->assertSee('/map/comet', false)->assertDontSee('/map/dune', false);
        $this->get('/maps?players=8&size=large')->assertOk()->assertSee('/map/dune', false)->assertDontSee('/map/comet', false);
        $this->get('/maps?sort=uploaded')->assertOk()->assertSee('/map/dune', false)->assertDontSee('/map/comet', false);

        $this->assertSame([], $this->mapRequests('#/rpc/|/rest/v1/(map\?|map_author|map_point)#'));
        $this->assertSame(
            [],
            array_filter($this->mapRequests('#/rest/v1/map_browse#'), fn (Request $r) => ! str_contains(urldecode($r->url()), 'select=map_name,uploaded_at,mirror_urls')),
        );
    }

    public function test_the_author_views_and_a_merge(): void
    {
        $this->supabase();
        $this->map(self::COMET, 'Comet 1.0', 'comet');
        $this->map(self::DUNE, 'Dune 1.0', 'dune');
        $this->credit(self::COMET, [['Beherith', 'beherith'], ['IceXuick', 'icexuick']]);
        $this->credit(self::DUNE, [['[teh]Beherith', 'teh beherith'], ['Beherith', 'beherith']]);
        $admin = Supabase::admin();

        $this->assertSame('refused', $admin->rpc('merge_author_keys', ['p_into' => ' ', 'p_keys' => ['x'], 'p_name' => null, 'p_note' => null]));
        $this->assertSame('merged', $admin->rpc('merge_author_keys', ['p_into' => 'beherith', 'p_keys' => ['teh beherith', 'beherith', ''], 'p_name' => null, 'p_note' => 'same person']));
        $this->assertSame('chained', $admin->rpc('merge_author_keys', ['p_into' => 'teh beherith', 'p_keys' => ['icexuick'], 'p_name' => null, 'p_note' => null]));
        $this->assertSame('beherith', $admin->rpc('resolved_author_key', ['credit_key' => 'teh beherith']));
        $this->assertSame('teh beherith', $admin->rpc('author_key', ['credit' => '  {X} Teh   Beherith.']));

        $counts = Supabase::anon()->from('author_map_count')->select('key, name, maps')->order('maps', false)->order('key')->get();
        $this->assertSame([['key' => 'beherith', 'name' => 'Beherith', 'maps' => 2], ['key' => 'icexuick', 'name' => 'IceXuick', 'maps' => 1]], $counts);
        $spellings = Supabase::anon()->from('author_spelling')->select('raw, credits')->eq('key', 'beherith')->order('raw')->get();
        $this->assertSame([['raw' => '[teh]Beherith', 'credits' => 1], ['raw' => 'Beherith', 'credits' => 2]], $spellings);

        $this->assertSame('merged', $admin->rpc('merge_author_keys', ['p_into' => 'beherith', 'p_keys' => [], 'p_name' => 'The Beherith', 'p_note' => null]));
        $this->assertSame('The Beherith', Supabase::anon()->from('author_map_count')->select('name')->eq('key', 'beherith')->maybeSingle()['name']);
        $this->assertSame([['key' => 'beherith']], Supabase::anon()->from('author_map_count')->select('key')->ilike('key', '*BEH*')->get());
        $this->assertSame([], $this->mapRequests());
    }

    public function test_download_sources_follow_who_may_edit_the_map(): void
    {
        $this->supabase([
            'GET /auth/v1/user' => fn (Request $r) => self::reply(['id' => $r->header('Authorization')[0] === 'Bearer owner' ? self::USER : self::STRANGER]),
        ], moderator: false);
        $this->map(self::COMET, 'Comet 1.0', 'comet');
        $this->credit(self::COMET, [['Beh', 'beh']]);
        DB::table('author_alias')->insert(['from_key' => 'beh', 'to_key' => 'beherith', 'set_at' => '2026-10-01 10:00:00.000000']);
        DB::table('author_owner')->insert(['key' => 'beherith', 'user_id' => self::USER, 'created_at' => '2026-10-01 10:00:00.000000']);
        $owner = Supabase::forToken('owner');
        $stranger = Supabase::forToken('stranger');

        $this->assertTrue($owner->rpc('can_edit_map', ['p_map_id' => self::COMET]));
        $this->assertFalse($stranger->rpc('can_edit_map', ['p_map_id' => self::COMET]));
        $owner->from('map_download_source')->insert([['map_id' => self::COMET, 'kind' => 'url', 'value' => 'https://owner.test/c.sd7', 'filename' => 'c.sd7', 'sort_order' => 0]])->execute();
        try {
            $stranger->from('map_download_source')->insert([['map_id' => self::COMET, 'kind' => 'url', 'value' => 'https://x.test/c.sd7']])->execute();
            $this->fail('A stranger added a source.');
        } catch (SupabaseException $e) {
            $this->assertSame('42501', $e->errorCode);
        }
        try {
            $owner->from('map_download_source')->insert([['map_id' => self::COMET, 'kind' => 'url', 'value' => 'https://x.test/c.sd7', 'asset' => 'a']])->execute();
            $this->fail('An asset on a url source was kept.');
        } catch (SupabaseException $e) {
            $this->assertSame('23514', $e->errorCode);
        }
        // A stranger's delete touches nothing, as row level security does.
        $stranger->from('map_download_source')->delete()->eq('map_id', self::COMET)->execute();
        $this->assertSame(1, DB::table('map_download_source')->count());
        $owner->from('map_download_source')->delete()->eq('map_id', self::COMET)->execute();
        $this->assertSame(0, DB::table('map_download_source')->count());
        // Anon may not read the owners or the conflicts at all.
        foreach (['author_owner', 'map_source_conflict'] as $table) {
            try {
                Supabase::anon()->from($table)->select('*')->get();
                $this->fail('Anon read '.$table);
            } catch (SupabaseException $e) {
                $this->assertSame('42501', $e->errorCode);
            }
        }
    }

    public function test_ownership_requests(): void
    {
        $this->supabase([
            'GET /auth/v1/user' => self::reply(['id' => self::USER]),
            'POST /rest/v1/rpc/current_author_name' => self::reply('Requester'),
        ], moderator: false);
        $this->map(self::COMET, 'Comet 1.0', 'comet');
        $user = Supabase::forToken('tok');

        $user->from('map_ownership_request')->insert([['requested_by' => self::USER, 'note' => 'mine', 'map_id' => self::COMET]])->execute();
        $this->assertSame(['Requester', 'open'], [DB::table('map_ownership_request')->value('requested_by_name'), DB::table('map_ownership_request')->value('state')]);
        try {
            $user->from('map_ownership_request')->insert([['requested_by' => self::USER, 'map_id' => self::COMET]])->execute();
            $this->fail('Two open requests for one map.');
        } catch (SupabaseException $e) {
            $this->assertSame('23505', $e->errorCode);
        }
        $user->from('map_ownership_request')->insert([['requested_by' => self::USER, 'author_key' => 'beherith']])->execute();
        try {
            $user->from('map_ownership_request')->insert([['requested_by' => self::USER, 'author_key' => 'x', 'map_id' => self::COMET]])->execute();
            $this->fail('A request naming a map and an author.');
        } catch (SupabaseException $e) {
            $this->assertSame('23514', $e->errorCode);
        }
        $this->assertCount(2, $user->from('map_ownership_request')->select('id')->get());

        // The service role reads them and decides, and the embed of the map works.
        $admin = Supabase::admin();
        $request = $admin->from('map_ownership_request')->select('map_id,state,map(slug)')->not('map_id', 'is', 'null')->maybeSingle();
        $this->assertSame(['map_id' => self::COMET, 'state' => 'open', 'map' => ['slug' => 'comet']], $request);
        // Production grants the service role no update on `author_owner`, so an upsert there is refused as it is in Postgres.
        try {
            $admin->from('author_owner')->upsert([['key' => 'beherith', 'user_id' => self::USER]], 'key')->execute();
            $this->fail('An upsert without the update grant.');
        } catch (SupabaseException $e) {
            $this->assertSame('42501', $e->errorCode);
        }
    }

    public function test_packs_and_clearing_a_map(): void
    {
        $this->supabase([
            'POST /rest/v1/rpc/clear_map_facts' => self::reply(true),
        ]);
        $this->map(self::COMET, 'Comet 1.0', 'comet', ['archive_filename' => 'comet_v1.sd7']);
        $this->map(self::DUNE, 'Dune 1.0', 'dune');
        $pack = '00000000-0000-4000-8000-0000000000bb';
        DB::table('map_pack')->insert(['id' => $pack, 'title' => 'Classics', 'created_at' => '2026-10-01 10:00:00.000000']);
        DB::table('map_pack_map')->insert(['pack_id' => $pack, 'map_name' => 'Dune 1.0', 'position' => 0]);
        $admin = Supabase::admin();

        $added = $admin->rpc('add_maps_to_pack', ['p_pack_id' => $pack, 'p_names' => ['COMET_V1.SD7', ' ', 'dune 1.0', 'nothing']]);
        $this->assertSame([
            ['wanted' => 'COMET_V1.SD7', 'map_name' => 'Comet 1.0'], ['wanted' => 'dune 1.0', 'map_name' => 'Dune 1.0'], ['wanted' => 'nothing', 'map_name' => null],
        ], $added);
        $entries = Supabase::anon()->from('map_pack_entry')->select('map_name, slug, position')->eq('pack_id', $pack)->order('position')->get();
        $this->assertSame([['map_name' => 'Dune 1.0', 'slug' => 'dune', 'position' => 0], ['map_name' => 'Comet 1.0', 'slug' => 'comet', 'position' => 1]], $entries);
        $admin->from('map_pack_map')->delete()->eq('pack_id', $pack)->eq('map_name', 'Dune 1.0')->execute();
        $this->assertSame(['Comet 1.0'], DB::table('map_pack_map')->pluck('map_name')->all());

        $this->assertFalse($admin->rpc('clear_map_facts', ['p_map_id' => self::COMET]));
        DB::table('map_source_conflict')->insert(['map_id' => self::COMET, 'source_archive' => 'a', 'held_source_hash' => 'h', 'reported_source_hash' => 'x', 'at' => '2026-10-01 10:00:00.000000']);
        $this->assertTrue($admin->rpc('clear_map_facts', ['p_map_id' => self::COMET]));
        $this->assertFalse(DB::table('map')->where('id', self::COMET)->exists());
        $cleared = $this->mapRequests('#/rpc/clear_map_facts#');
        $this->assertCount(1, $cleared);
        $this->assertSame(self::COMET, $cleared[0]['p_map_id']);
    }

    public function test_the_moderation_pages_read_and_write_here(): void
    {
        $this->supabase([
            'GET /rest/v1/asset_licence' => self::reply([['all_maps' => true, 'map_name' => null, 'redistribute_extracted' => 'allowed', 'redistribute_rendered' => 'allowed']]),
            'POST /rest/v1/map?' => self::reply([], 201),
        ]);
        $this->map(self::COMET, 'Comet 1.0', 'comet', ['display_name' => 'Comet', 'featured_at' => '2026-10-02 10:00:00.000000', 'curated_tags' => '["classic"]']);
        $this->credit(self::COMET, [['Beherith', 'beherith']]);
        $pack = '00000000-0000-4000-8000-0000000000bb';
        DB::table('map_pack')->insert(['id' => $pack, 'title' => 'Classics', 'created_at' => '2026-10-01 10:00:00.000000']);
        DB::table('map_pack_map')->insert(['pack_id' => $pack, 'map_name' => 'Comet 1.0', 'position' => 0]);

        $this->signedIn()->get('/moderation/maps')->assertOk()->assertSee('/moderation/maps/comet', false);
        $this->signedIn()->get('/moderation/maps/comet')->assertOk()->assertSee('classic');
        $this->signedIn()->get('/moderation/map-packs')->assertOk()->assertSee('Classics');
        $this->signedIn()->get('/moderation/map-packs/'.$pack)->assertOk()->assertSee('/moderation/maps/comet', false);
        $this->signedIn()->get('/moderation/authors')->assertOk()->assertSee('Beherith');

        $this->signedIn()->post('/moderation/maps/tags', ['map' => self::COMET, 'slug' => 'comet', 'tags' => 'classic, ffa'])->assertRedirect();
        $this->assertSame('["classic","ffa"]', DB::table('map')->where('id', self::COMET)->value('curated_tags'));
        $this->signedIn()->post('/moderation/maps/feature', ['id' => self::COMET, 'slug' => 'comet', 'featured' => 'false'])->assertRedirect();
        $this->assertNull(DB::table('map')->where('id', self::COMET)->value('featured_at'));
        // Each change to the map row went to Supabase's copy too.
        $this->assertCount(2, $this->mapRequests('#/rest/v1/map\?#'));
        $this->assertSame([], $this->mapRequests('#/rest/v1/(map_|author_)|/rpc/(?!is_moderator|capability_access)#'));
    }

    public function test_writes_during_the_copy_are_refused_and_reads_still_answer(): void
    {
        config(['hub.maps_store' => MapStore::FROZEN]);
        $this->supabase(['GET /rest/v1/map?' => self::reply([['slug' => 'comet']])]);

        try {
            Supabase::admin()->rpc('merge_author_keys', ['p_into' => 'a', 'p_keys' => ['b'], 'p_name' => null, 'p_note' => null]);
            $this->fail('A merge during the copy.');
        } catch (SupabaseException $e) {
            $this->assertSame([503, 'HUB_FROZEN'], [$e->status, $e->errorCode]);
        }
        $this->assertSame([['slug' => 'comet']], Supabase::anon()->from('map')->select('slug')->get());
    }
}
