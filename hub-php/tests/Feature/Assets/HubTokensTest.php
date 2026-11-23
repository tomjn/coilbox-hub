<?php

namespace Tests\Feature\Assets;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Coilbox signing in with the hub's own tokens (#602, part 3c). */
class HubTokensTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://sb.test';

    private const ALICE = 'aaaaaaaa-0000-4000-8000-00000000000a';

    private const DUPLICATE = 'dddddddd-0000-4000-8000-00000000000d';

    private const PROVIDER_ID = '4242';

    private const LOOPBACK = 'http://127.0.0.1:41234/oauth2callback?state=coilbox-state';

    private const VERIFIER = 'a-verifier-that-is-long-enough-for-pkce-0123456789';

    /** The fields of Supabase's `/auth/v1/token` answer, in its order (#592). */
    private const ANSWER = ['access_token', 'token_type', 'expires_in', 'expires_at', 'refresh_token', 'user'];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.supabase.url' => self::URL,
            'hub.games_store' => 'mariadb',
            'hub.pictures_store' => 'mariadb',
            'hub.maps_store' => 'mariadb',
            'hub.mirrors_store' => 'mariadb',
            'hub.items_store' => 'mariadb',
            'hub.signin_store' => 'mariadb',
            'hub.tokens_store' => 'mariadb',
            'services.discord' => ['client_id' => 'discord-id', 'client_secret' => 'discord-secret'],
            'services.github' => ['client_id' => 'github-id', 'client_secret' => 'github-secret'],
            'services.twitch' => ['client_id' => 'twitch-id', 'client_secret' => 'twitch-secret'],
            'services.google' => ['client_id' => 'google-id', 'client_secret' => 'google-secret'],
        ]);
        DB::table('auth_user')->delete();
        $this->account(self::ALICE, 'alice@example.test', [['github', self::PROVIDER_ID]]);
        DB::table('profile')->insert(['user_id' => self::ALICE, 'display_name' => 'Alice', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, array{string, string}> */
    public static function providers(): array
    {
        return [
            'discord' => ['discord', 'https://discord.com/api/oauth2/authorize'],
            'github' => ['github', 'https://github.com/login/oauth/authorize'],
            'google' => ['google', 'https://accounts.google.com/o/oauth2/auth'],
            'twitch' => ['twitch', 'https://id.twitch.tv/oauth2/authorize'],
        ];
    }

    #[DataProvider('providers')]
    public function test_authorize_goes_to_the_provider_with_the_registered_callback(string $provider, string $authorize): void
    {
        $location = (string) $this->authorize($provider)->assertStatus(302)->headers->get('Location');

        $this->assertStringStartsWith($authorize.'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame("http://localhost/auth/{$provider}/callback", $query['redirect_uri']);
        $this->assertSame("{$provider}-id", $query['client_id']);
        $this->assertStringStartsWith('coilbox.', $query['state']);
    }

    public function test_with_the_switch_off_authorize_still_goes_to_supabase(): void
    {
        config(['hub.tokens_store' => 'supabase']);

        $location = (string) $this->authorize('discord')->assertStatus(302)->headers->get('Location');

        $this->assertStringStartsWith(self::URL.'/auth/v1/authorize?provider=discord&', $location);
    }

    public function test_authorize_refuses_another_address_a_bad_challenge_or_an_unknown_provider(): void
    {
        $this->authorize('discord', ['redirect_to' => 'https://evil.example/cb'])->assertStatus(400)->assertJsonPath('error_code', 'validation_failed');
        $this->authorize('discord', ['redirect_to' => 'http://localhost:41234/cb'])->assertStatus(400);
        $this->authorize('discord', ['redirect_to' => 'http://127.0.0.1@evil.example:80/cb'])->assertStatus(400);
        $this->authorize('discord', ['code_challenge' => 'short'])->assertStatus(400);
        $this->authorize('discord', ['code_challenge_method' => 'md5'])->assertStatus(400);
        $this->authorize('discord', ['code_challenge_method' => 'plain'])->assertStatus(400)->assertJsonPath('msg', 'code_challenge_method must be s256');
        $this->authorize('gitlab')->assertStatus(400)->assertExactJson(['code' => 400, 'error_code' => 'validation_failed', 'msg' => 'Unsupported provider: provider is not enabled']);
        config(['services.twitch' => ['client_id' => null, 'client_secret' => null]]);
        $this->authorize('twitch')->assertStatus(400);
        $this->assertSame(0, DB::table('auth_sign_in')->count());
    }

    public function test_a_hub_sign_in_answers_in_the_592_shape_for_the_existing_account(): void
    {
        $code = $this->signInCode('github');

        $response = $this->swapCode($code)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Access-Control-Allow-Origin', '*');
        $body = $response->json();

        $this->assertSame(self::ANSWER, array_keys($body));
        $this->assertMatchesRegularExpression('/\A[0-9]+\|[A-Za-z0-9]{48}\z/', $body['access_token']);
        $this->assertSame('bearer', $body['token_type']);
        $this->assertSame(3600, $body['expires_in']);
        $this->assertEqualsWithDelta(time() + 3600, $body['expires_at'], 5);
        $this->assertStringStartsWith('hubr_', $body['refresh_token']);
        // The account from before the switch, so its items, maps and rights stay its own.
        $this->assertSame(self::ALICE, $body['user']['id']);
        $this->assertSame('alice@example.test', $body['user']['email']);
        $this->assertSame('Pat Person', $body['user']['user_metadata']['full_name']);
        $this->assertSame('github', $body['user']['identities'][0]['provider']);
        $this->assertSame(1, DB::table('auth_user')->count());

        $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer '.$body['access_token']])
            ->assertOk()->assertExactJson(['display_name' => 'Alice']);
    }

    public function test_a_denied_sign_in_goes_back_to_coilbox_with_the_error(): void
    {
        $state = $this->stateFor('github');

        $location = (string) $this->get('/auth/github/callback?error=access_denied&state='.$state)->assertStatus(302)->headers->get('Location');

        $this->assertStringStartsWith(self::LOOPBACK.'&error=access_denied&', $location);
        $this->assertSame(0, DB::table('auth_sign_in')->count());
    }

    public function test_a_callback_with_an_unknown_state_goes_nowhere_near_coilbox(): void
    {
        $this->get('/auth/github/callback?code=c&state=coilbox.made-up')->assertRedirect('/?error=sign_in_failed&provider=github');
    }

    public function test_a_code_works_once_and_only_with_its_verifier(): void
    {
        $code = $this->signInCode('github');

        $this->swapCode($code, 'the-wrong-verifier')->assertStatus(400)
            ->assertExactJson(['code' => 400, 'error_code' => 'bad_code_verifier', 'msg' => 'code challenge does not match previously saved code verifier']);
        $this->swapCode($code)->assertStatus(404)->assertJsonPath('error_code', 'flow_state_not_found');
        // A made up hub code never reaches Supabase.
        Http::fake();
        $this->swapCode('hubc_made-up')->assertStatus(404);
        Http::assertNothingSent();
    }

    public function test_a_code_swapped_too_late_has_expired(): void
    {
        $code = $this->signInCode('github');
        $this->travel(301)->seconds();

        $this->swapCode($code)->assertStatus(422)->assertJsonPath('error_code', 'flow_state_expired');
    }

    public function test_a_refresh_token_rotates_and_a_spent_one_works_only_within_the_grace(): void
    {
        $first = $this->signedIn();

        $second = $this->refresh($first['refresh_token'])->assertOk()->json();
        $this->assertSame(self::ANSWER, array_keys($second));
        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->assertNotSame($first['access_token'], $second['access_token']);
        $this->assertSame(self::ALICE, $second['user']['id']);

        // A second request with the same token, as two refreshes at once send.
        $this->travel(5)->seconds();
        $this->refresh($first['refresh_token'])->assertOk();

        $third = $this->refresh($second['refresh_token'])->assertOk()->json();
        $this->travel(11)->seconds();
        $this->refresh($first['refresh_token'])->assertStatus(400)
            ->assertExactJson(['code' => 400, 'error_code' => 'refresh_token_already_used', 'msg' => 'Invalid Refresh Token: Already Used']);

        // Reuse ends the whole session.
        $this->refresh($third['refresh_token'])->assertStatus(400)->assertJsonPath('error_code', 'refresh_token_not_found');
        $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer '.$third['access_token']])->assertStatus(401);
    }

    public function test_an_old_copy_of_a_spent_refresh_token_ends_the_session_even_before_its_replacement_is_used(): void
    {
        $first = $this->signedIn();
        // The owner refreshes, then closes Coilbox before using the new token.
        $owners = $this->refresh($first['refresh_token'])->assertOk()->json();
        $this->travel(11)->seconds();

        // Somebody with an old copy of the first token gets nothing.
        $this->refresh($first['refresh_token'])->assertStatus(400)->assertJsonPath('error_code', 'refresh_token_already_used');

        // The session is over for everybody, so nobody holds it quietly.
        $this->refresh($owners['refresh_token'])->assertStatus(400)->assertJsonPath('error_code', 'refresh_token_not_found');
        $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer '.$owners['access_token']])->assertStatus(401);
        $this->assertSame(0, DB::table('auth_refresh_token')->count());
    }

    public function test_a_hub_refresh_token_the_hub_does_not_know_is_refused_without_asking_supabase(): void
    {
        Http::fake();

        $this->refresh('hubr_made-up')->assertStatus(400)
            ->assertExactJson(['code' => 400, 'error_code' => 'refresh_token_not_found', 'msg' => 'Invalid Refresh Token: Refresh Token Not Found']);
        Http::assertNothingSent();
    }

    public function test_a_supabase_refresh_token_is_swapped_once_then_refused(): void
    {
        $this->supabaseRefreshes(self::ALICE, [['provider' => 'github', 'id' => self::PROVIDER_ID]]);

        $swapped = $this->refresh('supabase-refresh')->assertOk()->json();

        $this->assertSame(self::ANSWER, array_keys($swapped));
        $this->assertSame(self::ALICE, $swapped['user']['id']);
        $this->assertStringStartsWith('hubr_', $swapped['refresh_token']);
        $this->assertMatchesRegularExpression('/\A[0-9]+\|/', $swapped['access_token']);
        Http::assertSent(fn (Request $sent) => $sent->url() === self::URL.'/auth/v1/token?grant_type=refresh_token'
            && $sent->data() === ['refresh_token' => 'supabase-refresh']);
        // The Supabase session is ended, so its old tokens cannot be used at Supabase either.
        Http::assertSent(fn (Request $sent) => $sent->url() === self::URL.'/auth/v1/logout?scope=local'
            && $sent->hasHeader('Authorization', 'Bearer eyJsupabase.access.token'));

        $this->refresh($swapped['refresh_token'])->assertOk();
        $this->travel(11)->seconds();
        $this->refresh('supabase-refresh')->assertStatus(400)->assertJsonPath('error_code', 'refresh_token_already_used');
        Http::assertSentCount(2);
    }

    public function test_a_supabase_refusal_of_its_own_refresh_token_is_passed_on(): void
    {
        $refusal = ['code' => 400, 'error_code' => 'refresh_token_already_used', 'msg' => 'Invalid Refresh Token: Already Used'];
        Http::fake([self::URL.'/auth/v1/token*' => Http::response($refusal, 400)]);

        $this->refresh('supabase-refresh')->assertStatus(400)->assertExactJson($refusal);
        $this->assertSame(0, DB::table('auth_refresh_token')->count());
    }

    public function test_with_the_switch_off_a_supabase_refresh_token_still_goes_to_supabase(): void
    {
        config(['hub.tokens_store' => 'supabase']);
        $session = ['access_token' => 'eyJa.b.c', 'token_type' => 'bearer', 'expires_in' => 3600, 'expires_at' => 1900000000, 'refresh_token' => 'next', 'user' => ['id' => self::ALICE]];
        Http::fake([self::URL.'/auth/v1/token*' => Http::response($session)]);

        $this->refresh('supabase-refresh')->assertOk()->assertExactJson($session);
        $this->assertSame(0, DB::table('auth_refresh_token')->count());
    }

    public function test_hub_tokens_keep_working_after_switching_back(): void
    {
        $tokens = $this->signedIn();
        config(['hub.tokens_store' => 'supabase']);
        Http::fake();

        $next = $this->refresh($tokens['refresh_token'])->assertOk()->json();
        $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer '.$next['access_token']])->assertOk();
        Http::assertNothingSent();
    }

    public function test_a_coilbox_account_made_through_supabase_after_the_browser_switch_lands_on_the_hub_account(): void
    {
        // The second account the hub added when Coilbox signed in through Supabase.
        $this->account(self::DUPLICATE, 'alice@example.test', [], ['copy_hash' => '']);
        $this->supabaseRefreshes(self::DUPLICATE, [['provider' => 'github', 'id' => self::PROVIDER_ID]]);

        $this->refresh('supabase-refresh')->assertOk()->assertJsonPath('user.id', self::ALICE);

        $this->artisan('users:duplicates')->expectsOutputToContain('added from Coilbox after the browser sign in moved: 1.')
            ->expectsOutputToContain(self::DUPLICATE)->assertExitCode(0);
    }

    public function test_bearer_takes_a_hub_token_or_a_supabase_one(): void
    {
        $tokens = $this->signedIn();
        Http::fake([self::URL.'/auth/v1/user' => Http::response(['id' => self::ALICE, 'email' => 'alice@example.test'])]);

        $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer '.$tokens['access_token']])->assertOk()->assertJsonPath('display_name', 'Alice');
        Http::assertNothingSent();
        $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ4In0.sig'])->assertOk()->assertJsonPath('display_name', 'Alice');
        Http::assertSentCount(1);

        [$id] = explode('|', $tokens['access_token']);
        $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer '.$id.'|forged'])->assertStatus(401)
            ->assertExactJson(['error' => 'That access token is not valid. Sign in again and use a fresh one.']);
    }

    public function test_an_access_token_expires_after_an_hour(): void
    {
        $tokens = $this->signedIn();
        $this->travel(3601)->seconds();

        $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer '.$tokens['access_token']])->assertStatus(401);
        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$tokens['access_token']])->assertStatus(401)
            ->assertExactJson(['error' => 'That access token has expired. Refresh it and send the new one.']);
        // The refresh token does not expire.
        $this->refresh($tokens['refresh_token'])->assertOk();
    }

    public function test_logout_ends_the_session_on_the_hub(): void
    {
        $tokens = $this->signedIn();
        Http::fake();

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$tokens['access_token']])->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', '*');

        $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer '.$tokens['access_token']])->assertStatus(401);
        $this->refresh($tokens['refresh_token'])->assertStatus(400)->assertJsonPath('error_code', 'refresh_token_not_found');
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        Http::assertNothingSent();
    }

    public function test_a_banned_account_loses_its_tokens(): void
    {
        $tokens = $this->signedIn();
        DB::table('auth_user')->where('id', self::ALICE)->update(['banned_until' => '9999-12-31 23:59:59.000000']);

        $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer '.$tokens['access_token']])->assertStatus(401);
        $this->refresh($tokens['refresh_token'])->assertStatus(400)->assertJsonPath('error_code', 'user_banned');
    }

    public function test_tokens_store_switches_only_once_the_browser_sign_in_has_moved(): void
    {
        config(['hub.tokens_store' => null, 'hub.signin_store' => 'supabase']);
        $this->artisan('tokens:store mariadb')->expectsOutputToContain('Run users:copy --switch first.')->assertExitCode(1);

        config(['hub.signin_store' => null]);
        DB::table('data_move')->insert(['area' => 'signin', 'state' => 'mariadb', 'changed_at' => now()]);
        $this->artisan('tokens:store mariadb --dry-run')->expectsOutputToContain('Dry run')->assertExitCode(0);
        $this->assertNull(DB::table('data_move')->where('area', 'tokens')->value('state'));

        $this->artisan('tokens:store mariadb')->expectsOutputToContain('Coilbox now signs in with the hub.')->assertExitCode(0);
        $this->assertSame('mariadb', DB::table('data_move')->where('area', 'tokens')->value('state'));
        $this->artisan('users:store supabase')->expectsOutputToContain('Run tokens:store supabase first.')->assertExitCode(1);

        $this->artisan('tokens:store supabase')->assertExitCode(0);
        $this->assertSame('supabase', DB::table('data_move')->where('area', 'tokens')->value('state'));
    }

    /** @param array<string, string> $override */
    private function authorize(string $provider, array $override = []): TestResponse
    {
        $query = $override + [
            'provider' => $provider,
            'code_challenge_method' => 's256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '='),
            'redirect_to' => self::LOOPBACK,
        ];

        return $this->get('/api/v1/auth/authorize?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    private function stateFor(string $provider): string
    {
        parse_str((string) parse_url((string) $this->authorize($provider)->headers->get('Location'), PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    /** Runs a sign in as far as Coilbox's loopback address, and answers the code it was given. */
    private function signInCode(string $provider): string
    {
        $state = $this->stateFor($provider);
        $this->fakeGithub();
        $location = (string) $this->get("/auth/{$provider}/callback?code=provider-code&state=".rawurlencode($state))
            ->assertStatus(302)->headers->get('Location');
        $this->assertStringStartsWith(self::LOOPBACK.'&code=hubc_', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('coilbox-state', $query['state']);

        return (string) $query['code'];
    }

    /** @return array<string, mixed> */
    private function signedIn(): array
    {
        return $this->swapCode($this->signInCode('github'))->assertOk()->json();
    }

    private function swapCode(string $code, string $verifier = self::VERIFIER): TestResponse
    {
        return $this->postJson('/api/v1/auth/token?grant_type=pkce', ['auth_code' => $code, 'code_verifier' => $verifier]);
    }

    private function refresh(string $token): TestResponse
    {
        return $this->postJson('/api/v1/auth/token?grant_type=refresh_token', ['refresh_token' => $token]);
    }

    private function fakeGithub(): void
    {
        $reply = fn (array $body) => new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
        app()->instance('hub.oauth.guzzle', ['handler' => HandlerStack::create(new MockHandler([
            $reply(['access_token' => 'provider-token', 'scope' => '']),
            $reply(['id' => (int) self::PROVIDER_ID, 'node_id' => 'n', 'login' => 'pat', 'name' => 'Pat Person', 'avatar_url' => 'https://avatars.example.test/p']),
            $reply([['email' => 'alice@example.test', 'primary' => true, 'verified' => true]]),
        ]))]);
    }

    /** @param list<array{provider: string, id: string}> $identities */
    private function supabaseRefreshes(string $userId, array $identities): void
    {
        Http::fake([
            self::URL.'/auth/v1/token*' => Http::response([
                'access_token' => 'eyJsupabase.access.token', 'token_type' => 'bearer', 'expires_in' => 3600, 'expires_at' => 1900000000,
                'refresh_token' => 'supabase-next', 'user' => ['id' => $userId, 'email' => 'alice@example.test', 'identities' => $identities],
            ]),
            self::URL.'/auth/v1/logout*' => Http::response(null, 204),
        ]);
    }

    /** @param list<array{string, string}> $identities */
    private function account(string $id, string $email, array $identities, array $extra = []): void
    {
        DB::table('auth_user')->insert($extra + [
            'id' => $id, 'email' => $email, 'email_confirmed_at' => '2026-01-01 00:00:00.000000',
            'app_metadata' => '{}', 'user_metadata' => '{}', 'is_anonymous' => false,
            'created_at' => '2026-01-01 00:00:00.000000', 'copied_at' => '2026-11-19 00:00:00.000000', 'copy_hash' => str_repeat('0', 40),
        ]);
        foreach ($identities as $i => [$provider, $providerId]) {
            DB::table('auth_identity')->insert([
                'id' => substr($id, 0, 8).'-0000-4000-8000-00000000000'.$i, 'user_id' => $id, 'provider' => $provider, 'provider_id' => $providerId,
                'identity_data' => '{}', 'email' => $email, 'created_at' => '2026-01-01 00:00:00.000000',
            ]);
        }
    }
}
