<?php

namespace Tests\Feature\Maps;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The route level cases from `lib/api/map*.test.ts`, plus the write path with Supabase faked. */
class MapApiTest extends TestCase
{
    private const MAP = [
        'map_name' => 'Comet Catcher Remake 1.8',
        'source_archive' => 'Comet Catcher Remake 1.8',
        'source_hash' => 'src-comet',
        'catalog_version' => 3,
        'width_elmos' => 6144,
        'height_elmos' => 10240,
        'world_height_min' => -120.5,
        'world_height_max' => 890,
    ];

    /** @var list<string> */
    private array $paths = [];

    /** @var array<string, mixed> */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'sb_publishable_x', 'hub.supabase.service_role_key' => 'sb_secret_x']);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->paths[] = $path;
            if ($path === '/auth/v1/user') {
                return $request->header('Authorization')[0] === 'Bearer good'
                    ? Http::response(['id' => 'user-1'])
                    : Http::response(['message' => 'bad jwt'], 401);
            }
            $answer = $this->answers[$path] ?? [];

            return $answer instanceof \Closure ? $answer($request) : Http::response($answer);
        });
    }

    private function postJson_(string $uri, mixed $body, ?string $token = 'good')
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $this->call('POST', $uri, [], [], [], $this->transformHeadersToServerVars($headers), is_string($body) ? $body : json_encode($body));
    }

    private static function batch(array ...$maps): array
    {
        return ['format' => 'coilbox-hub-maps', 'version' => 1, 'maps' => $maps];
    }

    public function test_no_token_is_a_401_before_the_body_is_read(): void
    {
        foreach (['/api/v1/maps', '/api/v1/maps/have'] as $uri) {
            $response = $this->postJson_($uri, 'not json at all', null);
            $response->assertStatus(401)->assertExactJson(['error' => 'Send an access token as "Authorization: Bearer <token>".']);
            $response->assertHeader('Access-Control-Allow-Origin', '*');
        }
        $this->postJson_('/api/v1/maps', 'not json', 'bad')->assertStatus(401)
            ->assertExactJson(['error' => 'That access token is not valid. Sign in again and use a fresh one.']);
    }

    public function test_a_submission_answers_per_map_in_request_order(): void
    {
        $this->answers['/rest/v1/rpc/submit_map_facts'] = [
            ['map_name' => 'Alpha 1.0', 'outcome' => 'stored', 'said' => null],
            ['map_name' => 'Zed 1.0', 'outcome' => 'conflict', 'said' => 'held elsewhere'],
        ];

        $response = $this->postJson_('/api/v1/maps', self::batch(
            ['map_name' => 'Zed 1.0'] + self::MAP,
            ['map_name' => 'Bad 1.0', 'slug' => 'x'] + self::MAP,
            ['map_name' => 'Alpha 1.0'] + self::MAP,
        ));

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('{"format":"coilbox-hub-maps","version":1,"results":[{"map_name":"Zed 1.0","outcome":"conflict","said":"held elsewhere"},{"map_name":"Bad 1.0","outcome":"refused","said":"Unknown field: slug"},{"map_name":"Alpha 1.0","outcome":"stored"}]}', $response->getContent());
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/rpc/submit_map_facts')
            && $request['p_submitted_by'] === 'user-1'
            && array_column(array_column($request['p_maps'], 'entry'), 'map_name') === ['Alpha 1.0', 'Zed 1.0']);
    }

    public function test_a_batch_of_only_malformed_entries_never_reaches_the_database(): void
    {
        $response = $this->postJson_('/api/v1/maps', self::batch(['width_elmos' => 0] + self::MAP));

        $response->assertOk()->assertExactJson(['format' => 'coilbox-hub-maps', 'version' => 1, 'results' => [
            ['map_name' => 'Comet Catcher Remake 1.8', 'outcome' => 'refused', 'said' => '`width_elmos` is required and must be a positive integer.'],
        ]]);
        $this->assertSame(['/auth/v1/user'], $this->paths);
    }

    public function test_the_write_failures(): void
    {
        $this->answers['/rest/v1/rpc/submit_map_facts'] = fn () => Http::response(['code' => '53400', 'message' => 'limit'], 400);
        $this->postJson_('/api/v1/maps', self::batch(self::MAP))->assertStatus(429)
            ->assertExactJson(['error' => 'Too many maps submitted in the last hour. Try again later.']);

        $this->answers['/rest/v1/rpc/submit_map_facts'] = fn () => Http::response(['code' => '57P01', 'message' => 'down'], 503);
        $this->postJson_('/api/v1/maps', self::batch(self::MAP))->assertStatus(503)
            ->assertExactJson(['error' => 'The map catalog could not be written just now.']);

        // A map the function answered nothing for is a hub bug, not an outcome.
        $this->answers['/rest/v1/rpc/submit_map_facts'] = [];
        $this->postJson_('/api/v1/maps', self::batch(self::MAP))->assertStatus(503);
    }

    public function test_a_body_past_the_byte_cap_is_a_413_and_bad_json_a_400(): void
    {
        $big = self::batch(['description' => str_repeat('é', 600000)] + self::MAP);
        $this->postJson_('/api/v1/maps', $big)->assertStatus(413)->assertExactJson(['error' => 'A request body may be at most 1048576 bytes.']);
        $this->postJson_('/api/v1/maps', '{nope')->assertStatus(400)->assertExactJson(['error' => 'The request body must be JSON.']);
    }

    public function test_have_answers_from_the_catalog(): void
    {
        $this->answers['/rest/v1/map'] = [['map_name' => 'Comet Catcher Remake 1.8', 'source_hash' => 'src-comet', 'catalog_version' => 2]];

        $this->postJson_('/api/v1/maps/have', ['keys' => [
            ['map_name' => 'Comet Catcher Remake 1.8', 'source_hash' => 'src-comet', 'catalog_version' => 3],
            ['map_name' => 'Other', 'source_hash' => 'h', 'catalog_version' => 1],
        ]])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson([
            'format' => 'coilbox-hub-map-have', 'version' => 1, 'results' => [
                ['map_name' => 'Comet Catcher Remake 1.8', 'status' => 'changed'],
                ['map_name' => 'Other', 'status' => 'missing'],
            ],
        ]);

        $this->answers['/rest/v1/map'] = fn () => Http::response(['message' => 'down'], 503);
        $this->postJson_('/api/v1/maps/have', ['keys' => [['map_name' => 'x', 'source_hash' => 'h', 'catalog_version' => 1]]])
            ->assertStatus(503)->assertExactJson(['error' => 'The map catalog could not be read just now.']);
    }

    public function test_lookup_needs_no_token_and_501_names_is_a_413_either_way(): void
    {
        $body = ['names' => array_map(fn (int $i) => 'Map '.$i, range(0, 500))];

        $anonymous = $this->postJson_('/api/v1/maps/lookup', $body, null);
        $withToken = $this->postJson_('/api/v1/maps/lookup', $body, 'not-a-real-token');

        $anonymous->assertStatus(413);
        $withToken->assertStatus(413);
        $this->assertSame($anonymous->getContent(), $withToken->getContent());
        $this->assertSame([], $this->paths);
    }

    public function test_lookup_asks_once_per_distinct_name_and_answers_every_position(): void
    {
        $this->answers['/rest/v1/rpc/map_facts'] = [['map_name' => 'A', 'facts' => ['slug' => 'a', 'appearance' => []]]];
        $this->answers['/rest/v1/asset_licence'] = [['all_maps' => true, 'map_name' => null, 'redistribute_extracted' => 'allowed', 'redistribute_rendered' => 'allowed']];

        $response = $this->postJson_('/api/v1/maps/lookup', ['names' => ['A', 'B', 'A']], null);

        $response->assertOk();
        $this->assertSame('{"format":"coilbox-hub-map-lookup","version":1,"results":[{"map_name":"A","map":{"slug":"a","appearance":{}}},{"map_name":"B","map":null},{"map_name":"A","map":{"slug":"a","appearance":{}}}]}', $response->getContent());
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/rpc/map_facts') && $request['p_names'] === ['A', 'B']);
    }

    public function test_map_packs_list_and_fail_loudly(): void
    {
        $this->answers['/rest/v1/asset_licence'] = [['all_maps' => true, 'map_name' => null, 'redistribute_extracted' => 'allowed', 'redistribute_rendered' => 'allowed']];
        $this->answers['/rest/v1/map_pack'] = [['id' => 'p', 'title' => 'BAR', 'blurb' => null, 'featured_at' => '2026-01-01T00:00:00Z']];
        $this->answers['/rest/v1/map_pack_entry'] = [['pack_id' => 'p', 'map_name' => 'Isis 1.3', 'slug' => 'isis-1-3', 'display_name' => null, 'archive_filename' => null]];

        $this->get('/api/v1/map-packs')->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public, s-maxage=60')
            ->assertExactJson(['format' => 'coilbox-hub-map-packs', 'version' => 1, 'packs' => [
                ['id' => 'p', 'title' => 'BAR', 'featured' => true, 'maps' => [['id' => 'Isis 1.3', 'title' => 'Isis 1.3', 'download' => ['kind' => 'map', 'springName' => 'Isis 1.3']]]],
            ]]);

        $this->answers['/rest/v1/asset_licence'] = fn () => Http::response(['message' => 'down'], 503);
        $this->get('/api/v1/map-packs')->assertStatus(503)->assertExactJson(['error' => 'The map packs could not be read just now.']);
    }

    public function test_a_preflight_is_answered(): void
    {
        $this->call('OPTIONS', '/api/v1/maps/lookup')->assertNoContent()->assertHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    }
}
