<?php

namespace Tests\Feature\Gallery;

use App\Domain\Points\CommunityPoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\StartsWithNoPoints;
use Tests\Feature\Moderation\ModerationTestCase;

/** The caller's community points, served to Coilbox and shown on their own profile. */
class PointsTest extends ModerationTestCase
{
    use RefreshDatabase;
    use StartsWithNoPoints;

    private const OWNER = 'aaaaaaaa-0000-4000-8000-00000000000a';

    private const STRANGER = 'bbbbbbbb-0000-4000-8000-00000000000b';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.games_store' => 'mariadb',
            'hub.maps_store' => 'mariadb',
            'hub.pictures_store' => 'mariadb',
            'hub.mirrors_store' => 'mariadb',
            'hub.items_store' => 'mariadb',
            'app.url' => 'https://hub.test',
        ]);
        foreach ([self::OWNER => 'Owner', self::STRANGER => 'Stranger'] as $id => $name) {
            DB::table('profile')->insert(['user_id' => $id, 'display_name' => $name, 'created_at' => '2026-10-01 10:00:00.000000', 'updated_at' => '2026-10-01 10:00:00.000000']);
        }
    }

    /** A signed in session as somebody, the way HubSession stores one. */
    private function as(string $userId, string $token = 'access'): static
    {
        return $this->withSession(['supabase' => [
            'access_token' => $token, 'refresh_token' => 'refresh', 'expires_at' => time() + 3600, 'user_id' => $userId, 'aal' => 'aal1',
        ]]);
    }

    /** Supabase Auth only: who a token belongs to, with no second factor. */
    private function auth(string $userId = self::OWNER): void
    {
        $this->supabase(['/auth/v1/user' => self::reply(['id' => $userId, 'factors' => [], 'email' => 'x@example.test', 'user_metadata' => []])]);
    }

    private function bearer(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer t'];
    }

    public function test_the_api_answers_the_callers_points(): void
    {
        $this->auth();
        CommunityPoints::award(self::OWNER, CommunityPoints::ITEM, 'item-1');
        CommunityPoints::award(self::OWNER, CommunityPoints::REPORT, 'report-1');

        $response = $this->call('GET', '/api/v1/profile/points', [], [], [], $this->bearer());

        $response->assertOk()->assertExactJson(['points' => 25]);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_the_api_answers_zero_for_an_account_with_no_points(): void
    {
        $this->auth();

        $this->call('GET', '/api/v1/profile/points', [], [], [], $this->bearer())->assertOk()->assertExactJson(['points' => 0]);
    }

    public function test_the_api_refuses_without_a_token(): void
    {
        $this->auth();

        $this->getJson('/api/v1/profile/points')->assertStatus(401);
    }

    public function test_the_owner_sees_their_points_on_their_profile(): void
    {
        $this->auth();
        CommunityPoints::award(self::OWNER, CommunityPoints::ITEM, 'item-1');
        CommunityPoints::award(self::OWNER, CommunityPoints::REPORT, 'report-1');

        $this->as(self::OWNER)->get('/profile/Owner')->assertOk()->assertSee('25')->assertSee('community points')->assertSee('Change your name');
    }

    public function test_another_account_sees_the_points_but_not_the_rename_link(): void
    {
        $this->auth(self::STRANGER);
        CommunityPoints::award(self::OWNER, CommunityPoints::ITEM, 'item-1');

        $this->as(self::STRANGER)->get('/profile/Owner')->assertOk()->assertSee('20')->assertSee('community points')->assertDontSee('Change your name');
    }

    public function test_a_signed_out_visitor_sees_the_points(): void
    {
        $this->auth();
        CommunityPoints::award(self::OWNER, CommunityPoints::ITEM, 'item-1');

        $this->get('/profile/Owner')->assertOk()->assertSee('20')->assertSee('community points');
    }

    public function test_a_profile_is_found_by_name_whatever_the_capitals(): void
    {
        $this->auth();

        $this->get('/profile/oWnEr')->assertRedirect('/profile/Owner');
        $this->get('/profile/Owner')->assertOk()->assertSee('Owner has not added anything to the hub yet.');
    }

    public function test_an_id_address_sends_the_browser_to_the_name(): void
    {
        $this->auth();

        $this->get('/profile/'.self::OWNER)->assertRedirect('/profile/Owner');
        $this->get('/profile/'.strtoupper(self::OWNER))->assertRedirect('/profile/Owner');
    }

    public function test_a_name_nobody_has_is_not_found(): void
    {
        $this->auth();

        $this->get('/profile/Nobody')->assertNotFound();
        // A wildcard in the address is a letter, not a pattern.
        $this->get('/profile/Own%25')->assertNotFound();
        $this->get('/profile/_wner')->assertNotFound();
    }

    public function test_a_name_with_a_space_is_found_and_linked_encoded(): void
    {
        $this->auth();
        DB::table('profile')->where('user_id', self::OWNER)->update(['display_name' => 'The Owner']);

        $this->get('/profile/'.self::OWNER)->assertRedirect('/profile/The%20Owner');
        $this->get('/profile/The%20Owner')->assertOk()->assertSee('The Owner');
    }
}
