<?php

namespace Tests\Feature\Assets;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Coilbox signing in and refreshing through the hub instead of Supabase. */
class AuthTokenTest extends TestCase
{
    private const URL = 'https://sb.test';

    private const SESSION = [
        'access_token' => 'access', 'token_type' => 'bearer', 'expires_in' => 3600, 'expires_at' => 1900000000,
        'refresh_token' => 'next-refresh', 'user' => ['id' => 'user-1', 'email' => 'a@example.test'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => self::URL]);
    }

    private function supabaseAnswers(int $status, ?array $body): void
    {
        Http::fake([self::URL.'/auth/v1/token*' => Http::response($body, $status)]);
    }

    public function test_authorize_redirects_to_supabase_with_the_same_query(): void
    {
        $query = 'provider=discord&redirect_to=http%3A%2F%2F127.0.0.1%3A1234%2Fcb&code_challenge=abc-_123&code_challenge_method=s256';

        $this->get('/api/v1/auth/authorize?'.$query)->assertRedirect(self::URL.'/auth/v1/authorize?'.$query);
    }

    public function test_a_pkce_code_is_swapped_for_a_session_in_supabase_shape(): void
    {
        $this->supabaseAnswers(200, self::SESSION);

        $this->postJson('/api/v1/auth/token?grant_type=pkce', ['auth_code' => 'code', 'code_verifier' => 'verifier'])
            ->assertOk()->assertExactJson(self::SESSION)
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Cache-Control', 'no-store, private');

        Http::assertSent(fn (Request $sent) => $sent->url() === self::URL.'/auth/v1/token?grant_type=pkce'
            && $sent->data() === ['auth_code' => 'code', 'code_verifier' => 'verifier']
            && $sent->hasHeader('apikey', 'sb_publishable_test'));
    }

    public function test_a_refresh_token_is_swapped_with_the_grant_in_the_body(): void
    {
        $this->supabaseAnswers(200, self::SESSION);

        $this->postJson('/api/v1/auth/token', ['grant_type' => 'refresh_token', 'refresh_token' => 'old-refresh'])
            ->assertOk()->assertJsonPath('refresh_token', 'next-refresh')->assertJsonPath('user.id', 'user-1');

        Http::assertSent(fn (Request $sent) => $sent->url() === self::URL.'/auth/v1/token?grant_type=refresh_token'
            && $sent->data() === ['refresh_token' => 'old-refresh']);
    }

    public function test_a_bad_code_verifier_passes_supabases_answer_on(): void
    {
        $refusal = ['code' => 400, 'error_code' => 'bad_code_verifier', 'msg' => 'code challenge does not match previously saved code verifier'];
        $this->supabaseAnswers(400, $refusal);

        $this->postJson('/api/v1/auth/token?grant_type=pkce', ['auth_code' => 'code', 'code_verifier' => 'wrong'])
            ->assertStatus(400)->assertExactJson($refusal);
    }

    public function test_a_reused_refresh_token_passes_supabases_answer_on(): void
    {
        $refusal = ['code' => 400, 'error_code' => 'refresh_token_already_used', 'msg' => 'Invalid Refresh Token: Already Used'];
        $this->supabaseAnswers(400, $refusal);

        $this->postJson('/api/v1/auth/token?grant_type=refresh_token', ['refresh_token' => 'used'])
            ->assertStatus(400)->assertExactJson($refusal);
    }

    public function test_a_missing_or_unknown_grant_or_field_is_a_400_without_asking_supabase(): void
    {
        $this->supabaseAnswers(200, self::SESSION);

        $this->postJson('/api/v1/auth/token', [])->assertStatus(400)->assertExactJson(['error' => 'grant_type must be "pkce" or "refresh_token".']);
        $this->postJson('/api/v1/auth/token?grant_type=password', ['email' => 'a', 'password' => 'b'])->assertStatus(400);
        $this->postJson('/api/v1/auth/token?grant_type=pkce', ['auth_code' => 'code'])
            ->assertStatus(400)->assertExactJson(['error' => '"code_verifier" must be a non-empty string.']);
        $this->postJson('/api/v1/auth/token?grant_type=refresh_token', ['refresh_token' => ['x']])->assertStatus(400);
        Http::assertNothingSent();
    }

    public function test_a_failing_or_unreachable_sign_in_service_is_a_502(): void
    {
        $this->supabaseAnswers(500, ['msg' => 'down']);
        $this->postJson('/api/v1/auth/token?grant_type=refresh_token', ['refresh_token' => 'r'])
            ->assertStatus(502)->assertExactJson(['error' => 'Could not reach the sign in service. Try again.']);

        Http::fake(fn () => throw new ConnectionException('timed out'));
        $this->postJson('/api/v1/auth/token?grant_type=refresh_token', ['refresh_token' => 'r'])->assertStatus(502);
    }

    public function test_an_address_is_limited_to_30_code_swaps_in_5_minutes_and_refreshes_count_apart(): void
    {
        $this->supabaseAnswers(200, self::SESSION);
        $swap = fn () => $this->postJson('/api/v1/auth/token?grant_type=pkce', ['auth_code' => 'c', 'code_verifier' => 'v']);

        for ($i = 0; $i < 30; $i++) {
            $swap()->assertOk();
        }
        $swap()->assertStatus(429)->assertHeader('Retry-After')
            ->assertExactJson(['error' => 'Too many sign in requests from this address. Try again in a few minutes.']);
        $this->postJson('/api/v1/auth/token?grant_type=refresh_token', ['refresh_token' => 'r'])->assertOk();

        $this->travel(5)->minutes();
        $swap()->assertOk();
    }

    public function test_an_address_is_limited_to_150_refreshes_in_5_minutes(): void
    {
        $this->supabaseAnswers(200, self::SESSION);
        $refresh = fn () => $this->postJson('/api/v1/auth/token?grant_type=refresh_token', ['refresh_token' => 'r']);

        for ($i = 0; $i < 150; $i++) {
            $refresh()->assertOk();
        }
        $refresh()->assertStatus(429);
    }

    public function test_the_auth_document_lists_the_endpoints_at_version_1(): void
    {
        $this->call('OPTIONS', '/api/v1/auth/token')->assertNoContent()->assertHeader('Access-Control-Allow-Origin', '*');
        $this->getJson('/api/v1/auth')->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('supabase_url', self::URL)
            ->assertJsonPath('endpoints', ['authorize' => '/api/v1/auth/authorize', 'token' => '/api/v1/auth/token', 'logout' => '/api/v1/auth/logout']);
    }
}
