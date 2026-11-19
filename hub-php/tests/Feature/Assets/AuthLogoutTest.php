<?php

namespace Tests\Feature\Assets;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Ending a Supabase session from Coilbox, and from the browser's sign out. */
class AuthLogoutTest extends TestCase
{
    private const URL = 'https://sb.test';

    private const TOKEN = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ1In0.c2lnbmF0dXJl';

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => self::URL]);
    }

    private function logoutAnswers(int $status, array $body = []): void
    {
        Http::fake([self::URL.'/auth/v1/logout*' => Http::response($body ?: null, $status)]);
    }

    private function logout(?string $token = self::TOKEN)
    {
        return $this->postJson('/api/v1/auth/logout', [], $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
    }

    public function test_a_valid_token_ends_its_session(): void
    {
        $this->logoutAnswers(204);

        $this->logout()->assertNoContent()->assertHeader('Access-Control-Allow-Origin', '*');

        Http::assertSent(fn (Request $sent) => $sent->method() === 'POST'
            && $sent->url() === self::URL.'/auth/v1/logout?scope=local'
            && $sent->hasHeader('Authorization', 'Bearer '.self::TOKEN));
    }

    public function test_a_session_that_is_already_gone_is_a_204(): void
    {
        $this->logoutAnswers(403, ['code' => 403, 'error_code' => 'session_not_found', 'msg' => 'Session from session_id claim in JWT does not exist']);

        $this->logout()->assertNoContent();
    }

    public function test_an_expired_token_is_a_401_asking_for_a_fresh_one(): void
    {
        $this->logoutAnswers(403, ['code' => 403, 'error_code' => 'bad_jwt', 'msg' => 'invalid JWT: unable to parse or verify signature, token has invalid claims: token is expired']);

        $this->logout()->assertStatus(401)->assertExactJson(['error' => 'That access token has expired. Refresh it and send the new one.']);
    }

    public function test_no_token_or_a_malformed_one_is_a_401_without_asking_supabase(): void
    {
        $this->logoutAnswers(204);

        foreach ([null, 'not-a-token', 'a.b', 'a.b.c.d', 'a.b.c d'] as $token) {
            $this->logout($token)->assertStatus(401)->assertExactJson(['error' => 'Send an access token as "Authorization: Bearer <token>".']);
        }
        Http::assertNothingSent();
    }

    public function test_a_failing_sign_in_service_is_a_502(): void
    {
        $this->logoutAnswers(500, ['msg' => 'down']);

        $this->logout()->assertStatus(502)->assertExactJson(['error' => 'Could not reach the sign in service. Try again.']);
    }

    public function test_the_logout_endpoint_answers_a_preflight_and_is_in_the_auth_document(): void
    {
        $this->call('OPTIONS', '/api/v1/auth/logout')->assertNoContent()->assertHeader('Access-Control-Allow-Origin', '*');
        $this->getJson('/api/v1/auth')->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('endpoints.logout', '/api/v1/auth/logout');
    }

    public function test_the_browser_sign_out_ends_the_supabase_session(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->logoutAnswers(204);

        $this->withSession(['supabase' => ['access_token' => 'live', 'refresh_token' => 'r', 'expires_at' => time() + 3600, 'user_id' => 'u', 'aal' => 'aal1']])
            ->post('/auth/signout')->assertRedirect('/');

        Http::assertSent(fn (Request $sent) => str_contains($sent->url(), '/auth/v1/logout') && $sent->hasHeader('Authorization', 'Bearer live'));
    }

    public function test_the_browser_sign_out_refreshes_an_expired_token_before_ending_the_session(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Http::fake([
            self::URL.'/auth/v1/token*' => Http::response(['access_token' => 'fresh', 'refresh_token' => 'r2', 'expires_in' => 3600]),
            self::URL.'/auth/v1/logout*' => Http::response(null, 204),
        ]);

        $this->withSession(['supabase' => ['access_token' => 'stale', 'refresh_token' => 'r', 'expires_at' => time() - 60, 'user_id' => 'u', 'aal' => 'aal1']])
            ->post('/auth/signout')->assertRedirect('/');

        Http::assertSent(fn (Request $sent) => str_contains($sent->url(), 'grant_type=refresh_token') && $sent->data()['refresh_token'] === 'r');
        Http::assertSent(fn (Request $sent) => str_contains($sent->url(), '/auth/v1/logout') && $sent->hasHeader('Authorization', 'Bearer fresh'));
    }

    public function test_the_browser_is_signed_out_even_when_supabase_fails(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->logoutAnswers(500);

        $this->withSession(['supabase' => ['access_token' => 'live', 'refresh_token' => 'r', 'expires_at' => time() + 3600, 'user_id' => 'u', 'aal' => 'aal1']])
            ->post('/auth/signout')->assertRedirect('/')->assertSessionMissing('supabase');
    }
}
