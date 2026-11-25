<?php

namespace Tests\Unit\Mirrors;

use App\Domain\Mirrors\HealthCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    private const BASE = 'https://mirror.example';

    private function candidate(int $size = 43): array
    {
        return ['filename' => 'a.sd7', 'kind' => 'map', 'size' => $size];
    }

    public function test_head_with_matching_length_is_healthy(): void
    {
        Http::fake(['*' => Http::response('', 200, ['Content-Length' => '43'])]);

        $this->assertTrue(HealthCheck::check(self::BASE, $this->candidate()));
        Http::assertSent(fn (Request $r) => $r->method() === 'HEAD' && $r->url() === self::BASE.'/maps/a.sd7');
    }

    public function test_head_with_other_length_or_404_or_redirect_is_unhealthy(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('', 200, ['Content-Length' => '44'])
            ->push('', 404, ['Content-Length' => '43'])
            ->push('', 302, ['Location' => 'https://elsewhere.example/a.sd7', 'Content-Length' => '43']),
        ]);

        $this->assertFalse(HealthCheck::check(self::BASE, $this->candidate()));
        $this->assertFalse(HealthCheck::check(self::BASE, $this->candidate()));
        $this->assertFalse(HealthCheck::check(self::BASE, $this->candidate()));
        Http::assertSentCount(3);
    }

    public function test_connection_failure_is_unhealthy(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->assertFalse(HealthCheck::check(self::BASE, $this->candidate()));
        $this->assertFalse(HealthCheck::check(self::BASE, null));
    }

    public function test_filename_is_rawurlencoded_in_the_url(): void
    {
        Http::fake(['*' => Http::response('', 200, ['Content-Length' => '43'])]);

        HealthCheck::check(self::BASE.'/', ['filename' => 'Test Map (v1.2) é.sd7', 'kind' => 'map', 'size' => 43]);

        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/maps/Test%20Map%20%28v1.2%29%20%C3%A9.sd7');
    }

    public function test_game_candidate_uses_the_games_folder(): void
    {
        Http::fake(['*' => Http::response('', 200, ['Content-Length' => '43'])]);

        HealthCheck::check(self::BASE, ['filename' => 'g.sd7', 'kind' => 'game', 'size' => 43]);

        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/games/g.sd7');
    }

    public function test_engine_candidate_uses_the_engines_folder(): void
    {
        Http::fake(['*' => Http::response('', 200, ['Content-Length' => '43'])]);

        HealthCheck::check('https://m.test', ['filename' => 'recoil_2026.09.03_arm64-macos.7z', 'kind' => 'engine', 'size' => 43]);

        Http::assertSent(fn (Request $r) => $r->method() === 'HEAD' && $r->url() === 'https://m.test/engines/recoil_2026.09.03_arm64-macos.7z');
    }

    public function test_no_candidate_checks_listing_json(): void
    {
        Http::fake([self::BASE.'/listing.json' => Http::sequence()->push('{}', 200)->push('', 500)]);

        $this->assertTrue(HealthCheck::check(self::BASE, null));
        $this->assertFalse(HealthCheck::check(self::BASE, null));
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === self::BASE.'/listing.json');
    }
}
