<?php

namespace Tests\Feature\Assets;

use App\Domain\Assets\Upload;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Assets\ImageAndCapsTest;

/**
 * The asset routes end to end against a faked Supabase. Ported from
 * `app/api/v1/assets/upload/route.test.ts`, `app/assets/staged/[...path]/route.test.ts`
 * and `app/assets/games/[shortname]/[kind]/[hash]/route.test.ts`.
 */
class AssetRoutesTest extends TestCase
{
    private const USER = '11111111-1111-1111-1111-111111111111';

    /** @var list<Request> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.supabase.url' => 'https://sb.test',
            'hub.supabase.publishable_key' => 'sb_publishable_test',
            'hub.supabase.service_role_key' => 'sb_secret_test',
            'hub.asset_cdn_base' => null,
            'app.url' => 'https://hub.test',
        ]);
    }

    /**
     * Supabase as the upload sees it. `$routes` maps a URL fragment to a
     * response, checked in order, and anything unmatched is a 500 so a request
     * nobody expected fails loudly.
     *
     * @param  array<string, mixed>  $routes
     */
    private function supabase(array $routes): void
    {
        Http::swap(new Factory);
        Http::fake(function (Request $request) use ($routes) {
            $this->sent[] = $request;
            $url = urldecode($request->url());
            foreach ($routes as $fragment => $response) {
                [$method, $needle] = str_contains($fragment, ' ') ? explode(' ', $fragment, 2) : [null, $fragment];
                if (($method === null || $method === $request->method()) && str_contains($url, $needle)) {
                    return is_callable($response) ? $response($request) : $response;
                }
            }

            return Http::response(['message' => 'unexpected '.$request->method().' '.$url], 500);
        });
    }

    private static function reply(mixed $value): mixed
    {
        return Http::response(json_encode($value), 200, ['Content-Type' => 'application/json']);
    }

    private static function counted(int $n): mixed
    {
        return Http::response($n ? [['id' => 'x']] : [], 200, ['Content-Range' => ($n ? '0-0' : '*').'/'.$n]);
    }

    /** A signed in uploader with no capabilities, an empty identity and room in every quota. */
    private function world(array $overrides = []): array
    {
        return [
            'GET /auth/v1/user' => self::reply(['id' => self::USER]),
            'POST /rpc/has_capability' => fn (Request $r) => self::reply(in_array($r->data()['capability'], $overrides['capabilities'] ?? [], true)),
            'POST /rpc/account_asset_bytes' => self::reply(0),
            'GET /rest/v1/asset?select=id,source_hash' => self::reply(isset($overrides['existing']) ? [$overrides['existing']] : []),
            'GET /rest/v1/asset?select=id&uploaded_by' => self::counted($overrides['recent'] ?? 0),
            'GET /rest/v1/asset?select=id' => self::counted(0),
            'POST /storage/v1/object/staged-pictures/' => $overrides['storage'] ?? self::reply(['Key' => 'k']),
            'PATCH /rest/v1/asset' => Http::response('', 204),
            'POST /rest/v1/asset_upload_ip' => Http::response('', 201),
            'POST /rest/v1/asset_source_conflict' => Http::response('', 201),
            'POST /rest/v1/asset?' => $overrides['insert'] ?? Http::response([['id' => 'new-row']], 201),
        ];
    }

    private function declaration(array $over = []): string
    {
        return json_encode([...[
            'keyed_on' => 'unit', 'game' => 'BYAR', 'unit_name' => 'armsolar', 'variant' => 'buildpic',
            'source_hash' => 'raw-abc', 'encode_profile' => 'webp-lossless-256', 'origin' => 'extracted',
            'mime' => 'image/webp', 'bytes' => 40, 'source_archive' => 'byar_1.2.sdz',
        ], ...$over]);
    }

    private function upload(?string $declaration = null, ?string $bytes = null, array $headers = [])
    {
        $bytes ??= ImageAndCapsTest::webp(128, 128, true);
        $file = UploadedFile::fake()->createWithContent('buildpic.webp', $bytes);

        return $this->withHeaders(['Authorization' => 'Bearer token', ...$headers])
            ->post('/api/v1/assets/upload', ['asset' => $declaration ?? $this->declaration(['bytes' => strlen($bytes)]), 'file' => $file]);
    }

    public function test_an_upload_is_stored_written_and_answered_pending_with_no_path(): void
    {
        $this->supabase($this->world());
        $bytes = ImageAndCapsTest::webp(128, 128, true);
        $hash = hash('sha256', $bytes);

        $response = $this->upload(null, $bytes, ['X-Real-IP' => '203.0.113.7']);

        $response->assertStatus(201)
            ->assertExactJson(['format' => 'coilbox-hub-asset-upload', 'version' => 1, 'moderation' => 'pending'])
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Access-Control-Allow-Origin', '*');

        $storage = collect($this->sent)->first(fn (Request $r) => str_contains($r->url(), '/storage/'));
        $this->assertSame('https://sb.test/storage/v1/object/staged-pictures/units/BYAR/buildpic/'.$hash.'.webp', $storage->url());
        $this->assertSame($bytes, $storage->body());
        $this->assertSame('false', $storage->header('x-upsert')[0]);

        $insert = collect($this->sent)->first(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/rest/v1/asset?'));
        $this->assertSame(['pending', null, 128, 128, $hash], [$insert->data()['moderation'], $insert->data()['approval_source'], $insert->data()['width'], $insert->data()['height'], $insert->data()['hash']]);

        $ip = collect($this->sent)->first(fn (Request $r) => str_contains($r->url(), 'asset_upload_ip'));
        $this->assertSame(['asset_id' => 'new-row', 'ip' => '127.0.0.1'], $ip->data());
    }

    public function test_bytes_already_in_the_bucket_are_reused(): void
    {
        $this->supabase($this->world(['storage' => Http::response(['statusCode' => '409', 'error' => 'Duplicate', 'message' => 'The resource already exists'], 400)]));
        $this->upload()->assertStatus(201);
    }

    public function test_a_store_that_refuses_is_a_502_and_nothing_is_written(): void
    {
        $this->supabase($this->world(['storage' => Http::response(['message' => 'down'], 500)]));
        $this->upload()->assertStatus(502)->assertExactJson(['error' => 'The asset store would not accept that upload just now.']);
        $this->assertEmpty(collect($this->sent)->filter(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/rest/v1/asset?')));
    }

    public function test_a_row_that_cannot_be_written_is_a_503(): void
    {
        $this->supabase($this->world(['insert' => Http::response(['message' => 'x'], 400)]));
        $this->upload()->assertStatus(503)->assertExactJson(['error' => 'The asset was uploaded but its record could not be written.']);
    }

    public function test_an_uploader_who_skips_the_queue_publishes_as_a_bypass_and_records_no_address(): void
    {
        $this->supabase($this->world(['capabilities' => ['can_moderate']]));
        $this->upload(null, null, ['X-Real-IP' => '203.0.113.7'])->assertStatus(201)->assertJson(['moderation' => 'approved']);

        $insert = collect($this->sent)->first(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/rest/v1/asset?'));
        $this->assertSame(['approved', 'bypass'], [$insert->data()['moderation'], $insert->data()['approval_source']]);
        $this->assertEmpty(collect($this->sent)->filter(fn (Request $r) => str_contains($r->url(), 'asset_upload_ip')));
    }

    public function test_a_holder_replaces_another_accounts_picture_and_it_goes_back_to_the_queue_as_theirs(): void
    {
        $existing = ['id' => 'held', 'source_hash' => 'raw-old', 'source_archive' => 'byar_1.1.sdz', 'uploaded_by' => 'someone', 'moderation' => 'approved', 'bytes' => 40, 'bytes_missing_at' => null];
        $this->supabase($this->world(['existing' => $existing, 'capabilities' => ['can_replace_any_asset']]));

        $this->upload()->assertStatus(200)->assertJson(['moderation' => 'pending']);

        $patch = collect($this->sent)->first(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertSame([self::USER, 'pending'], [$patch->data()['uploaded_by'], $patch->data()['moderation']]);
    }

    public function test_an_account_without_the_capability_cannot_and_nothing_is_stored(): void
    {
        $existing = ['id' => 'held', 'source_hash' => 'raw-old', 'source_archive' => 'byar_1.2.sdz', 'uploaded_by' => 'someone', 'moderation' => 'approved', 'bytes' => 40, 'bytes_missing_at' => null];
        $this->supabase($this->world(['existing' => $existing, 'capabilities' => ['can_moderate']]));

        $this->upload()->assertStatus(409)->assertExactJson(['error' => 'Another account uploaded the asset with that identity, so it cannot be replaced.']);
        $this->assertEmpty(collect($this->sent)->filter(fn (Request $r) => str_contains($r->url(), '/storage/')));

        // The same archive with different bytes, so the disagreement is still recorded.
        $conflict = collect($this->sent)->first(fn (Request $r) => str_contains($r->url(), 'asset_source_conflict'));
        $this->assertSame('raw-abc', $conflict->data()['reported_source_hash']);
    }

    public function test_a_holder_over_the_hourly_limit_is_turned_away_before_anything_is_stored(): void
    {
        $this->supabase($this->world(['recent' => Upload::SUBJECT_UPLOADS_PER_HOUR]));
        $this->upload()->assertStatus(429);
        $this->assertEmpty(collect($this->sent)->filter(fn (Request $r) => str_contains($r->url(), '/storage/')));
    }

    public function test_the_refusals_before_the_database(): void
    {
        $this->supabase($this->world());

        $this->post('/api/v1/assets/upload')->assertStatus(401)->assertExactJson(['error' => 'Send an access token as "Authorization: Bearer <token>".']);

        $this->withHeaders(['Authorization' => 'Bearer token'])->postJson('/api/v1/assets/upload', ['asset' => 'x'])
            ->assertStatus(415)->assertExactJson(['error' => 'The request body must be multipart/form-data with an "asset" JSON part and a "file" part.']);

        $this->withHeaders(['Authorization' => 'Bearer token'])->post('/api/v1/assets/upload', ['asset' => $this->declaration()])
            ->assertStatus(400)->assertExactJson(['error' => 'The "file" part is required and must carry the encoded bytes.']);

        $file = UploadedFile::fake()->createWithContent('b.webp', 'x');
        $this->withHeaders(['Authorization' => 'Bearer token'])->post('/api/v1/assets/upload', ['file' => $file])
            ->assertStatus(400)->assertExactJson(['error' => 'The "asset" part is required and must be the declaration as JSON.']);

        $this->upload('{not json')->assertStatus(400)->assertExactJson(['error' => 'The "asset" part must be JSON.']);
        $this->upload($this->declaration(['width' => 128]))->assertStatus(400)->assertExactJson(['error' => 'Unknown field: width']);
        $this->upload($this->declaration(['bytes' => 41]))->assertStatus(400)->assertExactJson(['error' => 'The "file" part is 40 bytes and the declaration says 41.']);
        $this->upload(null, ImageAndCapsTest::webp(128, 128))->assertStatus(400)->assertExactJson(['error' => 'A "buildpic" must be losslessly encoded, and that one is not.']);

        $this->assertEmpty(collect($this->sent)->filter(fn (Request $r) => ! str_contains($r->url(), '/auth/v1/user')));
    }

    public function test_preflights(): void
    {
        foreach (['/api/v1/auth', '/api/v1/assets/pictures', '/api/v1/assets/have', '/api/v1/assets/upload'] as $path) {
            $this->call('OPTIONS', $path)->assertStatus(204)
                ->assertHeader('Access-Control-Allow-Origin', '*')
                ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
                ->assertHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type');
        }
    }

    public function test_auth_discovery(): void
    {
        $this->getJson('/api/v1/auth')->assertOk()
            ->assertHeader('Cache-Control', 'max-age=86400, public, s-maxage=86400')
            ->assertJsonPath('format', 'coilbox-hub-auth')
            ->assertJsonPath('supabase_url', 'https://sb.test')
            ->assertJsonPath('publishable_key', 'sb_publishable_test')
            ->assertJsonPath('providers', ['discord', 'github', 'twitch'])
            ->assertJsonPath('email_password', false);

        config(['hub.supabase.url' => '']);
        $this->getJson('/api/v1/auth')->assertStatus(503)->assertExactJson(['error' => 'This deployment has not configured Supabase sign-in.']);
    }

    public function test_the_have_route(): void
    {
        $this->supabase([
            'GET /auth/v1/user' => self::reply(['id' => self::USER]),
            'GET /rest/v1/asset' => self::reply([
                ['game' => 'bar', 'unit_name' => 'armsolar', 'map_name' => null, 'faction_key' => null, 'variant' => 'buildpic', 'source_hash' => 'raw-abc', 'moderation' => 'pending', 'bytes_missing_at' => null],
            ]),
        ]);
        $this->withHeaders(['Authorization' => 'Bearer token'])->postJson('/api/v1/assets/have', ['keys' => [
            ['keyed_on' => 'unit', 'game' => 'bar', 'unit_name' => 'armsolar', 'variant' => 'buildpic', 'source_hash' => 'raw-abc'],
            ['keyed_on' => 'unit', 'game' => 'bar', 'unit_name' => 'other', 'variant' => 'buildpic', 'source_hash' => 'raw-abc'],
        ]])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson([
            'format' => 'coilbox-hub-asset-have', 'version' => 1, 'results' => [
                ['keyed_on' => 'unit', 'game' => 'bar', 'unit_name' => 'armsolar', 'variant' => 'buildpic', 'status' => 'have'],
                ['keyed_on' => 'unit', 'game' => 'bar', 'unit_name' => 'other', 'variant' => 'buildpic', 'status' => 'missing'],
            ],
        ]);
        // Read with the secret key, since pending rows count.
        $read = collect($this->sent)->first(fn (Request $r) => str_contains($r->url(), '/rest/v1/asset'));
        $this->assertSame('sb_secret_test', $read->header('apikey')[0]);

        $this->supabase(['GET /auth/v1/user' => self::reply(['id' => self::USER]), 'GET /rest/v1/asset' => Http::response(['message' => 'down'], 500)]);
        $this->withHeaders(['Authorization' => 'Bearer token'])->postJson('/api/v1/assets/have', ['keys' => [
            ['keyed_on' => 'unit', 'game' => 'bar', 'unit_name' => 'armsolar', 'variant' => 'buildpic', 'source_hash' => 'raw-abc'],
        ]])->assertStatus(503)->assertExactJson(['error' => 'The asset index could not be read just now.']);
    }

    public function test_the_pictures_route_reads_as_anon_and_a_failure_is_no_picture(): void
    {
        $this->supabase(['GET /rest/v1/asset' => Http::response(['message' => 'down'], 500)]);
        $this->postJson('/api/v1/assets/pictures', ['keys' => [['keyed_on' => 'map', 'map_name' => 'Comet', 'variant' => 'minimap']]])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['format' => 'coilbox-hub-asset-pictures', 'version' => 1, 'results' => [
                ['keyed_on' => 'map', 'map_name' => 'Comet', 'variant' => 'minimap', 'picture' => null],
            ]]);
        $this->assertSame('sb_publishable_test', $this->sent[0]->header('apikey')[0]);
    }

    // The staged picture route.

    public function test_an_approved_staged_picture_is_served_as_an_image_cached_for_good(): void
    {
        $this->supabase([
            'GET /rest/v1/asset' => self::reply([['mime' => 'image/webp', 'tier' => 'bucket']]),
            'GET /storage/v1/object/staged-pictures/units/bar/buildpic/abc.webp' => Http::response('bytes', 200),
        ]);

        $response = $this->get('/assets/staged/units/bar/buildpic/abc.webp');
        $response->assertOk();
        $this->assertSame('bytes', $response->getContent());
        $response->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeaderMissing('Set-Cookie');

        $read = $this->sent[0];
        $this->assertStringContainsString('path=eq.units%2Fbar%2Fbuildpic%2Fabc.webp', $read->url());
        $this->assertSame('sb_publishable_test', $read->header('apikey')[0]);
    }

    public function test_a_staged_row_with_an_unknown_type_is_octet_stream(): void
    {
        $this->supabase([
            'GET /rest/v1/asset' => self::reply([['mime' => 'text/html', 'tier' => 'bucket']]),
            'GET /storage/' => Http::response('<b>', 200),
        ]);
        $this->get('/assets/staged/a/b.webp')->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
    }

    public function test_a_promoted_picture_is_a_permanent_cached_redirect(): void
    {
        $this->supabase(['GET /rest/v1/asset' => self::reply([['mime' => 'image/webp', 'tier' => 'static']])]);
        $this->get('/assets/staged/units/bar/buildpic/abc.webp')->assertStatus(308)
            ->assertHeader('Location', 'https://tomjn.github.io/coilbox-assets/units/bar/buildpic/abc.webp')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
            ->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertCount(1, $this->sent);
    }

    public function test_every_staged_refusal_is_an_empty_uncached_404_and_a_failure_a_502(): void
    {
        $this->supabase(['GET /rest/v1/asset' => self::reply([])]);
        $response = $this->get('/assets/staged/units/bar/buildpic/abc.webp');
        $response->assertStatus(404)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('', $response->getContent());

        $this->supabase(['GET /rest/v1/asset' => self::reply([['mime' => 'image/webp', 'tier' => 'bucket']]), 'GET /storage/' => Http::response(['error' => 'not_found'], 400)]);
        $this->get('/assets/staged/x.webp')->assertStatus(404);

        $this->supabase(['GET /rest/v1/asset' => Http::response(['message' => 'down'], 500)]);
        $response = $this->get('/assets/staged/x.webp');
        $response->assertStatus(502)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('', $response->getContent());
    }

    // The game art route.

    public function test_staged_game_art_is_served_only_under_its_own_hash(): void
    {
        $bytes = 'logo bytes';
        $hash = hash('sha256', $bytes);
        $this->supabase([
            'GET /rest/v1/game' => self::reply([['logo_path' => 'games/BA/logo.png', 'logo_staged_tier' => 'bucket']]),
            'GET /storage/v1/object/staged-pictures/games/BA/logo.png' => Http::response($bytes, 200),
        ]);

        $response = $this->get('/assets/games/BA/logo/'.$hash);
        $response->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        $this->assertSame($bytes, $response->getContent());
        $this->assertStringContainsString('logo_hash=eq.'.$hash, urldecode($this->sent[0]->url()));

        // The bucket already holds newer bytes than the row names.
        $this->get('/assets/games/BA/logo/'.hash('sha256', 'older'))->assertStatus(404);
    }

    public function test_promoted_game_art_is_a_temporary_uncached_redirect(): void
    {
        $this->supabase(['GET /rest/v1/game' => self::reply([['card_path' => 'games/ZK/card.webp', 'card_staged_tier' => null]])]);
        $this->get('/assets/games/ZK/card/'.str_repeat('a', 64))->assertStatus(307)
            ->assertHeader('Location', 'https://tomjn.github.io/coilbox-assets/games/ZK/card.webp')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_a_bad_kind_or_hash_is_refused_without_a_read(): void
    {
        $this->supabase([]);
        $this->get('/assets/games/BA/icon/'.str_repeat('a', 64))->assertStatus(404);
        $this->get('/assets/games/BA/logo/'.str_repeat('A', 64))->assertStatus(404);
        $this->get('/assets/games/BA/logo/abc')->assertStatus(404);
        $this->assertSame([], $this->sent);

        $this->supabase(['GET /rest/v1/game' => Http::response(['message' => 'down'], 500)]);
        $this->get('/assets/games/BA/banner/'.str_repeat('a', 64))->assertStatus(502);
    }
}
