<?php

namespace Tests\Feature\Gallery;

use App\Domain\Container\Container;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The desktop app's `/api/v1/items` and `/api/v1/profile`, plus `/i/{id}` and
 * `/export`. The profile cases are ported from `app/api/v1/profile/route.test.ts`.
 */
class ItemsApiTest extends TestCase
{
    private const USER = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    private const ID = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.supabase.url' => 'https://sb.test',
            'hub.supabase.publishable_key' => 'pk',
            'app.url' => 'https://hub.test',
        ]);
    }

    private function summary(string $id = self::ID): array
    {
        return [
            'id' => $id,
            'kind' => 'preset',
            'mode' => null,
            'title' => 'Example',
            'description' => '',
            'game_name' => 'Beyond All Reason',
            'game_key' => null,
            'map_name' => null,
            'tags' => ['eco'],
            'author_name' => 'Someone',
            'author_id' => self::USER,
            'created_at' => '2026-01-01T00:00:00Z',
            'featured_at' => null,
        ];
    }

    private function signedIn(): void
    {
        Http::fake(['https://sb.test/auth/v1/user' => Http::response(['id' => self::USER], 200)]);
    }

    /** Fake the auth check, then each extra URL pattern in order. */
    private function fakeWithAuth(array $others): void
    {
        Http::fake(['https://sb.test/auth/v1/user' => Http::response(['id' => self::USER], 200)] + $others);
    }

    private function bearer(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer good-token', 'CONTENT_TYPE' => 'application/json'];
    }

    private function postRaw(string $uri, string $body, array $server)
    {
        return $this->call('POST', $uri, [], [], [], $server, $body);
    }

    private function presetCode(): string
    {
        return Container::encodeCode('preset', Container::SUPPORTED_KIND_VERSIONS['preset'], (object) [
            'gameName' => 'Beyond All Reason',
            'mapName' => 'Comet Catcher Remake',
            'startPosType' => 2,
            'modOptionValues' => new \stdClass,
            'participants' => [],
        ]);
    }

    public function test_the_list_answers_200_with_the_format_marker_paging_and_a_cache_header(): void
    {
        Http::fake(['https://sb.test/rest/v1/item*' => Http::response([$this->summary()], 200, ['Content-Range' => '0-0/30'])]);

        $response = $this->getJson('/api/v1/items?page=1');

        $response->assertOk();
        $response->assertExactJson([
            'format' => 'coilbox-hub-items',
            'version' => 1,
            'page' => 1,
            'page_size' => 24,
            'total' => 30,
            'items' => [$this->summary()],
        ]);
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=60', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('s-maxage=60', $response->headers->get('Cache-Control'));
        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_an_unknown_query_parameter_is_a_400_and_never_reaches_the_database(): void
    {
        Http::fake();

        $response = $this->getJson('/api/v1/items?orderBy=title');

        $response->assertStatus(400);
        $response->assertExactJson(['error' => 'Unknown query parameter: orderBy']);
        Http::assertNothingSent();
    }

    public function test_a_range_past_the_last_row_is_an_empty_page_with_the_real_total(): void
    {
        Http::fake(['https://sb.test/rest/v1/item*' => Http::sequence()
            ->push(['message' => 'Requested range not satisfiable', 'code' => 'PGRST103'], 416)
            ->push([$this->summary()], 200, ['Content-Range' => '0-0/4'])]);

        $response = $this->getJson('/api/v1/items?page=9');

        $response->assertOk();
        $response->assertJsonPath('items', []);
        $response->assertJsonPath('total', 4);
        $response->assertJsonPath('page', 9);
    }

    public function test_one_item_carries_a_container_url_rather_than_the_container(): void
    {
        Http::fake(['https://sb.test/rest/v1/item*' => Http::response([$this->summary()], 200)]);

        $response = $this->getJson('/api/v1/items/'.self::ID);

        $response->assertOk();
        $response->assertJsonPath('format', 'coilbox-hub-item');
        $response->assertJsonPath('item.id', self::ID);
        $response->assertJsonPath('item.container_url', 'https://hub.test/i/'.self::ID);
        $response->assertJsonMissingPath('item.container');
    }

    public function test_an_unknown_item_is_a_404(): void
    {
        Http::fake(['https://sb.test/rest/v1/item*' => Http::response([], 200)]);

        $response = $this->getJson('/api/v1/items/'.self::ID);

        $response->assertNotFound();
        $response->assertExactJson(['error' => 'No such item.']);
    }

    public function test_publishing_without_a_token_is_a_401(): void
    {
        Http::fake();

        $response = $this->postJson('/api/v1/items', ['code' => $this->presetCode(), 'title' => 'T']);

        $response->assertUnauthorized();
        $this->assertStringContainsString('Authorization: Bearer', $response->json('error'));
        Http::assertNothingSent();
    }

    public function test_publishing_a_body_that_is_not_json_is_a_400(): void
    {
        $this->signedIn();

        $response = $this->postRaw('/api/v1/items', 'this is not json', $this->bearer());

        $response->assertStatus(400);
        $response->assertExactJson(['error' => 'The request body must be JSON.']);
    }

    public function test_publishing_a_good_code_is_a_201_with_a_container_url_ending_in_the_item_id(): void
    {
        $this->fakeWithAuth(['https://sb.test/rest/v1/item*' => Http::response([$this->summary()], 201)]);

        $response = $this->postRaw('/api/v1/items', json_encode(['code' => $this->presetCode(), 'title' => 'Example']), $this->bearer());

        $response->assertStatus(201);
        $response->assertJsonPath('format', 'coilbox-hub-item');
        $this->assertStringEndsWith('/i/'.self::ID, $response->json('item.container_url'));
        $inserts = Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), '/rest/v1/item'));
        $this->assertCount(1, $inserts);
        $this->assertSame(self::USER, $inserts->first()[0]->data()['author_id']);
    }

    public function test_publishing_a_bad_code_is_a_422(): void
    {
        $this->signedIn();

        $response = $this->postRaw('/api/v1/items', json_encode(['code' => 'not-a-coilbox-code', 'title' => 'Example']), $this->bearer());

        $response->assertStatus(422);
        $this->assertStringContainsString('not something coilbox made', $response->json('error'));
    }

    public function test_publishing_past_the_rate_limit_is_a_429(): void
    {
        $this->fakeWithAuth(['https://sb.test/rest/v1/item*' => Http::response(
            ['message' => 'Too many published in the last hour. Try again later.', 'code' => '53400'],
            400,
        )]);

        $response = $this->postRaw('/api/v1/items', json_encode(['code' => $this->presetCode(), 'title' => 'Example']), $this->bearer());

        $response->assertStatus(429);
        $this->assertSame('Could not publish it: Too many published in the last hour. Try again later.', $response->json('error'));
    }

    public function test_the_import_ping_is_a_204_when_the_rpc_counted_the_item(): void
    {
        Http::fake(['https://sb.test/rest/v1/rpc/record_item_imported' => Http::response('true', 200, ['Content-Type' => 'application/json'])]);

        $response = $this->postJson('/api/v1/items/'.self::ID.'/imported');

        $response->assertNoContent();
        $recorded = Http::recorded();
        $this->assertSame(['target_id' => self::ID], $recorded[0][0]->data());
    }

    public function test_the_import_ping_is_a_404_when_the_rpc_counted_nothing(): void
    {
        Http::fake(['https://sb.test/rest/v1/rpc/record_item_imported' => Http::response('false', 200, ['Content-Type' => 'application/json'])]);

        $response = $this->postJson('/api/v1/items/'.self::ID.'/imported');

        $response->assertNotFound();
        $response->assertExactJson(['error' => 'No such item.']);
    }

    public function test_the_raw_container_keeps_an_empty_object_as_an_empty_object(): void
    {
        Http::fake(['https://sb.test/rest/v1/item*' => Http::response([['container' => '{"a": {}}']], 200)]);

        $response = $this->get('/i/'.self::ID);

        $response->assertOk();
        $this->assertSame('{"a":{}}', $response->getContent());
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
        $recorded = Http::recorded();
        $this->assertStringContainsString('container%3A%3Atext', (string) $recorded[0][0]->url());
    }

    public function test_an_unknown_container_is_a_404(): void
    {
        Http::fake(['https://sb.test/rest/v1/item*' => Http::response([], 200)]);

        $this->get('/i/'.self::ID)->assertNotFound();
    }

    public function test_the_export_lists_every_item_with_its_container_and_a_content_disposition(): void
    {
        $row = ['id' => self::ID, 'kind' => 'preset', 'title' => 'Example', 'container' => '{"a": {}}'];
        Http::fake(['https://sb.test/rest/v1/item*' => Http::response([$row], 200, ['Content-Range' => '0-0/1'])]);

        $response = $this->get('/export');

        $response->assertOk();
        $this->assertSame('inline; filename="coilbox-hub.json"', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
        $this->assertSame(
            '{"format":"coilbox-hub-export","version":1,"count":1,"items":[{"id":"'.self::ID.'","kind":"preset","title":"Example","container":{"a":{}}}]}',
            $response->getContent(),
        );
    }

    public function test_the_profile_answers_with_the_display_name_uncacheable_by_anything_shared(): void
    {
        $this->fakeWithAuth(['https://sb.test/rest/v1/profile*' => Http::response([['display_name' => 'Tom']], 200)]);

        $response = $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer good-token']);

        $response->assertOk();
        $response->assertExactJson(['display_name' => 'Tom']);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        $asked = Http::recorded(fn (Request $request) => str_contains($request->url(), '/rest/v1/profile'));
        $this->assertStringContainsString('user_id=eq.'.self::USER, urldecode($asked->first()[0]->url()));
    }

    public function test_the_profile_has_a_null_display_name_when_the_account_has_no_profile_row(): void
    {
        $this->fakeWithAuth(['https://sb.test/rest/v1/profile*' => Http::response([], 200)]);

        $response = $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer good-token']);

        $response->assertOk();
        $response->assertExactJson(['display_name' => null]);
    }

    public function test_a_missing_token_is_a_401_that_tells_the_client_what_to_send(): void
    {
        Http::fake();

        $response = $this->getJson('/api/v1/profile');

        $response->assertUnauthorized();
        $this->assertStringContainsString('Authorization: Bearer', $response->json('error'));
    }

    public function test_an_invalid_token_is_a_401(): void
    {
        Http::fake(['https://sb.test/auth/v1/user' => Http::response(['msg' => 'invalid JWT'], 401)]);

        $response = $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer bad-token']);

        $response->assertUnauthorized();
        $this->assertStringContainsString('not valid', $response->json('error'));
    }

    public function test_the_profile_answers_the_cors_preflight(): void
    {
        $this->call('OPTIONS', '/api/v1/profile')->assertStatus(204);
    }
}
