<?php

namespace Tests\Feature\Mirrors;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Mirrors\MirrorFixtures;

/** `mirrors:catalogue` and `mirrors:status`, against a faked Supabase. */
class MirrorCommandsTest extends TestCase
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

        return ['seq' => 7, 'changes' => 4, 'mirrors' => $json['mirrors'], 'archives' => $json['archives'], 'removed' => $json['removed']];
    }

    public function test_catalogue_command_prints_the_seq(): void
    {
        Http::fake([
            'https://sb.test/rest/v1/rpc/catalogue_source' => Http::response($this->source()),
            'https://sb.test/rest/v1/rpc/store_catalogue' => Http::response('true'),
            'https://sb.test/rest/v1/catalogue_state*' => Http::response([['seq' => 7]]),
        ]);

        $this->artisan('mirrors:catalogue')->expectsOutputToContain('catalogue seq 7')->assertExitCode(0);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'rpc/store_catalogue') && $r['p_seq'] === 7);
    }

    public function test_status_lists_mirrors_waiting_for_the_next_key(): void
    {
        $next = self::fixtureKeys()['rotated_hub_secret_key'];
        config(['hub.mirrors.next_secret_key' => $next]);
        $public = \App\Domain\Mirrors\HubKeys::fromConfig()->nextKey()['public_key'];

        Http::fake([
            'https://sb.test/rest/v1/mirror*' => Http::response([
                ['id' => 'a', 'enabled' => true, 'healthy' => true, 'last_report_at' => '2026-11-17T10:00:00+00:00', 'last_catalogue_seq' => 5, 'last_catalogue_generated_at' => 1794787200],
                ['id' => 'b', 'enabled' => true, 'healthy' => false, 'last_report_at' => null, 'last_catalogue_seq' => null, 'last_catalogue_generated_at' => null],
                ['id' => 'c', 'enabled' => true, 'healthy' => true, 'last_report_at' => '2026-11-17T10:00:00+00:00', 'last_catalogue_seq' => 9, 'last_catalogue_generated_at' => 1794787200],
                ['id' => 'd', 'enabled' => false, 'healthy' => false, 'last_report_at' => null, 'last_catalogue_seq' => null, 'last_catalogue_generated_at' => null],
            ]),
            'https://sb.test/rest/v1/catalogue_state*' => Http::response([
                ['seq' => 9, 'body' => '{}', 'changes' => 1, 'built_changes' => 1, 'next_key_public' => $public, 'next_key_since_seq' => 8],
            ]),
        ]);

        $this->artisan('mirrors:status')
            ->expectsOutputToContain('next key since seq 8')
            ->expectsOutputToContain('waiting for: a, b')
            ->assertExitCode(0);
    }

    public function test_status_says_safe_once_every_enabled_mirror_has_the_next_key(): void
    {
        config(['hub.mirrors.next_secret_key' => self::fixtureKeys()['rotated_hub_secret_key']]);
        $public = \App\Domain\Mirrors\HubKeys::fromConfig()->nextKey()['public_key'];

        Http::fake([
            'https://sb.test/rest/v1/mirror*' => Http::response([
                ['id' => 'a', 'enabled' => true, 'healthy' => true, 'last_report_at' => null, 'last_catalogue_seq' => 8, 'last_catalogue_generated_at' => null],
                ['id' => 'b', 'enabled' => false, 'healthy' => false, 'last_report_at' => null, 'last_catalogue_seq' => null, 'last_catalogue_generated_at' => null],
            ]),
            'https://sb.test/rest/v1/catalogue_state*' => Http::response([
                ['seq' => 8, 'body' => '{}', 'changes' => 1, 'built_changes' => 1, 'next_key_public' => $public, 'next_key_since_seq' => 8],
            ]),
        ]);

        $this->artisan('mirrors:status')->expectsOutputToContain('safe to switch keys')->assertExitCode(0);
    }

    public function test_catalogue_command_fails_without_a_key(): void
    {
        config(['hub.mirrors.secret_key' => '']);
        Http::fake();

        $this->artisan('mirrors:catalogue')->expectsOutputToContain('HUB_MIRROR_SECRET_KEY')->assertExitCode(1);
        Http::assertNothingSent();
    }
}
