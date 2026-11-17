<?php

namespace Tests\Feature\Mirrors;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Mirrors\MirrorFixtures;

/** `GET /api/v1/catalogue` and `catalogue.sig`, against a faked Supabase. */
class CatalogueApiTest extends TestCase
{
    use MirrorFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.supabase.url' => 'https://sb.test',
            'hub.supabase.publishable_key' => 'pk',
            'hub.supabase.service_role_key' => 'sk',
            'hub.mirrors.secret_key' => self::fixtureKeys()['hub_secret_key'],
            'hub.mirrors.next_secret_key' => '',
        ]);
    }

    private function source(): array
    {
        $json = json_decode(self::fixture('catalogue.json'), true);

        return ['seq' => 1, 'changes' => 4, 'mirrors' => $json['mirrors'], 'archives' => $json['archives'], 'removed' => $json['removed']];
    }

    public function test_serves_the_stored_body_and_sig_unchanged(): void
    {
        $body = self::fixture('catalogue.json');
        Http::fake(['https://sb.test/rest/v1/catalogue_state*' => Http::response([
            ['body' => $body, 'sig' => self::fixture('catalogue.sig'), 'changes' => 4, 'built_changes' => 4, 'next_key_public' => null],
        ])]);

        $response = $this->get('/api/v1/catalogue');

        $response->assertOk()->assertHeader('Content-Type', 'application/json');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($body, $response->getContent());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'rpc/catalogue_source'));

        $sig = $this->get('/api/v1/catalogue.sig');
        $sig->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->assertSame(self::fixture('catalogue.sig'), $sig->getContent());
    }

    public function test_rebuilds_when_changes_moved_and_serves_the_new_body(): void
    {
        $stored = ['body' => 'old', 'sig' => 'oldsig', 'built' => 4];
        Http::fake([
            'https://sb.test/rest/v1/rpc/catalogue_source' => Http::response($this->source()),
            'https://sb.test/rest/v1/rpc/store_catalogue' => function (Request $r) use (&$stored) {
                $stored = ['body' => $r['p_body'], 'sig' => $r['p_sig'], 'built' => $r['p_changes']];

                return Http::response('true');
            },
            'https://sb.test/rest/v1/catalogue_state*' => function () use (&$stored) {
                return Http::response([
                    ['body' => $stored['body'], 'sig' => $stored['sig'], 'changes' => 5, 'built_changes' => $stored['built'], 'next_key_public' => null],
                ]);
            },
        ]);

        $response = $this->get('/api/v1/catalogue');

        $response->assertOk();
        $this->assertNotSame('old', $response->getContent());
        $this->assertStringStartsWith('{"v":1,"seq":1,"generated_at":', $response->getContent());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'rpc/store_catalogue') && $r['p_seq'] === 1 && $r['p_changes'] === 4 && $r['p_next_key'] === null);
    }

    public function test_sig_route_never_rebuilds(): void
    {
        Http::fake(['https://sb.test/rest/v1/catalogue_state*' => Http::response([
            ['body' => 'b', 'sig' => 's', 'changes' => 9, 'built_changes' => 1, 'next_key_public' => null],
        ])]);

        $this->get('/api/v1/catalogue.sig')->assertOk();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'rpc/catalogue_source'));
    }

    public function test_answers_503_when_nothing_is_stored_and_no_key_is_set(): void
    {
        config(['hub.mirrors.secret_key' => '']);
        Http::fake(['https://sb.test/rest/v1/catalogue_state*' => Http::response([
            ['body' => null, 'sig' => null, 'changes' => 0, 'built_changes' => -1, 'next_key_public' => null],
        ])]);

        $this->get('/api/v1/catalogue')->assertStatus(503)->assertJsonStructure(['error']);
        $this->get('/api/v1/catalogue.sig')->assertStatus(503)->assertJsonStructure(['error']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'rpc/'));
    }
}
