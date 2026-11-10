<?php

namespace Tests\Feature\Games;

use App\Domain\Games\GameFacts;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** `GET /api/v1/games` and `POST /api/v1/games/facts`, against a faked Supabase. */
class GameApiTest extends TestCase
{
    private const USER = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.supabase.url' => 'https://sb.test',
            'hub.supabase.publishable_key' => 'pk',
            'hub.supabase.service_role_key' => 'sk',
            'cache.default' => 'array',
        ]);
    }

    private function facts(array $overrides = []): array
    {
        return $overrides + ['format' => GameFacts::FORMAT, 'version' => 1, 'shortname' => 'BA', 'release' => '1.9.0', 'units' => [['name' => 'armcom']]];
    }

    private function fakeSignedIn(array $more = []): void
    {
        Http::fake($more + [
            'https://sb.test/auth/v1/user' => Http::response(['id' => self::USER]),
            'https://sb.test/rest/v1/rpc/submit_game_facts' => Http::response([['kind' => 'unit', 'name' => 'armcom', 'outcome' => 'accepted', 'said' => null]]),
            'https://sb.test/rest/v1/rpc/offer_game_download_sources' => Http::response('1'),
        ]);
    }

    public function test_the_listing_answers_every_game_featured_first(): void
    {
        Http::fake(['https://sb.test/rest/v1/game_browse*' => Http::response([
            ['shortname' => 'ZZ', 'display_name' => null, 'description' => null, 'featured_at' => null, 'downloads' => [], 'faction_count' => 1, 'unit_count' => 2, 'item_count' => 0, 'base_game' => null],
            ['shortname' => 'AA', 'display_name' => 'Zed', 'description' => 'd', 'featured_at' => '2026-01-01T00:00:00Z', 'downloads' => [['kind' => 'rapid', 'value' => 'a:b']], 'faction_count' => 0, 'unit_count' => 0, 'item_count' => 3, 'base_game' => null, 'logo_path' => 'games/AA/logo.png', 'logo_hash' => 'h', 'logo_staged_tier' => 'bucket'],
        ])]);

        $response = $this->getJson('/api/v1/games');

        $response->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertJsonPath('format', 'coilbox-hub-games')
            ->assertJsonPath('games.0.shortname', 'AA')
            ->assertJsonPath('games.0.featured', true)
            ->assertJsonPath('games.0.logo', '/assets/games/AA/logo/h')
            ->assertJsonPath('games.0.downloads', [['kind' => 'rapid', 'value' => 'a:b']])
            ->assertJsonPath('games.1.title', 'ZZ');
        $this->assertStringContainsString('max-age=60', $response->headers->get('Cache-Control'));
    }

    public function test_a_failed_read_is_a_503_not_an_empty_list(): void
    {
        Http::fake(['https://sb.test/*' => Http::response(['message' => 'down'], 500)]);

        $this->getJson('/api/v1/games')->assertStatus(503)->assertExactJson(['error' => 'The catalog could not be read just now.']);
    }

    public function test_the_preflight_names_the_methods(): void
    {
        $this->options('/api/v1/games/facts')->assertNoContent()->assertHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    }

    public function test_facts_without_a_token_are_refused_and_nothing_is_called(): void
    {
        Http::fake();

        $this->postJson('/api/v1/games/facts', $this->facts())->assertStatus(401)->assertJsonPath('error', 'Send an access token as "Authorization: Bearer <token>".');
        Http::assertNothingSent();
    }

    public function test_facts_with_a_bad_token_are_refused(): void
    {
        Http::fake(['https://sb.test/auth/v1/user' => Http::response(['msg' => 'bad'], 401)]);

        $this->postJson('/api/v1/games/facts', $this->facts(), ['Authorization' => 'Bearer nope'])->assertStatus(401);
        Http::assertSentCount(1);
    }

    public function test_a_body_that_is_not_json_or_is_malformed_is_a_400_before_any_write(): void
    {
        $this->fakeSignedIn();

        $this->call('POST', '/api/v1/games/facts', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer t', 'CONTENT_TYPE' => 'application/json'], '{not json')
            ->assertStatus(400)->assertJsonPath('error', 'The request body must be JSON.');
        $this->postJson('/api/v1/games/facts', $this->facts(['slug' => 'x']), ['Authorization' => 'Bearer t'])
            ->assertStatus(400)->assertJsonPath('error', 'unknown field: slug');

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/rpc/'));
    }

    public function test_a_body_past_the_byte_cap_is_a_413(): void
    {
        $this->fakeSignedIn();
        $huge = '{"format":"coilbox-hub-games","pad":"'.str_repeat('x', GameFacts::MAX_BYTES).'"}';

        $this->call('POST', '/api/v1/games/facts', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer t', 'CONTENT_TYPE' => 'application/json'], $huge)
            ->assertStatus(413);
    }

    public function test_a_good_submission_writes_once_and_answers_in_request_order(): void
    {
        $this->fakeSignedIn();

        $this->postJson('/api/v1/games/facts', $this->facts(), ['Authorization' => 'Bearer t'])
            ->assertOk()
            ->assertExactJson(['format' => 'coilbox-hub-games', 'version' => 1, 'results' => [['kind' => 'unit', 'name' => 'armcom', 'outcome' => 'accepted']]]);

        // The secret key writes, and the digest is the hub's own.
        Http::assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), '/rpc/submit_game_facts')) {
                return false;
            }
            $body = json_decode($request->body(), true);

            return $request->header('apikey')[0] === 'sk'
                && $body['p_submitted_by'] === self::USER
                && preg_match('/^[0-9a-f]{64}$/', $body['p_submission']['units'][0]['facts_digest'])
                && ! array_key_exists('downloads', $body['p_submission']);
        });
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'offer_game_download_sources'));
    }

    public function test_download_sources_are_offered_apart_from_the_facts(): void
    {
        $this->fakeSignedIn();

        $this->postJson('/api/v1/games/facts', $this->facts(['downloads' => [['kind' => 'rapid', 'value' => 'ba:stable']]]), ['Authorization' => 'Bearer t'])->assertOk();

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return str_ends_with($request->url(), '/rpc/offer_game_download_sources')
                && $body === ['p_shortname' => 'BA', 'p_sources' => [['kind' => 'rapid', 'value' => 'ba:stable']], 'p_offered_by' => self::USER];
        });
    }

    public function test_an_offer_that_fails_does_not_cost_the_submission(): void
    {
        $this->fakeSignedIn(['https://sb.test/rest/v1/rpc/offer_game_download_sources' => Http::response(['message' => 'no'], 500)]);

        $this->postJson('/api/v1/games/facts', $this->facts(['downloads' => [['kind' => 'rapid', 'value' => 'ba:stable']]]), ['Authorization' => 'Bearer t'])->assertOk();
    }

    public function test_a_failed_write_is_a_503(): void
    {
        $this->fakeSignedIn(['https://sb.test/rest/v1/rpc/submit_game_facts' => Http::response(['message' => 'no'], 500)]);

        $this->postJson('/api/v1/games/facts', $this->facts(), ['Authorization' => 'Bearer t'])
            ->assertStatus(503)->assertJsonPath('error', 'The game catalog could not be written just now.');
    }

    public function test_no_secret_key_is_a_503_that_says_so(): void
    {
        config(['hub.supabase.service_role_key' => '']);
        $this->fakeSignedIn();

        $this->postJson('/api/v1/games/facts', $this->facts(), ['Authorization' => 'Bearer t'])
            ->assertStatus(503)->assertJsonPath('error', 'This deployment has not configured Supabase. Set SUPABASE_SERVICE_ROLE_KEY.');
    }
}
