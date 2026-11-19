<?php

namespace Tests\Feature;

use App\Auth\AuthUsers;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The hub's copy of Supabase Auth accounts and their providers (#602, part 3a). */
class AuthUsersTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://project.supabase.test';

    private const ALICE = 'aaaaaaaa-0000-4000-8000-00000000000a';

    private const BOB = 'bbbbbbbb-0000-4000-8000-00000000000b';

    private const GONE = 'cccccccc-0000-4000-8000-00000000000c';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.supabase.url' => self::URL,
            'hub.supabase.publishable_key' => 'sb_publishable_test',
            'hub.supabase.service_role_key' => 'service-key',
            'hub.items_store' => 'supabase',
        ]);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        // Tests without RefreshDatabase sign in too, and on CI's MariaDB their accounts stay.
        DB::table('auth_user')->delete();
    }

    /** @return array<string, mixed> A user as Supabase Auth gives it. */
    private static function user(string $id, array $identities, array $extra = []): array
    {
        return $extra + [
            'id' => $id,
            'aud' => 'authenticated',
            'role' => 'authenticated',
            'email' => substr($id, 0, 1).'@example.test',
            'email_confirmed_at' => '2026-01-02T03:04:05.123456Z',
            'confirmed_at' => '2026-01-02T03:04:05.123456Z',
            'last_sign_in_at' => '2026-11-01T10:00:00.5Z',
            'app_metadata' => ['provider' => 'discord', 'providers' => array_column($identities, 'provider')],
            'user_metadata' => ['full_name' => 'Name '.substr($id, 0, 1), 'avatar_url' => 'https://cdn.example.test/a.png'],
            'identities' => $identities,
            'created_at' => '2026-01-02T03:04:05.123456Z',
            'updated_at' => '2026-11-01T10:00:00.5Z',
            'is_anonymous' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function identity(string $identityId, string $userId, string $provider, string $providerId): array
    {
        return [
            'identity_id' => $identityId,
            'id' => $providerId,
            'user_id' => $userId,
            'identity_data' => ['sub' => $providerId, 'provider_id' => $providerId, 'name' => 'n'],
            'provider' => $provider,
            'last_sign_in_at' => '2026-11-01T10:00:00Z',
            'created_at' => '2026-01-02T03:04:05Z',
            'updated_at' => '2026-11-01T10:00:00Z',
            'email' => 'x@example.test',
        ];
    }

    /** @return array<string, array<string, mixed>> Supabase's accounts, by id. */
    private static function accounts(): array
    {
        return [
            self::ALICE => self::user(self::ALICE, [self::identity('11111111-0000-4000-8000-000000000001', self::ALICE, 'discord', '1001')]),
            self::BOB => self::user(self::BOB, [
                self::identity('22222222-0000-4000-8000-000000000002', self::BOB, 'discord', '2002'),
                self::identity('33333333-0000-4000-8000-000000000003', self::BOB, 'github', '3003'),
            ]),
        ];
    }

    /**
     * Supabase Auth's admin API: the list a page at a time without
     * identities, as it answers, and each account on its own with them.
     *
     * @param  array<string, array<string, mixed>>  $accounts
     */
    private function fakeAdmin(array $accounts, int $perPage = 1): void
    {
        Http::fake(function (Request $request) use ($accounts, $perPage) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            if ($path === '/auth/v1/admin/users') {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $page = array_slice(array_values($accounts), ((int) $query['page'] - 1) * $perPage, $perPage);

                return Http::response(['users' => array_map(fn ($u) => ['identities' => null] + $u, $page), 'aud' => 'authenticated']);
            }
            if (str_starts_with($path, '/auth/v1/admin/users/')) {
                $id = substr($path, strlen('/auth/v1/admin/users/'));

                return isset($accounts[$id]) ? Http::response($accounts[$id]) : Http::response(['code' => 404, 'msg' => 'User not found'], 404);
            }

            return Http::response(['message' => 'not faked'], 500);
        });
    }

    private function seedGone(): void
    {
        DB::table('auth_user')->insert([
            'id' => self::GONE, 'app_metadata' => '{}', 'user_metadata' => '{}', 'created_at' => '2026-01-01 00:00:00.000000',
            'copied_at' => '2026-01-01 00:00:00.000000', 'copy_hash' => str_repeat('0', 40),
        ]);
        DB::table('auth_identity')->insert([
            'id' => '44444444-0000-4000-8000-000000000004', 'user_id' => self::GONE, 'provider' => 'twitch', 'provider_id' => '4004', 'identity_data' => '{}',
        ]);
    }

    public function test_a_dry_run_counts_and_writes_nothing(): void
    {
        $this->seedGone();
        $this->fakeAdmin(self::accounts());

        $this->artisan('users:copy', ['--dry-run' => true])
            ->expectsOutput('Accounts: Supabase 2, MariaDB 1. Providers: Supabase 3, MariaDB 1.')
            ->expectsOutput('To add: 2 accounts. To remove: 1 accounts Supabase no longer has.')
            ->expectsOutput('Dry run: nothing was written.')
            ->assertExitCode(0);

        $this->assertSame([self::GONE], DB::table('auth_user')->pluck('id')->all());
        $this->assertSame(1, DB::table('auth_identity')->count());
    }

    public function test_the_copy_keeps_supabase_ids_and_providers_and_drops_deleted_accounts(): void
    {
        $this->seedGone();
        $this->fakeAdmin(self::accounts());

        $this->artisan('users:copy')
            ->expectsOutput('Added or updated 2 accounts, removed 1.')
            ->expectsOutput('Accounts: Supabase 2, MariaDB 2. Providers: Supabase 3, MariaDB 3.')
            ->assertExitCode(0);

        $this->assertSame([self::ALICE, self::BOB], DB::table('auth_user')->orderBy('id')->pluck('id')->all());
        $this->assertSame(
            [
                ['11111111-0000-4000-8000-000000000001', self::ALICE, 'discord', '1001'],
                ['22222222-0000-4000-8000-000000000002', self::BOB, 'discord', '2002'],
                ['33333333-0000-4000-8000-000000000003', self::BOB, 'github', '3003'],
            ],
            DB::table('auth_identity')->orderBy('id')->get()->map(fn ($r) => [$r->id, $r->user_id, $r->provider, $r->provider_id])->all(),
        );
        $alice = DB::table('auth_user')->where('id', self::ALICE)->first();
        $this->assertSame('a@example.test', $alice->email);
        $this->assertSame('2026-01-02 03:04:05.123456', substr((string) $alice->created_at, 0, 26));
        $this->assertSame('2026-11-01 10:00:00.500000', substr((string) $alice->last_sign_in_at, 0, 26));
        $this->assertSame('Name a', json_decode($alice->user_metadata, true)['full_name']);
        $this->assertSame(['discord'], json_decode($alice->app_metadata, true)['providers']);
        Http::assertSentCount(5);

        // Running it again changes nothing.
        $this->artisan('users:copy')->expectsOutput('Added or updated 0 accounts, removed 0.')->assertExitCode(0);
    }

    public function test_the_copy_stops_when_supabase_auth_fails(): void
    {
        Http::fake([self::URL.'/auth/v1/admin/users*' => Http::response(['msg' => 'down'], 500)]);

        $this->artisan('users:copy')->assertExitCode(1);
        $this->assertSame(0, DB::table('auth_user')->count());
    }

    public function test_a_bearer_request_updates_the_copy(): void
    {
        $bob = self::accounts()[self::BOB];
        Http::fake([
            self::URL.'/auth/v1/user' => Http::response($bob),
            self::URL.'/rest/v1/*' => Http::response([['user_id' => self::BOB, 'display_name' => 'Bob']]),
        ]);

        $this->call('GET', '/api/v1/profile', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer t'])->assertOk();

        $this->assertSame(self::BOB, DB::table('auth_user')->value('id'));
        $this->assertSame(['discord', 'github'], DB::table('auth_identity')->orderBy('provider')->pluck('provider')->all());
        $copiedAt = DB::table('auth_user')->value('copied_at');

        // The same account again writes nothing.
        $this->call('GET', '/api/v1/profile', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer t'])->assertOk();
        $this->assertSame($copiedAt, DB::table('auth_user')->value('copied_at'));
    }

    public function test_coilbox_signing_in_through_the_hub_updates_the_copy(): void
    {
        $alice = self::accounts()[self::ALICE];
        Http::fake([self::URL.'/auth/v1/token*' => Http::response([
            'access_token' => 'access', 'token_type' => 'bearer', 'expires_in' => 3600, 'expires_at' => 1900000000,
            'refresh_token' => 'refresh', 'user' => $alice,
        ])]);

        $this->postJson('/api/v1/auth/token?grant_type=pkce', ['auth_code' => 'code', 'code_verifier' => 'verifier'])->assertOk();

        $this->assertSame(self::ALICE, DB::table('auth_user')->value('id'));
        $this->assertSame('1001', DB::table('auth_identity')->value('provider_id'));
    }

    public function test_a_browser_sign_in_updates_the_copy_and_a_new_provider_is_added(): void
    {
        $bob = self::accounts()[self::BOB];
        $onlyDiscord = ['identities' => [$bob['identities'][0]]] + $bob;
        AuthUsers::keep($onlyDiscord);
        $this->assertSame(1, DB::table('auth_identity')->count());

        Http::fake([
            self::URL.'/auth/v1/token*' => Http::response([
                'access_token' => 'a.'.rtrim(base64_encode(json_encode(['sub' => self::BOB, 'aal' => 'aal1'])), '=').'.s',
                'refresh_token' => 'refresh', 'expires_in' => 3600, 'user' => $bob,
            ]),
            self::URL.'/rest/v1/*' => Http::response([]),
        ]);

        $this->withSession(['pkce' => ['verifier' => 'v', 'next' => '/']])->get('/auth/callback?code=c')->assertRedirect('/');

        $this->assertSame(['discord', 'github'], DB::table('auth_identity')->where('user_id', self::BOB)->orderBy('provider')->pluck('provider')->all());
    }

    public function test_unlinking_a_provider_removes_it_from_the_copy(): void
    {
        $bob = self::accounts()[self::BOB];
        AuthUsers::keep($bob);
        Http::fake([
            self::URL.'/auth/v1/user' => Http::response($bob),
            self::URL.'/auth/v1/*' => Http::response([], 200),
        ]);

        $this->withSession(['supabase' => ['access_token' => 'access', 'refresh_token' => 'r', 'expires_at' => time() + 3600, 'user_id' => self::BOB, 'aal' => 'aal1']])
            ->post('/account/unlink', ['identityId' => '33333333-0000-4000-8000-000000000003'])
            ->assertRedirect('/account#sign-in-methods');

        $this->assertSame(['discord'], DB::table('auth_identity')->where('user_id', self::BOB)->pluck('provider')->all());
    }

    public function test_a_provider_account_moved_to_another_user_follows_it(): void
    {
        $accounts = self::accounts();
        AuthUsers::keep($accounts[self::ALICE]);
        $moved = self::identity('55555555-0000-4000-8000-000000000005', self::BOB, 'discord', '1001');

        AuthUsers::keep(['identities' => [$moved]] + $accounts[self::BOB]);

        $this->assertSame([self::BOB], DB::table('auth_identity')->where('provider_id', '1001')->pluck('user_id')->all());
    }

    public function test_forgetting_an_account_takes_its_providers(): void
    {
        AuthUsers::keep(self::accounts()[self::BOB]);

        AuthUsers::forget(self::BOB);

        $this->assertSame(0, DB::table('auth_user')->count());
        $this->assertSame(0, DB::table('auth_identity')->count());
    }
}
