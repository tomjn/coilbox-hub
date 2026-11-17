<?php

namespace Tests\Feature\Mirrors;

use App\Domain\Mirrors\Mismatch;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** `POST /api/v1/archives/mismatch`, against a faked Supabase. */
class MismatchApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.supabase.url' => 'https://sb.test',
            'hub.supabase.publishable_key' => 'pk',
            'hub.supabase.service_role_key' => 'sk',
        ]);
    }

    private function send(mixed $body): TestResponse
    {
        $raw = is_string($body) ? $body : json_encode($body);

        return $this->call('POST', '/api/v1/archives/mismatch', [], [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.7'], $raw);
    }

    private static function report(): array
    {
        return ['sha256' => str_repeat('ab', 32), 'found' => ['internal_name' => 'Other Map', 'shortname' => null]];
    }

    public function test_a_report_is_sent_with_the_socket_address_and_answered_202(): void
    {
        Http::fake(['https://sb.test/rest/v1/rpc/report_archive_mismatch' => Http::response(['outcome' => 'recorded'])]);

        $response = $this->send(self::report());

        $response->assertStatus(202)->assertExactJson(['outcome' => 'recorded']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        Http::assertSent(fn (Request $r) => json_decode($r->body(), true) === [
            'p_sha256' => str_repeat('ab', 32),
            'p_found' => ['internal_name' => 'Other Map', 'shortname' => null],
            'p_ip' => '203.0.113.7',
        ]);
    }

    /** @return array<string, array{string, int, string}> */
    public static function outcomes(): array
    {
        return [
            'already' => ['already', 200, 'already'],
            'unknown archive' => ['unknown_archive', 404, 'unknown_archive'],
            'agrees' => ['agrees', 422, 'agrees'],
            'rate limited' => ['rate_limited', 429, 'rate_limited'],
            'anything else' => ['what', 503, 'unavailable'],
        ];
    }

    #[DataProvider('outcomes')]
    public function test_each_outcome_has_its_status(string $outcome, int $status, string $reason): void
    {
        Http::fake(['https://sb.test/rest/v1/rpc/report_archive_mismatch' => Http::response(['outcome' => $outcome])]);

        $response = $this->send(self::report())->assertStatus($status);
        $this->assertSame($reason, $response->json('reason') ?? $response->json('outcome'));
    }

    public function test_the_database_failing_is_503(): void
    {
        Http::fake(['https://sb.test/rest/v1/rpc/report_archive_mismatch' => Http::response(['message' => 'down'], 500)]);

        $this->send(self::report())->assertStatus(503)->assertJson(['reason' => 'unavailable']);
    }

    public function test_a_body_past_the_cap_is_413_before_anything_is_sent(): void
    {
        Http::fake();

        $this->send(str_repeat(' ', Mismatch::MAX_BODY_BYTES + 1))->assertStatus(413);

        Http::assertNothingSent();
    }

    /** @return array<string, array{mixed}> */
    public static function broken(): array
    {
        $ok = self::report();

        return [
            'not json' => ['nonsense'],
            'a list' => [[1]],
            'sha256 upper case' => [[...$ok, 'sha256' => str_repeat('AB', 32)]],
            'found missing' => [['sha256' => $ok['sha256']]],
            'found a list' => [[...$ok, 'found' => ['x']]],
            'found empty' => [[...$ok, 'found' => (object) []]],
            'found an unknown field' => [[...$ok, 'found' => ['author' => 'x']]],
            'found kind unknown' => [[...$ok, 'found' => ['kind' => 'mod']]],
            'found version a number' => [[...$ok, 'found' => ['version' => 2]]],
            'found name too long' => [[...$ok, 'found' => ['internal_name' => str_repeat('a', 257)]]],
            'found name null' => [[...$ok, 'found' => ['internal_name' => null]]],
        ];
    }

    #[DataProvider('broken')]
    public function test_a_broken_report_is_400_and_nothing_is_sent(mixed $body): void
    {
        Http::fake();

        $this->send($body)->assertStatus(400)->assertJson(['reason' => 'bad_request']);

        Http::assertNothingSent();
    }
}
