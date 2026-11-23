<?php

namespace Tests\Feature\Points;

use App\Domain\Container\Container;
use App\Domain\Points\CommunityPoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Moderation\ModerationTestCase;

/** Publishing earns 20 points for an item, and withdrawing it takes them back. */
class ItemPointsTest extends ModerationTestCase
{
    use RefreshDatabase;

    private const OWNER = 'aaaaaaaa-0000-4000-8000-00000000000a';

    private const ITEM = '11111111-1111-4111-8111-111111111111';

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
        foreach ([self::OWNER => 'Owner', self::MODERATOR => 'Moddy'] as $id => $name) {
            DB::table('profile')->insert(['user_id' => $id, 'display_name' => $name, 'created_at' => '2026-10-01 10:00:00.000000', 'updated_at' => '2026-10-01 10:00:00.000000']);
        }
        DB::table('user_capability')->insert(['user_id' => self::MODERATOR, 'capability' => 'can_moderate', 'granted_at' => '2026-10-01 10:00:00.000000']);
    }

    private static function payload(): object
    {
        return (object) [
            'gameName' => 'Beyond All Reason', 'mapName' => 'Comet Catcher Remake', 'startPosType' => 2,
            'modOptionValues' => new \stdClass, 'participants' => [],
        ];
    }

    private function presetCode(): string
    {
        return Container::encodeCode('preset', Container::SUPPORTED_KIND_VERSIONS['preset'], self::payload());
    }

    private function as(string $userId, string $token = 'access'): static
    {
        return $this->withSession(['supabase' => [
            'access_token' => $token, 'refresh_token' => 'refresh', 'expires_at' => time() + 3600, 'user_id' => $userId, 'aal' => 'aal1',
        ]]);
    }

    private function auth(string $userId = self::OWNER): void
    {
        $this->supabase(['/auth/v1/user' => self::reply(['id' => $userId, 'factors' => [], 'email' => 'x@example.test', 'user_metadata' => []])]);
    }

    /** Publishes through the website and returns the new item's id. */
    private function publishOnTheSite(): string
    {
        $this->as(self::OWNER)->post('/publish', ['code' => $this->presetCode(), 'title' => 'From the site'])->assertSessionHas('publish.publishedId');

        return (string) session('publish.publishedId');
    }

    public function test_publishing_on_the_website_earns_twenty(): void
    {
        $this->auth();

        $this->publishOnTheSite();

        $this->assertSame(20, CommunityPoints::total(self::OWNER));
    }

    public function test_publishing_through_the_api_earns_twenty(): void
    {
        $this->auth();

        $this->call('POST', '/api/v1/items', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer good-token', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['code' => $this->presetCode(), 'title' => 'Fresh']))->assertCreated();

        $this->assertSame(20, CommunityPoints::total(self::OWNER));
    }

    public function test_withdrawing_takes_the_points_back_and_restoring_gives_them_again(): void
    {
        $this->auth();
        $id = $this->publishOnTheSite();

        $this->as(self::OWNER)->post('/item/'.$id.'/withdraw', ['withdrawn' => 'true'])->assertRedirect();
        $this->assertSame(0, CommunityPoints::total(self::OWNER));

        $this->as(self::OWNER)->post('/item/'.$id.'/withdraw', ['withdrawn' => 'false'])->assertRedirect();
        $this->assertSame(20, CommunityPoints::total(self::OWNER));
    }

    public function test_somebody_else_cannot_withdraw_an_item_or_take_its_points(): void
    {
        $this->auth();
        $id = $this->publishOnTheSite();
        $stranger = 'bbbbbbbb-0000-4000-8000-00000000000b';
        DB::table('profile')->insert(['user_id' => $stranger, 'display_name' => 'Stranger', 'created_at' => '2026-10-01 10:00:00.000000', 'updated_at' => '2026-10-01 10:00:00.000000']);
        $this->auth($stranger);

        $this->as($stranger)->post('/item/'.$id.'/withdraw', ['withdrawn' => 'true'])->assertRedirect();

        $this->assertNull(DB::table('item')->where('id', $id)->value('deleted_at'));
        $this->assertSame(20, CommunityPoints::total(self::OWNER));
    }

    public function test_a_moderator_withdrawing_an_item_takes_the_points_back(): void
    {
        $this->auth(self::MODERATOR);
        DB::table('item')->insert([
            'id' => self::ITEM, 'kind' => 'preset', 'kind_version' => Container::SUPPORTED_KIND_VERSIONS['preset'], 'title' => 'An item',
            'description' => '', 'tags' => '[]', 'container' => json_encode((object) ['format' => 'coilbox']), 'author_id' => self::OWNER,
            'author_name' => 'Owner', 'created_at' => '2026-11-01 10:00:00.000000', 'updated_at' => '2026-11-01 10:00:00.000000', 'import_count' => 0,
        ]);
        CommunityPoints::award(self::OWNER, CommunityPoints::ITEM, self::ITEM);
        $this->post('/item/'.self::ITEM.'/report', ['reason' => 'Spam']);
        $report = DB::table('report')->value('id');

        $this->as(self::MODERATOR)->post('/moderation/reports', ['report_id' => $report, 'item_id' => self::ITEM, 'withdraw' => 'true'])->assertRedirect('/moderation');

        $this->assertNotNull(DB::table('item')->where('id', self::ITEM)->value('deleted_at'));
        $this->assertSame(0, CommunityPoints::total(self::OWNER));
    }

    public function test_handling_a_report_without_withdrawing_keeps_the_points(): void
    {
        $this->auth(self::MODERATOR);
        DB::table('item')->insert([
            'id' => self::ITEM, 'kind' => 'preset', 'kind_version' => Container::SUPPORTED_KIND_VERSIONS['preset'], 'title' => 'An item',
            'description' => '', 'tags' => '[]', 'container' => json_encode((object) ['format' => 'coilbox']), 'author_id' => self::OWNER,
            'author_name' => 'Owner', 'created_at' => '2026-11-01 10:00:00.000000', 'updated_at' => '2026-11-01 10:00:00.000000', 'import_count' => 0,
        ]);
        CommunityPoints::award(self::OWNER, CommunityPoints::ITEM, self::ITEM);
        $this->post('/item/'.self::ITEM.'/report', ['reason' => 'Spam']);
        $report = DB::table('report')->value('id');

        $this->as(self::MODERATOR)->post('/moderation/reports', ['report_id' => $report, 'item_id' => self::ITEM, 'withdraw' => 'false'])->assertRedirect('/moderation');

        $this->assertSame(20, CommunityPoints::total(self::OWNER));
    }
}
