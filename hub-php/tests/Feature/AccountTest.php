<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccountTest extends TestCase
{
    private const URL = 'https://project.supabase.test';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.supabase.url' => self::URL,
            'hub.supabase.publishable_key' => 'sb_publishable_test',
            'hub.supabase.service_role_key' => 'service-key',
        ]);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    /** A signed in session at the given authenticator level. */
    private function signedIn(string $aal = 'aal1'): static
    {
        return $this->withSession(['supabase' => [
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'expires_at' => time() + 3600,
            'user_id' => 'user-1',
            'aal' => $aal,
        ]]);
    }

    /** @param list<array<string, mixed>> $factors */
    private function fakeUser(array $factors, int $identities = 2): void
    {
        Http::fake([
            self::URL.'/auth/v1/user' => Http::response([
                'id' => 'user-1',
                'factors' => $factors,
                'identities' => array_map(fn ($i) => ['identity_id' => "id-$i", 'provider' => 'discord', 'identity_data' => []], range(1, $identities)),
            ]),
            self::URL.'/auth/v1/*' => Http::response([], 200),
            self::URL.'/rest/v1/*' => Http::response([['display_name' => 'Tom']]),
        ]);
    }

    public function test_removing_an_authenticator_needs_a_code_this_session(): void
    {
        $this->fakeUser([['id' => 'f1', 'status' => 'verified', 'factor_type' => 'totp']]);

        $this->signedIn('aal1')->post('/account/factor/remove', ['factorId' => 'f1'])
            ->assertRedirect('/account#second-factor')
            ->assertSessionHas('remove.error');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_removing_an_authenticator_at_aal2_deletes_it(): void
    {
        $this->fakeUser([['id' => 'f1', 'status' => 'verified', 'factor_type' => 'totp']]);

        $this->signedIn('aal2')->post('/account/factor/remove', ['factorId' => 'f1'])
            ->assertRedirect('/account#second-factor')
            ->assertSessionMissing('remove');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/auth/v1/factors/f1'));
    }

    public function test_cannot_remove_an_authenticator_on_someone_elses_account(): void
    {
        $this->fakeUser([['id' => 'f1', 'status' => 'verified', 'factor_type' => 'totp']]);

        $this->signedIn('aal2')->post('/account/factor/remove', ['factorId' => 'other'])
            ->assertSessionHas('remove.error', 'That authenticator is not on your account.');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_the_last_sign_in_method_cannot_be_unlinked(): void
    {
        $this->fakeUser([], identities: 1);

        $this->signedIn()->post('/account/unlink', ['identityId' => 'id-1'])
            ->assertSessionHas('unlink.error');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_unlinking_needs_a_code_when_the_account_has_an_authenticator(): void
    {
        $this->fakeUser([['id' => 'f1', 'status' => 'verified', 'factor_type' => 'totp']]);

        $this->signedIn('aal1')->post('/account/unlink', ['identityId' => 'id-1'])
            ->assertSessionHas('unlink.error', 'Enter a code from your authenticator before changing how you sign in.');
    }

    public function test_deleting_needs_the_exact_display_name(): void
    {
        $this->fakeUser([]);

        $this->signedIn()->post('/account/delete', ['confirm' => 'tom'])
            ->assertSessionHas('delete.error');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/admin/users/'));
    }

    public function test_deleting_with_the_name_deletes_the_user_with_the_service_key(): void
    {
        $this->fakeUser([]);

        $this->signedIn()->post('/account/delete', ['confirm' => ' Tom '])->assertRedirect('/?deleted=1');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_ends_with($r->url(), '/auth/v1/admin/users/user-1')
            && $r->header('Authorization')[0] === 'Bearer service-key');
    }

    public function test_signed_out_visitors_go_to_publish(): void
    {
        $this->get('/account')->assertRedirect('/publish');
        $this->post('/account/factor/start')->assertRedirect('/publish');
    }
}
