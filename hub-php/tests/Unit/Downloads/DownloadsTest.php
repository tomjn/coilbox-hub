<?php

namespace Tests\Unit\Downloads;

use App\Domain\Downloads\Downloads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class DownloadsTest extends TestCase
{
    use RefreshDatabase;

    private const MAP_ID = 'aaaaaaaa-0000-0000-0000-000000000001';

    private const ITEM_ID = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk', 'hub.supabase.service_role_key' => 'sk']);
    }

    private function request(string $ip = '203.0.113.5'): Request
    {
        return Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
    }

    /** Supabase finds the target with this id, or none when null. */
    private function target(?string $id, int $status = 200): void
    {
        Http::fake(['https://sb.test/rest/v1/*' => Http::response($status === 200 ? ($id === null ? [] : [['id' => $id]]) : ['message' => 'boom'], $status)]);
    }

    private function counted(string $kind = 'map', string $id = self::MAP_ID): int
    {
        return (int) DB::table('download_day')->where('kind', $kind)->where('target_id', $id)->sum('count');
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

    public function test_an_invalid_address_is_returned_unchanged(): void
    {
        $this->assertSame('', Downloads::network(''));
        $this->assertSame('garbage', Downloads::network('garbage'));
    }

    public function test_record_looks_the_map_up_by_name_as_admin_and_counts_it(): void
    {
        $this->target(self::MAP_ID);

        $this->assertTrue(Downloads::record('map', 'Red', $this->request()));

        Http::assertSent(fn (HttpRequest $sent) => str_starts_with($sent->url(), 'https://sb.test/rest/v1/map?')
            && str_contains(urldecode($sent->url()), 'map_name=eq.Red')
            && $sent->hasHeader('apikey', 'sk'));
        $this->assertSame(1, $this->counted());
        $this->assertTrue(DB::table('download_seen')->where('seen_key', Downloads::seenKey('map', 'Red', '203.0.113.5', now()->toDateString()))->exists());
    }

    public function test_a_game_must_be_visible_and_an_item_not_withdrawn(): void
    {
        $this->target('bbbbbbbb-0000-0000-0000-000000000001');
        Downloads::record('game', 'ba', $this->request());
        Downloads::record('item', self::ITEM_ID, $this->request());

        Http::assertSent(fn (HttpRequest $sent) => str_contains(urldecode($sent->url()), '/game?') && str_contains(urldecode($sent->url()), 'hidden_at=is.null'));
        Http::assertSent(fn (HttpRequest $sent) => str_contains(urldecode($sent->url()), '/item?') && str_contains(urldecode($sent->url()), 'deleted_at=is.null'));
    }

    public function test_an_item_id_that_is_not_a_uuid_is_false_without_asking_supabase(): void
    {
        $this->target(self::ITEM_ID);

        $this->assertFalse(Downloads::record('item', 'not-a-uuid', $this->request()));
        Http::assertNothingSent();
    }

    public function test_the_same_visitor_counts_once_a_day_and_a_repeat_is_still_true(): void
    {
        $this->target(self::MAP_ID);

        $this->assertTrue(Downloads::record('map', 'Red', $this->request()));
        $this->assertTrue(Downloads::record('map', 'Red', $this->request()));
        $this->assertSame(1, $this->counted());

        Downloads::record('map', 'Red', $this->request('203.0.113.6'));
        $this->assertSame(2, $this->counted());
    }

    public function test_the_ipv6_network_counts_once(): void
    {
        $this->target(self::MAP_ID);

        Downloads::record('map', 'Red', $this->request('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        Downloads::record('map', 'Red', $this->request('2001:db8:1:2::1'));

        $this->assertSame(1, $this->counted());
    }

    public function test_keys_older_than_yesterday_are_pruned(): void
    {
        $this->target(self::MAP_ID);
        DB::table('download_seen')->insert([
            ['day' => now()->subDays(2)->toDateString(), 'seen_key' => str_repeat('a', 64)],
            ['day' => now()->subDay()->toDateString(), 'seen_key' => str_repeat('b', 64)],
        ]);

        Downloads::record('map', 'Red', $this->request());

        $this->assertSame([str_repeat('b', 64)], DB::table('download_seen')->where('day', '<', now()->toDateString())->pluck('seen_key')->all());
    }

    public function test_record_still_counts_with_an_empty_invalid_or_null_address(): void
    {
        $this->target(self::MAP_ID);
        foreach (['', 'garbage', null] as $ip) {
            $request = Mockery::mock(Request::class)->makePartial();
            $request->shouldReceive('ip')->andReturn($ip);

            $this->assertTrue(Downloads::record('map', 'Red', $request), var_export($ip, true));
        }
    }

    public function test_record_is_false_without_a_service_key(): void
    {
        config(['hub.supabase.service_role_key' => '']);
        $this->target(self::MAP_ID);

        $this->assertFalse(Downloads::record('map', 'Red', $this->request()));
        Http::assertNothingSent();
        $this->assertSame(0, $this->counted());
    }

    public function test_record_is_false_when_supabase_fails(): void
    {
        $this->target(null, 500);

        $this->assertFalse(Downloads::record('map', 'Red', $this->request()));
        $this->assertSame(0, DB::table('download_day')->count());
    }

    public function test_record_is_false_for_a_target_that_does_not_exist(): void
    {
        $this->target(null);

        $this->assertFalse(Downloads::record('map', 'Nope', $this->request()));
        $this->assertSame(0, DB::table('download_seen')->count());
    }

    public function test_record_is_false_when_mariadb_fails(): void
    {
        $this->target(self::MAP_ID);

        $this->assertFalse($this->withBrokenDatabase(fn () => Downloads::record('map', 'Red', $this->request())));
        $this->assertSame(0, DB::table('download_seen')->count());
    }

    public function test_counts_give_the_total_and_the_last_30_days(): void
    {
        DB::table('download_day')->insert([
            ['kind' => 'map', 'target_id' => self::MAP_ID, 'day' => now()->toDateString(), 'count' => 3],
            ['kind' => 'map', 'target_id' => self::MAP_ID, 'day' => now()->subDays(29)->toDateString(), 'count' => 2],
            ['kind' => 'map', 'target_id' => self::MAP_ID, 'day' => now()->subDays(30)->toDateString(), 'count' => 5],
            ['kind' => 'game', 'target_id' => self::MAP_ID, 'day' => now()->toDateString(), 'count' => 7],
        ]);

        $this->assertSame(['total' => 10, 'last30' => 5], Downloads::counts('map', self::MAP_ID));
        $this->assertSame(10, Downloads::total('map', self::MAP_ID));
        $this->assertNull(Downloads::counts('map', 'aaaaaaaa-0000-0000-0000-000000000002'));
        $this->assertNull(Downloads::counts('map', null));
    }

    public function test_counts_are_null_and_the_total_zero_when_mariadb_fails(): void
    {
        $this->withBrokenDatabase(function () {
            $this->assertNull(Downloads::counts('map', self::MAP_ID));
            $this->assertSame(0, Downloads::total('item', self::ITEM_ID));
        });
    }

    public function test_the_ranking_counts_one_kind_inside_the_window(): void
    {
        $other = 'aaaaaaaa-0000-0000-0000-000000000002';
        DB::table('download_day')->insert([
            ['kind' => 'map', 'target_id' => self::MAP_ID, 'day' => now()->toDateString(), 'count' => 3],
            ['kind' => 'map', 'target_id' => self::MAP_ID, 'day' => now()->subDays(10)->toDateString(), 'count' => 1],
            ['kind' => 'map', 'target_id' => $other, 'day' => now()->subDays(40)->toDateString(), 'count' => 9],
            ['kind' => 'game', 'target_id' => $other, 'day' => now()->toDateString(), 'count' => 9],
        ]);

        $this->assertSame([self::MAP_ID => 4], Downloads::ranking('map', 30));
        $this->assertEqualsCanonicalizing([self::MAP_ID => 4, $other => 9], Downloads::ranking('map', 182));
    }
}
