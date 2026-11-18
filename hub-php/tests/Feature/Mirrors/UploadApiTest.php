<?php

namespace Tests\Feature\Mirrors;

use App\Domain\Mirrors\B64;
use App\Domain\Mirrors\Signing;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Mirrors\MirrorFixtures;

/** `POST /api/v1/uploads` and `GET /api/v1/uploads/{id}`, against a faked Supabase. */
class UploadApiTest extends TestCase
{
    use MirrorFixtures;

    private const USER = '11111111-1111-1111-1111-111111111111';

    private const ID = '22222222-2222-2222-2222-222222222222';

    private const AUTH = ['HTTP_AUTHORIZATION' => 'Bearer tok', 'CONTENT_TYPE' => 'application/json'];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.supabase.url' => 'https://sb.test',
            'hub.supabase.publishable_key' => 'pk',
            'hub.supabase.service_role_key' => 'sk',
            'hub.mirrors.secret_key' => self::fixtureKeys()['hub_secret_key'],
            'hub.mirrors.next_secret_key' => '',
            'hub.mirrors.token_window' => 300,
            'hub.mirrors.reservation' => 86400,
            'app.url' => 'https://hub.test',
        ]);
    }

    /** @return array<string, mixed> */
    private function facts(array $overrides = []): array
    {
        return $overrides + [
            'sha256' => str_repeat('a', 64),
            'size' => 43,
            'filename' => 'New Map (v1.0) é.sd7',
            'kind' => 'map',
            'internal_name' => 'New Map 1.0',
            'version' => '1.0',
            'shortname' => null,
            'author' => 'someone',
            'description' => null,
        ];
    }

    private function created(): array
    {
        return ['outcome' => 'created', 'id' => self::ID, 'mirror_id' => 'jj-darkstars', 'base_url' => 'https://jj.test', 'reserved_until' => 1794877200];
    }

    private function fakeSignedIn(array $more = []): void
    {
        Http::fake($more + [
            'https://sb.test/auth/v1/user' => Http::response(['id' => self::USER]),
            'https://sb.test/rest/v1/rpc/is_moderator' => Http::response('false'),
        ]);
    }

    private function send(array $facts): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/api/v1/uploads', [], [], [], self::AUTH, json_encode($facts));
    }

    private function sentTo(string $path): bool
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), $path))->isNotEmpty();
    }

    public function test_created_upload_returns_a_token_the_fixture_hub_key_verifies(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1794790000));
        $this->fakeSignedIn(['https://sb.test/rest/v1/rpc/begin_archive_upload' => Http::response($this->created())]);

        $response = $this->send($this->facts());

        $response->assertStatus(201)
            ->assertJsonPath('upload.id', self::ID)
            ->assertJsonPath('upload.state', 'uploading')
            ->assertJsonPath('upload.mirror', 'jj-darkstars')
            ->assertJsonPath('upload.upload_url', 'https://jj.test/upload.php')
            ->assertJsonPath('upload.limits_url', 'https://jj.test/limits.php')
            ->assertJsonPath('upload.start_by', 1794790300)
            ->assertJsonPath('upload.reserved_until', 1794877200);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('Access-Control-Allow-Origin', '*');

        [$payloadB64, $sigB64] = explode('.', $response->json('upload.token'));
        $payloadBytes = B64::decode($payloadB64);
        $this->assertTrue(Signing::verify(Signing::CTX_TOKEN, $payloadBytes, B64::decode($sigB64), B64::decode(self::fixtureKeys()['hub_public_key'])));
        $payload = json_decode($payloadBytes, true);
        $this->assertSame('jj-darkstars', $payload['mirror']);
        $this->assertSame(self::ID, $payload['upload']);
        $this->assertSame(str_repeat('a', 64), $payload['sha256']);
        $this->assertSame(43, $payload['size']);
        $this->assertSame('New Map (v1.0) é.sd7', $payload['filename']);
        $this->assertSame('map', $payload['kind']);
        $this->assertSame(1794790300, $payload['start_by']);

        Carbon::setTestNow();
    }

    public function test_reissued_upload_is_200(): void
    {
        $this->fakeSignedIn(['https://sb.test/rest/v1/rpc/begin_archive_upload' => Http::response(['outcome' => 'reissued'] + $this->created())]);

        $this->send($this->facts())->assertStatus(200)->assertJsonPath('upload.id', self::ID);
    }

    public function test_moderator_flag_is_passed_from_the_callers_own_client(): void
    {
        $this->fakeSignedIn([
            'https://sb.test/rest/v1/rpc/is_moderator' => Http::response('true'),
            'https://sb.test/rest/v1/rpc/begin_archive_upload' => Http::response($this->created()),
        ]);

        $this->send($this->facts())->assertStatus(201);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'rpc/is_moderator')
            && $r->header('Authorization') === ['Bearer tok']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'rpc/begin_archive_upload')
            && $r['p_user'] === self::USER
            && $r['p_is_moderator'] === true
            && $r['p_reservation_seconds'] === 86400
            && $r['p_facts']['sha256'] === str_repeat('a', 64));
    }

    public function test_a_failing_moderator_check_counts_as_not_a_moderator(): void
    {
        $this->fakeSignedIn([
            'https://sb.test/rest/v1/rpc/is_moderator' => Http::response(['message' => 'boom'], 500),
            'https://sb.test/rest/v1/rpc/begin_archive_upload' => Http::response($this->created()),
        ]);

        $this->send($this->facts())->assertStatus(201);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'rpc/begin_archive_upload') && $r['p_is_moderator'] === false);
    }

    public function test_duplicate_is_409_with_the_check_and_a_map_page_url_when_listed(): void
    {
        $this->fakeSignedIn([
            'https://sb.test/rest/v1/rpc/begin_archive_upload' => Http::sequence()
                ->push(['outcome' => 'refused', 'reason' => 'duplicate', 'check' => 'sha256', 'existing' => [
                    'id' => self::ID, 'filename' => 'a.sd7', 'kind' => 'map', 'state' => 'listed', 'internal_name' => 'New Map 1.0', 'shortname' => null,
                ]])
                ->push(['outcome' => 'refused', 'reason' => 'duplicate', 'check' => 'filename', 'existing' => [
                    'id' => self::ID, 'filename' => 'a.sd7', 'kind' => 'map', 'state' => 'uploading', 'internal_name' => 'New Map 1.0', 'shortname' => null,
                ]])
                ->push(['outcome' => 'refused', 'reason' => 'duplicate', 'check' => 'name_version', 'existing' => [
                    'id' => self::ID, 'filename' => 'g.sdz', 'kind' => 'game', 'state' => 'listed', 'internal_name' => 'BA 1', 'shortname' => 'BA',
                ]]),
            'https://sb.test/rest/v1/map*' => Http::response([['slug' => 'new-map-1-0']]),
        ]);

        $this->send($this->facts())->assertStatus(409)
            ->assertJsonPath('reason', 'duplicate')
            ->assertJsonPath('duplicate.check', 'sha256')
            ->assertJsonPath('duplicate.filename', 'a.sd7')
            ->assertJsonPath('duplicate.kind', 'map')
            ->assertJsonPath('duplicate.state', 'listed')
            ->assertJsonPath('duplicate.url', 'https://hub.test/maps/new-map-1-0');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'rest/v1/map?') && str_contains(urldecode($r->url()), 'map_name=eq.New Map 1.0'));

        $this->send($this->facts())->assertStatus(409)
            ->assertJsonPath('duplicate.check', 'filename')
            ->assertJsonPath('duplicate.state', 'uploading')
            ->assertJsonPath('duplicate.url', null);

        $this->send($this->facts())->assertStatus(409)
            ->assertJsonPath('duplicate.url', 'https://hub.test/games/BA');
    }

    public function test_a_listed_duplicate_with_no_map_row_has_a_null_url(): void
    {
        $this->fakeSignedIn([
            'https://sb.test/rest/v1/rpc/begin_archive_upload' => Http::response(['outcome' => 'refused', 'reason' => 'duplicate', 'check' => 'sha256', 'existing' => [
                'id' => self::ID, 'filename' => 'a.sd7', 'kind' => 'map', 'state' => 'listed', 'internal_name' => 'Gone', 'shortname' => null,
            ]]),
            'https://sb.test/rest/v1/map*' => Http::response([]),
        ]);

        $this->send($this->facts())->assertStatus(409)->assertJsonPath('duplicate.url', null);
    }

    public function test_not_owner_403_unknown_game_422_no_mirror_503(): void
    {
        $this->fakeSignedIn([
            'https://sb.test/rest/v1/rpc/begin_archive_upload' => Http::sequence()
                ->push(['outcome' => 'refused', 'reason' => 'not_owner'])
                ->push(['outcome' => 'refused', 'reason' => 'unknown_game'])
                ->push(['outcome' => 'refused', 'reason' => 'no_mirror']),
        ]);
        $game = $this->facts(['kind' => 'game', 'shortname' => 'BA']);

        $this->send($game)->assertStatus(403)->assertJsonPath('reason', 'not_owner')
            ->assertJsonPath('error', 'You do not own the game "BA".');
        $this->send($game)->assertStatus(422)->assertJsonPath('reason', 'unknown_game')
            ->assertJsonPath('error', 'The hub does not know a game called "BA".');
        $this->send($this->facts())->assertStatus(503)->assertJsonPath('reason', 'no_mirror');
    }

    public function test_unknown_map_is_422(): void
    {
        $this->fakeSignedIn([
            'https://sb.test/rest/v1/rpc/begin_archive_upload' => Http::response(['outcome' => 'refused', 'reason' => 'unknown_map']),
        ]);
        $facts = $this->facts();

        $this->send($facts)->assertStatus(422)->assertJsonPath('reason', 'unknown_map')
            ->assertJsonPath('error', 'The hub has no facts for the map "'.$facts['internal_name'].'". Send the map to the hub first.');
    }

    public function test_invalid_facts_are_400_and_nothing_is_reserved(): void
    {
        $this->fakeSignedIn();

        $this->send($this->facts(['sha256' => 'nope']))->assertStatus(400)->assertJsonPath('reason', 'bad_request');
        $this->call('POST', '/api/v1/uploads', [], [], [], self::AUTH, 'not json')->assertStatus(400);

        $this->assertFalse($this->sentTo('rpc/begin_archive_upload'));
    }

    public function test_an_oversized_body_is_413(): void
    {
        $this->fakeSignedIn();

        $this->send($this->facts(['description' => str_repeat('x', 17000)]))->assertStatus(413);

        $this->assertFalse($this->sentTo('rpc/begin_archive_upload'));
    }

    public function test_no_bearer_is_401_and_nothing_called(): void
    {
        Http::fake();

        $this->postJson('/api/v1/uploads', $this->facts())->assertStatus(401);
        $this->getJson('/api/v1/uploads/'.self::ID)->assertStatus(401);

        Http::assertNothingSent();
    }

    public function test_missing_hub_key_is_503_before_any_rpc(): void
    {
        config(['hub.mirrors.secret_key' => '']);
        $this->fakeSignedIn();

        $this->send($this->facts())->assertStatus(503)->assertJsonPath('reason', 'not_configured');

        $this->assertFalse($this->sentTo('rpc/'));
    }

    public function test_the_database_failing_is_503(): void
    {
        $this->fakeSignedIn(['https://sb.test/rest/v1/rpc/begin_archive_upload' => Http::response(['message' => 'down'], 500)]);

        $this->send($this->facts())->assertStatus(503);
    }

    public function test_status_of_someone_elses_upload_is_404(): void
    {
        $this->fakeSignedIn(['https://sb.test/rest/v1/rpc/archive_upload_status' => Http::response('null')]);

        $this->getJson('/api/v1/uploads/'.self::ID, ['Authorization' => 'Bearer tok'])->assertStatus(404);
    }

    public function test_a_malformed_upload_id_is_404_without_asking_the_database(): void
    {
        $this->fakeSignedIn();

        $this->getJson('/api/v1/uploads/not-a-uuid', ['Authorization' => 'Bearer tok'])->assertStatus(404);

        $this->assertFalse($this->sentTo('rpc/archive_upload_status'));
    }

    public function test_status_returns_state_and_is_not_cached(): void
    {
        $this->fakeSignedIn(['https://sb.test/rest/v1/rpc/archive_upload_status' => Http::response([
            'id' => self::ID, 'state' => 'listed', 'filename' => 'a.sd7', 'kind' => 'map', 'reserved_until' => null,
        ])]);

        $response = $this->getJson('/api/v1/uploads/'.self::ID, ['Authorization' => 'Bearer tok']);

        $response->assertOk()->assertExactJson(['upload' => [
            'id' => self::ID, 'state' => 'listed', 'filename' => 'a.sd7', 'kind' => 'map', 'reserved_until' => null,
        ]]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'rpc/archive_upload_status')
            && $r['p_user'] === self::USER && $r['p_id'] === self::ID && $r['p_reservation_seconds'] === 86400);
    }
}
