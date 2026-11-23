<?php

namespace Tests\Feature;

use App\Auth\AuthUsers;
use App\Auth\Factors;
use App\Auth\QrCode;
use App\Auth\Totp;
use App\Supabase\Local\Caller;
use App\Supabase\Local\ItemFunctions;
use App\Supabase\Supabase;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/** The browser sign in once it runs on the hub (#602, part 3b). */
class HubSignInTest extends TestCase
{
    use RefreshDatabase;

    private const ALICE = 'aaaaaaaa-0000-4000-8000-00000000000a';

    private const BOB = 'bbbbbbbb-0000-4000-8000-00000000000b';

    /** The provider id each test signs in as. */
    private const PROVIDER_ID = '4242';

    /** @var list<RequestInterface> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.games_store' => 'mariadb',
            'hub.pictures_store' => 'mariadb',
            'hub.maps_store' => 'mariadb',
            'hub.mirrors_store' => 'mariadb',
            'hub.items_store' => 'mariadb',
            'hub.signin_store' => 'mariadb',
            'services.discord' => ['client_id' => 'discord-id', 'client_secret' => 'discord-secret'],
            'services.github' => ['client_id' => 'github-id', 'client_secret' => 'github-secret'],
            'services.twitch' => ['client_id' => 'twitch-id', 'client_secret' => 'twitch-secret'],
        ]);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        DB::table('auth_user')->delete();
    }

    /** @return array<string, array{string}> */
    public static function providers(): array
    {
        return ['discord' => ['discord'], 'github' => ['github'], 'twitch' => ['twitch']];
    }

    /** @return array<string, array{string, string}> */
    public static function authorizeUrls(): array
    {
        return [
            'discord' => ['discord', 'https://discord.com/api/oauth2/authorize'],
            'github' => ['github', 'https://github.com/login/oauth/authorize'],
            'twitch' => ['twitch', 'https://id.twitch.tv/oauth2/authorize'],
        ];
    }

    #[DataProvider('authorizeUrls')]
    public function test_sign_in_goes_to_the_provider_with_the_hubs_callback(string $provider, string $authorize): void
    {
        $response = $this->post('/auth/signin', ['provider' => $provider, 'next' => '/publish'])->assertStatus(303);
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith($authorize.'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame("http://localhost/auth/{$provider}/callback", $query['redirect_uri']);
        $this->assertSame("{$provider}-id", $query['client_id']);
        $this->assertSame(40, strlen($query['state']));
    }

    #[DataProvider('providers')]
    public function test_with_the_switch_off_sign_in_still_goes_to_supabase(string $provider): void
    {
        config(['hub.signin_store' => 'supabase']);

        $location = (string) $this->post('/auth/signin', ['provider' => $provider])->assertStatus(303)->headers->get('Location');

        $this->assertStringStartsWith('https://project.supabase.test/auth/v1/authorize?provider='.$provider.'&', $location);
        $this->assertStringContainsString(rawurlencode('http://localhost/auth/callback?provider='.$provider), $location);
    }

    public function test_sign_in_is_paused_while_the_accounts_are_copied(): void
    {
        config(['hub.signin_store' => 'frozen']);

        $this->post('/auth/signin', ['provider' => 'github'])->assertStatus(503);
    }

    public function test_a_provider_without_its_app_set_up_is_refused(): void
    {
        config(['services.github' => ['client_id' => null, 'client_secret' => null]]);

        $this->post('/auth/signin', ['provider' => 'github'])->assertStatus(503);
    }

    public function test_a_provider_without_its_app_set_up_is_not_offered(): void
    {
        config([
            'hub.supabase.url' => 'https://sb.test',
            'hub.supabase.publishable_key' => 'sb_publishable_test',
            'services.twitch' => ['client_id' => null, 'client_secret' => null],
        ]);

        $this->get('/sign-in')->assertOk()->assertSee('Sign in with GitHub')->assertDontSee('Sign in with Twitch');
        $this->getJson('/api/v1/auth')->assertOk()->assertJsonPath('providers', ['discord', 'github']);
    }

    public function test_a_linked_provider_that_is_no_longer_set_up_can_still_be_unlinked_but_not_linked_again(): void
    {
        $this->account(self::ALICE, 'alice@example.test', [['github', '1'], ['twitch', '2']]);
        DB::table('profile')->insert(['user_id' => self::ALICE, 'display_name' => 'Alice', 'created_at' => now(), 'updated_at' => now()]);
        config([
            'services.twitch' => ['client_id' => null, 'client_secret' => null],
            'services.discord' => ['client_id' => null, 'client_secret' => null],
        ]);

        $this->asAlice()->get('/account')->assertOk()->assertSee('Twitch')->assertDontSee('Link Discord');
    }

    #[DataProvider('providers')]
    public function test_a_returning_identity_signs_in_to_its_existing_account(string $provider): void
    {
        $this->account(self::ALICE, 'alice@example.test', [[$provider, self::PROVIDER_ID]]);
        DB::table('profile')->insert(['user_id' => self::ALICE, 'display_name' => 'Alice', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_capability')->insert(['user_id' => self::ALICE, 'capability' => 'can_moderate', 'granted_at' => now()]);

        $this->signInWith($provider, ['email' => 'someone-else@example.test'], '/maps')->assertRedirect('/maps');

        $this->assertSame(self::ALICE, session('hub.user_id'));
        $this->assertSame(['id' => self::ALICE, 'name' => 'Alice', 'moderation' => 'granted'], session('nav'));
        $this->assertSame(1, DB::table('auth_user')->count());
        $this->assertSame(1, DB::table('profile')->count());
        $identity = DB::table('auth_identity')->where('user_id', self::ALICE)->first();
        $data = json_decode($identity->identity_data, true);
        // Kept from what Supabase stored.
        $this->assertSame('kept', $data['from_supabase']);
        $this->assertSame(self::PROVIDER_ID, $data['provider_id']);
        $this->assertNotNull($identity->last_sign_in_at);
    }

    #[DataProvider('providers')]
    public function test_a_new_identity_gets_a_new_account_and_profile(string $provider): void
    {
        $this->signInWith($provider, ['email' => 'new@example.test'], '/publish')->assertRedirect('/publish');

        $userId = session('hub.user_id');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $userId);
        $user = DB::table('auth_user')->where('id', $userId)->first();
        $this->assertSame('new@example.test', $user->email);
        $this->assertNotNull($user->email_confirmed_at);
        $this->assertSame([$provider], json_decode($user->app_metadata, true)['providers']);
        $this->assertSame(self::PROVIDER_ID, DB::table('auth_identity')->where('user_id', $userId)->value('provider_id'));
        $this->assertSame('Pat Person', DB::table('profile')->where('user_id', $userId)->value('display_name'));
        $this->assertSame('Pat Person', session('nav.name'));
    }

    #[DataProvider('providers')]
    public function test_a_tampered_state_is_refused(string $provider): void
    {
        $this->post('/auth/signin', ['provider' => $provider])->assertStatus(303);
        $this->fakeProvider($provider, []);

        $this->get("/auth/{$provider}/callback?code=c&state=".str_repeat('x', 40))
            ->assertRedirect('/?error=sign_in_failed&provider='.$provider);

        $this->assertNull(session('hub'));
        $this->assertSame([], $this->sent);
        $this->assertSame(0, DB::table('auth_user')->count());
    }

    public function test_a_callback_this_browser_did_not_start_is_refused(): void
    {
        $this->fakeProvider('github', []);

        $this->get('/auth/github/callback?code=c&state=abc')->assertRedirect('/?error=no_code');
        $this->assertSame([], $this->sent);
    }

    public function test_a_callback_for_another_provider_is_refused(): void
    {
        $state = $this->stateFrom($this->post('/auth/signin', ['provider' => 'github']));
        $this->fakeProvider('discord', []);

        $this->get('/auth/discord/callback?code=c&state='.$state)->assertRedirect('/?error=no_code');
        $this->assertSame([], $this->sent);
    }

    public function test_a_declined_sign_in_goes_back_with_an_error(): void
    {
        $state = $this->stateFrom($this->post('/auth/signin', ['provider' => 'github']));

        $this->get('/auth/github/callback?error=access_denied&state='.$state)->assertRedirect('/?error=sign_in_failed&provider=github');
        $this->assertNull(session('hub'));
    }

    public function test_a_verified_email_joins_the_existing_account(): void
    {
        $this->account(self::ALICE, 'Alice@Example.test', [['discord', '1']]);

        $this->signInWith('github', ['email' => 'alice@example.test'])->assertRedirect('/');

        $this->assertSame(self::ALICE, session('hub.user_id'));
        $this->assertEqualsCanonicalizing(['discord', 'github'], DB::table('auth_identity')->where('user_id', self::ALICE)->pluck('provider')->all());
    }

    public function test_an_unverified_discord_email_does_not_join_an_account(): void
    {
        $this->account(self::ALICE, 'alice@example.test', [['github', '1']]);

        $this->signInWith('discord', ['email' => 'alice@example.test', 'verified' => false])->assertRedirect('/');

        $this->assertNotSame(self::ALICE, session('hub.user_id'));
        $this->assertSame(2, DB::table('auth_user')->count());
        $this->assertNull(DB::table('auth_user')->where('id', session('hub.user_id'))->value('email_confirmed_at'));
    }

    public function test_an_email_two_accounts_share_joins_neither(): void
    {
        $this->account(self::ALICE, 'same@example.test', [['github', '1']]);
        $this->account(self::BOB, 'same@example.test', [['twitch', '2']]);

        $this->signInWith('discord', ['email' => 'same@example.test'])->assertRedirect('/');

        $this->assertNotContains(session('hub.user_id'), [self::ALICE, self::BOB]);
    }

    public function test_a_banned_account_cannot_sign_in(): void
    {
        $this->account(self::ALICE, 'alice@example.test', [['github', self::PROVIDER_ID]], ['banned_until' => '2999-01-01 00:00:00.000000']);

        $this->signInWith('github', [])->assertRedirect('/?error=sign_in_failed&provider=github');
        $this->assertNull(session('hub'));
    }

    public function test_the_redirect_after_sign_in_stays_on_the_site(): void
    {
        $this->signInWith('github', [], '//evil.example/x')->assertRedirect('/');
    }

    public function test_a_session_from_before_the_switch_keeps_its_account(): void
    {
        $this->account(self::ALICE, 'alice@example.test', [['github', '1']]);
        DB::table('profile')->insert(['user_id' => self::ALICE, 'display_name' => 'Alice', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake();

        $this->withSession(['supabase' => ['access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => time() - 10, 'user_id' => self::ALICE, 'aal' => 'aal1']])
            ->get('/account')->assertOk()->assertSee('Alice');

        $this->assertSame(self::ALICE, session('hub.user_id'));
        $this->assertNull(session('supabase'));
        Http::assertNothingSent();
    }

    public function test_a_deleted_account_is_signed_out(): void
    {
        $this->withSession(['hub' => ['user_id' => self::ALICE, 'aal' => 'aal1']])->get('/account')->assertRedirect('/publish');
        $this->assertNull(session('hub'));
    }

    public function test_after_going_back_to_supabase_a_hub_session_is_signed_out(): void
    {
        $this->account(self::ALICE, 'alice@example.test', [['github', '1']]);
        config(['hub.signin_store' => 'supabase']);

        $this->withSession(['hub' => ['user_id' => self::ALICE, 'aal' => 'aal1'], 'nav' => ['id' => self::ALICE, 'name' => 'A', 'moderation' => 'none']])
            ->get('/account')->assertRedirect('/publish');
        $this->assertNull(session('hub'));
    }

    public function test_the_account_page_lists_the_hubs_providers_without_asking_supabase(): void
    {
        $this->account(self::ALICE, 'alice@example.test', [['github', '1'], ['discord', '2']]);
        DB::table('profile')->insert(['user_id' => self::ALICE, 'display_name' => 'Alice', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake();

        $this->asAlice()->get('/account')->assertOk()->assertSee('GitHub')->assertSee('Discord')->assertSee('Twitch');
        Http::assertNothingSent();
    }

    public function test_sign_out_ends_a_hub_session_without_asking_supabase(): void
    {
        $this->account(self::ALICE, 'alice@example.test', [['github', '1']]);
        Http::fake();

        $this->asAlice()->post('/auth/signout')->assertRedirect('/');
        $this->assertNull(session('hub'));
        Http::assertNothingSent();
    }

    public function test_linking_adds_a_provider_to_the_signed_in_account(): void
    {
        $this->account(self::ALICE, 'alice@example.test', [['github', '1']]);

        $state = $this->stateFrom($this->asAlice()->post('/auth/link', ['provider' => 'twitch']));
        $this->fakeProvider('twitch', ['email' => 'other@example.test']);
        $this->get('/auth/twitch/callback?code=c&state='.$state)->assertRedirect('/account#sign-in-methods');

        $this->assertSame(self::PROVIDER_ID, DB::table('auth_identity')->where('user_id', self::ALICE)->where('provider', 'twitch')->value('provider_id'));
        $this->assertEqualsCanonicalizing(['github', 'twitch'], json_decode(DB::table('auth_user')->where('id', self::ALICE)->value('app_metadata'), true)['providers']);
        $this->assertSame(self::ALICE, session('hub.user_id'));
    }

    public function test_linking_a_provider_account_another_account_holds_is_refused(): void
    {
        $this->account(self::ALICE, 'alice@example.test', [['github', '1']]);
        $this->account(self::BOB, 'bob@example.test', [['twitch', self::PROVIDER_ID]]);

        $state = $this->stateFrom($this->asAlice()->post('/auth/link', ['provider' => 'twitch']));
        $this->fakeProvider('twitch', []);
        $this->get('/auth/twitch/callback?code=c&state='.$state)
            ->assertRedirect('/account?link_failed=twitch&link_reason=identity_already_exists#sign-in-methods');

        $this->assertSame(self::BOB, DB::table('auth_identity')->where('provider', 'twitch')->value('user_id'));
    }

    public function test_unlinking_removes_one_of_several_providers(): void
    {
        $this->account(self::ALICE, 'alice@example.test', [['github', '1'], ['discord', '2']]);
        $discord = DB::table('auth_identity')->where('provider', 'discord')->value('id');

        $this->asAlice()->post('/account/unlink', ['identityId' => $discord])->assertRedirect('/account#sign-in-methods')->assertSessionMissing('unlink');

        $this->assertSame(['github'], DB::table('auth_identity')->where('user_id', self::ALICE)->pluck('provider')->all());
    }

    public function test_the_last_provider_cannot_be_unlinked(): void
    {
        $this->account(self::ALICE, 'alice@example.test', [['github', '1']]);
        $github = DB::table('auth_identity')->value('id');

        $this->asAlice()->post('/account/unlink', ['identityId' => $github])->assertSessionHas('unlink.error');
        $this->assertSame(1, DB::table('auth_identity')->count());
    }

    public function test_an_authenticator_is_set_up_checked_and_needed_for_moderation(): void
    {
        $this->account(self::ALICE, 'alice@example.test', [['github', '1']]);
        DB::table('user_capability')->insert(['user_id' => self::ALICE, 'capability' => 'can_moderate', 'granted_at' => now()]);

        $this->asAlice()->post('/account/factor/start')->assertRedirect('/account#second-factor');
        $enrolment = session('enrolment');
        $this->assertStringStartsWith('otpauth://totp/', $enrolment['uri']);
        $this->post('/account/factor/confirm', ['factorId' => $enrolment['factorId'], 'code' => '000000'])->assertSessionHas('confirm.error');
        $this->post('/account/factor/confirm', ['factorId' => $enrolment['factorId'], 'code' => self::codeFor($enrolment['secret'])])
            ->assertSessionHas('confirm.done', true);
        $this->assertSame('aal2', session('hub.aal'));
        $this->assertSame('verified', DB::table('auth_factor')->value('status'));
        $this->assertNotSame($enrolment['secret'], DB::table('auth_factor')->value('secret'));

        // A fresh session has to enter a code before it may moderate.
        $this->flushSession();
        $this->asAlice();
        $this->get('/moderation')->assertRedirect('/account/verify?next=%2Fmoderation');
        $factorId = $enrolment['factorId'];
        $this->post('/account/verify', ['factorId' => $factorId, 'code' => '111111', 'next' => '/moderation'])->assertSessionHas('verify.error');
        // The code the setup used is spent.
        $this->post('/account/verify', ['factorId' => $factorId, 'code' => self::codeFor($enrolment['secret']), 'next' => '/moderation'])->assertSessionHas('verify.error');
        DB::table('auth_factor')->update(['last_step' => Totp::step(time()) - 5]);
        $this->post('/account/verify', ['factorId' => $factorId, 'code' => self::codeFor($enrolment['secret']), 'next' => '/moderation'])->assertRedirect('/moderation');
        $this->assertSame('aal2', session('hub.aal'));
        $this->assertSame('granted', session('nav.moderation'));
    }

    public function test_setting_up_an_authenticator_shows_a_qr_code_and_keeps_the_link_and_key(): void
    {
        $this->account(self::ALICE, 'alice@example.test', [['github', '1']]);
        DB::table('profile')->insert(['user_id' => self::ALICE, 'display_name' => 'Alice', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_capability')->insert(['user_id' => self::ALICE, 'capability' => 'can_moderate', 'granted_at' => now()]);

        $this->asAlice()->post('/account/factor/start')->assertRedirect('/account#second-factor');
        $enrolment = session('enrolment');

        $this->assertSame(QrCode::dataUri($enrolment['uri']), $enrolment['qrCode']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $enrolment['qrCode']);

        $this->get('/account')->assertOk()
            ->assertSee('src="'.$enrolment['qrCode'].'"', false)
            ->assertSee('href="'.e($enrolment['uri']).'"', false)
            ->assertSee($enrolment['secret']);
    }

    public function test_removing_an_authenticator_needs_a_code_this_session(): void
    {
        $this->account(self::ALICE, 'alice@example.test', [['github', '1']]);
        $started = Factors::start(self::ALICE, 'Alice');
        DB::table('auth_factor')->update(['status' => 'verified']);

        $this->asAlice()->post('/account/factor/remove', ['factorId' => $started['factorId']])->assertSessionHas('remove.error');
        $this->asAlice('aal2')->post('/account/factor/remove', ['factorId' => $started['factorId']])->assertSessionMissing('remove');
        $this->assertSame(0, DB::table('auth_factor')->count());
    }

    public function test_deleting_a_hub_made_account_is_not_stopped_by_supabase_not_having_it(): void
    {
        $this->account(self::ALICE, 'alice@example.test', [['github', '1']]);
        DB::table('profile')->insert(['user_id' => self::ALICE, 'display_name' => 'Alice', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(['https://project.supabase.test/auth/v1/admin/users/*' => Http::response(['msg' => 'User not found'], 404)]);

        $this->asAlice()->post('/account/delete', ['confirm' => 'Alice'])->assertRedirect('/?deleted=1');

        $this->assertSame(0, DB::table('auth_user')->count());
        $this->assertNull(session('hub'));
    }

    public function test_a_supabase_account_seen_through_coilbox_never_takes_a_hub_accounts_provider(): void
    {
        $this->account(self::ALICE, 'alice@example.test', [['github', self::PROVIDER_ID]]);

        AuthUsers::keep([
            'id' => self::BOB,
            'email' => 'bob@example.test',
            'identities' => [
                ['identity_id' => 'eeeeeeee-0000-4000-8000-00000000000e', 'id' => self::PROVIDER_ID, 'provider' => 'github', 'identity_data' => []],
                ['identity_id' => 'ffffffff-0000-4000-8000-00000000000f', 'id' => '77', 'provider' => 'discord', 'identity_data' => []],
            ],
        ]);

        $this->assertSame(self::ALICE, DB::table('auth_identity')->where('provider', 'github')->value('user_id'));
        $this->assertSame(self::BOB, DB::table('auth_identity')->where('provider', 'discord')->value('user_id'));
        $this->assertSame(2, DB::table('auth_user')->count());
    }

    public function test_codes_are_limited_per_account(): void
    {
        $this->account(self::ALICE, 'alice@example.test', [['github', '1']]);
        $this->account(self::BOB, 'bob@example.test', [['twitch', '2']]);
        $started = Factors::start(self::ALICE, 'Alice');
        DB::table('auth_factor')->update(['status' => 'verified']);

        for ($i = 0; $i < 30; $i++) {
            $this->asAlice()->post('/account/verify', ['factorId' => $started['factorId'], 'code' => '000000'])->assertRedirect();
        }
        $this->asAlice()->post('/account/verify', ['factorId' => $started['factorId'], 'code' => '000000'])->assertStatus(429);
        $this->asAlice()->post('/account/factor/confirm', ['factorId' => $started['factorId'], 'code' => '000000'])->assertStatus(429);
        // Another account from the same address is not held back.
        $this->withSession(['hub' => ['user_id' => self::BOB, 'aal' => 'aal1']])
            ->post('/account/verify', ['code' => '000000'])->assertRedirect();
    }

    public function test_a_ban_ends_the_session_and_stops_sign_in(): void
    {
        $this->account(self::ALICE, 'alice@example.test', [['github', self::PROVIDER_ID]]);
        config(['hub.signin_store' => null]);
        DB::table('data_move')->insert(['area' => 'signin', 'state' => 'mariadb', 'changed_at' => '2026-11-20 00:00:00.000000']);

        $this->artisan('users:ban', ['user' => 'ALICE@example.test', '--dry-run' => true])
            ->expectsOutput('Would ban until 9999-12-31 23:59:59.000000 UTC.')->assertExitCode(0);
        $this->assertNull(DB::table('auth_user')->value('banned_until'));

        $this->artisan('users:ban', ['user' => self::ALICE, '--until' => '2999-01-31'])->assertExitCode(0);
        $this->assertStringStartsWith('2999-01-31 00:00:00', (string) DB::table('auth_user')->value('banned_until'));
        $this->asAlice()->get('/account')->assertRedirect('/publish');
        $this->assertNull(session('hub'));
        $this->signInWith('github', [])->assertRedirect('/?error=sign_in_failed&provider=github');

        $this->artisan('users:ban', ['user' => self::ALICE, '--lift' => true])->expectsOutput('The ban is lifted.')->assertExitCode(0);
        $this->assertNull(DB::table('auth_user')->value('banned_until'));
        $this->artisan('users:ban', ['user' => self::ALICE, '--until' => '2001-01-01'])->assertExitCode(1);
        $this->artisan('users:ban', ['user' => 'nobody@example.test'])->expectsOutput('No account has that id or email.')->assertExitCode(1);
    }

    public function test_banning_on_the_hub_waits_for_the_switch(): void
    {
        $this->account(self::ALICE, 'alice@example.test', [['github', '1']]);
        config(['hub.signin_store' => 'supabase']);

        $this->artisan('users:ban', ['user' => self::ALICE])->expectsOutputToContain('Ban the account in the Supabase dashboard')->assertExitCode(1);
        $this->assertNull(DB::table('auth_user')->value('banned_until'));
    }

    public function test_a_ban_made_in_supabase_carries_over_but_never_shortens_one_made_here(): void
    {
        $this->account(self::ALICE, 'alice@example.test', [['github', '1']]);
        $supabase = ['id' => self::ALICE, 'email' => 'alice@example.test', 'banned_until' => '2900-01-01T00:00:00Z', 'identities' => []];

        AuthUsers::keep($supabase);
        $this->assertStringStartsWith('2900-01-01 00:00:00', (string) DB::table('auth_user')->value('banned_until'));

        DB::table('auth_user')->update(['banned_until' => '9999-12-31 23:59:59.000000']);
        AuthUsers::keep($supabase);
        $this->assertStringStartsWith('9999-12-31', (string) DB::table('auth_user')->value('banned_until'));
    }

    public function test_switching_refuses_while_the_environment_fixes_where_sign_in_runs(): void
    {
        $this->artisan('users:copy', ['--switch' => true])->expectsOutputToContain('HUB_SIGNIN_STORE is set to mariadb')->assertExitCode(1);
        $this->artisan('users:store', ['state' => 'supabase'])->expectsOutputToContain('HUB_SIGNIN_STORE is set to mariadb')->assertExitCode(1);
    }

    public function test_a_coilbox_token_still_has_to_enter_a_code_for_a_supabase_authenticator(): void
    {
        $this->account(self::ALICE, 'alice@example.test', [['github', '1']]);
        DB::table('user_capability')->insert(['user_id' => self::ALICE, 'capability' => 'can_moderate', 'granted_at' => now()]);
        Http::fake(['https://project.supabase.test/auth/v1/user' => Http::response(['id' => self::ALICE, 'factors' => [['id' => 'f', 'status' => 'verified', 'factor_type' => 'totp']]])]);

        $bearer = new Caller(Supabase::forToken('header.'.rtrim(strtr(base64_encode('{"aal":"aal1"}'), '+/', '-_'), '=').'.sig', self::ALICE));
        $this->assertSame('verify', ItemFunctions::capabilityAccess($bearer, 'can_moderate'));

        $session = new Caller(Supabase::forUser(self::ALICE, 'aal1'));
        $this->assertSame('granted', ItemFunctions::capabilityAccess($session, 'can_moderate'));
    }

    /**
     * Starts a sign in, then comes back from the provider as somebody with
     * provider id {@see PROVIDER_ID}.
     *
     * @param  array<string, mixed>  $person
     */
    private function signInWith(string $provider, array $person, string $next = '/'): TestResponse
    {
        $state = $this->stateFrom($this->post('/auth/signin', ['provider' => $provider, 'next' => $next]));
        $this->fakeProvider($provider, $person);

        return $this->get("/auth/{$provider}/callback?code=c&state=".$state);
    }

    private function stateFrom(TestResponse $response): string
    {
        $response->assertStatus(303);
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    /**
     * Answers the provider's token and profile requests.
     *
     * @param  array<string, mixed>  $person  `email`, and `verified` for Discord
     */
    private function fakeProvider(string $provider, array $person): void
    {
        $email = $person['email'] ?? null;
        $token = new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 'provider-token', 'scope' => '']));
        $responses = match ($provider) {
            'github' => [$token,
                self::reply(['id' => (int) self::PROVIDER_ID, 'node_id' => 'n', 'login' => 'pat', 'name' => 'Pat Person', 'avatar_url' => 'https://avatars.example.test/p']),
                self::reply($email === null ? [] : [['email' => $email, 'primary' => true, 'verified' => true]]),
            ],
            'discord' => [$token,
                self::reply(['id' => self::PROVIDER_ID, 'username' => 'Pat Person', 'discriminator' => '0', 'global_name' => 'Pat', 'avatar' => null, 'email' => $email, 'verified' => $person['verified'] ?? true]),
            ],
            'twitch' => [$token,
                self::reply(['data' => [['id' => self::PROVIDER_ID, 'login' => 'pat', 'display_name' => 'Pat Person', 'profile_image_url' => 'https://img.example.test/p', 'email' => $email]]]),
            ],
        };
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));
        app()->instance('hub.oauth.guzzle', ['handler' => $stack]);
    }

    private static function reply(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function asAlice(string $aal = 'aal1'): static
    {
        return $this->withSession(['hub' => ['user_id' => self::ALICE, 'aal' => $aal]]);
    }

    private static function codeFor(string $secret): string
    {
        return Totp::code(Totp::decode($secret), Totp::step(time()));
    }

    /**
     * An account as the copy from Supabase left it.
     *
     * @param  list<array{string, string}>  $identities  provider and provider id
     * @param  array<string, mixed>  $extra
     */
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
                'identity_data' => json_encode(['from_supabase' => 'kept', 'full_name' => 'Old name']), 'email' => $email,
                'created_at' => '2026-01-01 00:00:0'.$i.'.000000',
            ]);
        }
    }
}
