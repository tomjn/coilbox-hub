<?php

namespace Tests\Feature\Mirrors;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Mirrors\MirrorFixtures;

/** `POST /api/v1/mirrors/report`, against a faked Supabase and a faked mirror. */
class ReportApiTest extends TestCase
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

    /** @return array<string, mixed> */
    private function mirrorRow(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'jj-darkstars',
            'base_url' => 'https://mirror.test',
            'public_key' => self::fixtureKeys()['mirror_public_key'],
            'enabled' => true,
        ];
    }

    /** A catalogue that is already current, so a report does not rebuild one. */
    private function currentCatalogue(): array
    {
        return ['https://sb.test/rest/v1/catalogue_state*' => Http::response([
            ['body' => self::fixture('catalogue.json'), 'sig' => self::fixture('catalogue.sig'), 'changes' => 4, 'built_changes' => 4, 'next_key_public' => null],
        ])];
    }

    private function send(string $fixture = 'report-upload.json'): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/api/v1/mirrors/report', [], [], [], ['CONTENT_TYPE' => 'application/json'], self::fixture($fixture));
    }

    private function sentTo(string $path): bool
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), $path))->isNotEmpty();
    }

    public function test_fixture_report_is_recorded_checked_and_answered_200(): void
    {
        Http::fake($this->currentCatalogue() + [
            'https://sb.test/rest/v1/mirror*' => Http::response([$this->mirrorRow()]),
            'https://sb.test/rest/v1/rpc/record_mirror_report' => Http::response([
                'outcome' => 'recorded', 'listed' => 1,
                'check' => ['filename' => 'Test Map (v1.2) é.sd7', 'kind' => 'map', 'size' => 43],
            ]),
            'https://mirror.test/maps/*' => Http::response('', 200, ['Content-Length' => '43']),
            'https://sb.test/rest/v1/rpc/set_mirror_health' => Http::response(null, 204),
        ]);

        $response = $this->send();

        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        Http::assertSent(fn (Request $r) => $r->method() === 'HEAD'
            && $r->url() === 'https://mirror.test/maps/Test%20Map%20%28v1.2%29%20%C3%A9.sd7');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'rpc/record_mirror_report')
            && $r['p_mirror'] === 'jj-darkstars'
            && $r['p_payload']['event'] === 'upload'
            && is_array($r['p_payload']['held']));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'rpc/set_mirror_health')
            && $r['p_mirror'] === 'jj-darkstars' && $r['p_healthy'] === true);
    }

    public function test_a_failed_catalogue_refresh_does_not_fail_the_report(): void
    {
        Http::fake([
            'https://sb.test/rest/v1/catalogue_state*' => Http::response(['message' => 'down'], 500),
            'https://sb.test/rest/v1/mirror*' => Http::response([$this->mirrorRow()]),
            'https://sb.test/rest/v1/rpc/record_mirror_report' => Http::response(['outcome' => 'recorded', 'listed' => 0, 'check' => null]),
            'https://mirror.test/listing.json' => Http::response('{}', 200),
            'https://sb.test/rest/v1/rpc/set_mirror_health' => Http::response(null, 204),
        ]);

        $this->send('report-sync.json')->assertOk()->assertExactJson(['ok' => true]);
    }

    public function test_disabled_or_unknown_mirror_is_403_and_nothing_recorded(): void
    {
        Http::fake(['https://sb.test/rest/v1/mirror*' => Http::sequence()
            ->push([$this->mirrorRow(['enabled' => false])])
            ->push([])]);

        $this->send()->assertStatus(403)->assertExactJson(['error' => 'unknown_mirror']);
        $this->send()->assertStatus(403)->assertExactJson(['error' => 'unknown_mirror']);

        $this->assertFalse($this->sentTo('rpc/'));
    }

    public function test_wrong_key_is_403_and_record_mirror_report_never_called(): void
    {
        Http::fake(['https://sb.test/rest/v1/mirror*' => Http::response([
            $this->mirrorRow(['public_key' => self::fixtureKeys()['other_mirror_public_key']]),
        ])]);

        $this->send()->assertStatus(403)->assertExactJson(['error' => 'bad_signature']);

        $this->assertFalse($this->sentTo('rpc/'));
    }

    public function test_a_malformed_body_is_400_before_any_lookup(): void
    {
        Http::fake();

        $this->call('POST', '/api/v1/mirrors/report', [], [], [], [], 'nonsense')
            ->assertStatus(400)->assertExactJson(['error' => 'bad_request']);

        Http::assertNothingSent();
    }

    public function test_stale_report_is_409(): void
    {
        Http::fake([
            'https://sb.test/rest/v1/mirror*' => Http::response([$this->mirrorRow()]),
            'https://sb.test/rest/v1/rpc/record_mirror_report' => Http::response(['outcome' => 'stale']),
        ]);

        $this->send()->assertStatus(409)->assertExactJson(['error' => 'stale']);

        $this->assertFalse($this->sentTo('rpc/set_mirror_health'));
    }

    public function test_the_database_failing_is_503(): void
    {
        Http::fake(['https://sb.test/rest/v1/mirror*' => Http::response(['message' => 'down'], 500)]);

        $this->send()->assertStatus(503);
    }
}
