<?php

namespace Tests\Unit\Downloads;

use App\Domain\Downloads\Downloads;
use App\Support\HubCache;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DownloadsTest extends TestCase
{
    private const URL = 'https://sb.test/rest/v1/rpc/record_download';

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk', 'hub.supabase.service_role_key' => 'sk']);
    }

    private function request(string $ip = '203.0.113.5'): Request
    {
        return Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
    }

    private function answer(string $body, int $status = 200): void
    {
        Http::fake([self::URL => Http::response($body, $status, ['Content-Type' => 'application/json'])]);
    }

    public function test_seen_key_is_64_lowercase_hex(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Downloads::seenKey('map', 'Red', '203.0.113.5', '2026-11-18'));
    }

    public function test_seen_key_is_stable_for_the_same_input(): void
    {
        $this->assertSame(
            Downloads::seenKey('map', 'Red', '203.0.113.5', '2026-11-18'),
            Downloads::seenKey('map', 'Red', '203.0.113.5', '2026-11-18'),
        );
    }

    public function test_seen_key_changes_with_each_part(): void
    {
        $base = Downloads::seenKey('map', 'Red', '203.0.113.5', '2026-11-18');
        $this->assertNotSame($base, Downloads::seenKey('map', 'Red', '203.0.113.5', '2026-11-19'));
        $this->assertNotSame($base, Downloads::seenKey('map', 'Red', '203.0.113.6', '2026-11-18'));
        $this->assertNotSame($base, Downloads::seenKey('game', 'Red', '203.0.113.5', '2026-11-18'));
        $this->assertNotSame($base, Downloads::seenKey('map', 'Blue', '203.0.113.5', '2026-11-18'));
    }

    public function test_record_posts_kind_target_and_key(): void
    {
        $this->answer('true');

        $this->assertTrue(Downloads::record('map', 'Red', $this->request()));

        Http::assertSent(function (HttpRequest $sent) {
            $body = $sent->data();

            return $sent->url() === self::URL
                && $body['p_kind'] === 'map'
                && $body['p_target'] === 'Red'
                && $sent->hasHeader('apikey', 'sk')
                && $body['p_seen_key'] === Downloads::seenKey('map', 'Red', '203.0.113.5', now()->toDateString());
        });
    }

    public function test_the_key_uses_the_ipv6_network_not_the_address(): void
    {
        $key = fn (string $ip) => Downloads::seenKey('map', 'Red', Downloads::network($ip), '2026-11-18');

        $this->assertSame($key('2001:db8:1:2::1'), $key('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        $this->assertNotSame($key('2001:db8:1:2::1'), $key('2001:db8:1:3::1'));
    }

    public function test_an_ipv4_address_is_left_whole(): void
    {
        $this->assertSame('203.0.113.5', Downloads::network('203.0.113.5'));
        $this->assertNotSame(Downloads::network('203.0.113.5'), Downloads::network('203.0.113.6'));
    }

    public function test_record_sends_the_ipv6_network_in_the_key(): void
    {
        $this->answer('true');

        Downloads::record('map', 'Red', $this->request('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));

        Http::assertSent(fn (HttpRequest $sent) => $sent->data()['p_seen_key'] === Downloads::seenKey('map', 'Red', Downloads::network('2001:db8:1:2::1'), now()->toDateString()));
    }

    public function test_record_is_false_without_a_service_key(): void
    {
        config(['hub.supabase.service_role_key' => '']);
        $this->answer('true');

        $this->assertFalse(Downloads::record('map', 'Red', $this->request()));
        Http::assertNothingSent();
    }

    public function test_record_is_false_when_supabase_fails(): void
    {
        $this->answer('{"message":"boom"}', 500);

        $this->assertFalse(Downloads::record('map', 'Red', $this->request()));
    }

    public function test_record_is_false_when_the_download_was_not_counted(): void
    {
        $this->answer('false');

        $this->assertFalse(Downloads::record('map', 'Red', $this->request()));
    }

    public function test_a_counted_item_invalidates_its_page(): void
    {
        $this->answer('true');
        $id = '11111111-1111-1111-1111-111111111111';
        $calls = 0;
        $load = function () use (&$calls) {
            return ++$calls;
        };
        HubCache::remember('item:'.$id, [HubCache::item($id)], 60, $load);

        Downloads::record('item', $id, $this->request());

        HubCache::remember('item:'.$id, [HubCache::item($id)], 60, $load);
        $this->assertSame(2, $calls);
    }

    public function test_a_counted_map_or_game_leaves_the_cache_alone(): void
    {
        foreach (['map' => HubCache::MAPS, 'game' => HubCache::GAMES] as $kind => $tag) {
            Cache::flush();
            $this->answer('true');
            $calls = 0;
            $load = function () use (&$calls) {
                return ++$calls;
            };
            HubCache::remember('k', [$tag], 60, $load);

            $this->assertTrue(Downloads::record($kind, 'x', $this->request()));

            HubCache::remember('k', [$tag], 60, $load);
            $this->assertSame(1, $calls, $kind);
        }
    }

    public function test_an_uncounted_download_leaves_the_cache_alone(): void
    {
        $this->answer('false');
        $calls = 0;
        $load = function () use (&$calls) {
            return ++$calls;
        };
        HubCache::remember('k', [HubCache::MAPS], 60, $load);

        Downloads::record('map', 'x', $this->request());

        HubCache::remember('k', [HubCache::MAPS], 60, $load);
        $this->assertSame(1, $calls);
    }
}
