<?php

namespace Tests\Feature\Gallery;

use App\Domain\Points\CommunityPoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Moderation\ModerationTestCase;

/** The caller's community points, served to Coilbox and shown on their own profile. */
class PointsTest extends ModerationTestCase
{
    use RefreshDatabase;

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

        $this->as(self::OWNER)->get('/profile/'.self::OWNER)->assertOk()->assertSee('25')->assertSee('community points');
    }

    public function test_another_account_does_not_see_the_points(): void
    {
        $this->auth(self::STRANGER);
        CommunityPoints::award(self::OWNER, CommunityPoints::ITEM, 'item-1');

        $this->as(self::STRANGER)->get('/profile/'.self::OWNER)->assertOk()->assertDontSee('community points');
    }

    public function test_a_signed_out_visitor_does_not_see_the_points(): void
    {
        $this->auth();
        CommunityPoints::award(self::OWNER, CommunityPoints::ITEM, 'item-1');

        $this->get('/profile/'.self::OWNER)->assertOk()->assertDontSee('community points');
    }
}
