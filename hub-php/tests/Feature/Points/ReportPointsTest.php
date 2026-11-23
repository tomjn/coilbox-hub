<?php

namespace Tests\Feature\Points;

use App\Domain\Container\Container;
use App\Domain\Points\CommunityPoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Moderation\ModerationTestCase;

/** An upheld report earns its reporter 5 points. A dismissed one earns nothing. */
class ReportPointsTest extends ModerationTestCase
{
    use RefreshDatabase;

    private const REPORTER = 'aaaaaaaa-0000-4000-8000-00000000000a';

    private const MAP = 'dddddddd-0000-4000-8000-00000000000d';

    private const ITEM = '11111111-1111-4111-8111-111111111111';

    private const MAP_REPORT = '66666666-6666-4666-8666-666666666666';

    private const ITEM_REPORT = '55555555-5555-4555-8555-555555555555';

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
        foreach ([self::REPORTER => 'Reporter', self::MODERATOR => 'Moddy'] as $id => $name) {
            DB::table('profile')->insert(['user_id' => $id, 'display_name' => $name, 'created_at' => '2026-10-01 10:00:00.000000', 'updated_at' => '2026-10-01 10:00:00.000000']);
        }
        DB::table('user_capability')->insert(['user_id' => self::MODERATOR, 'capability' => 'can_moderate', 'granted_at' => '2026-10-01 10:00:00.000000']);
        DB::table('map')->insert([
            'id' => self::MAP, 'map_name' => 'Comet Catcher Remake', 'slug' => 'comet-catcher-remake', 'width_elmos' => 6144, 'height_elmos' => 6144,
            'world_height_min' => -10.0, 'world_height_max' => 300.0, 'appearance' => '{}', 'curated_tags' => '[]', 'source_hash' => 'h',
            'source_archive' => 'Comet Catcher Remake', 'catalog_version' => 1, 'facts_digest' => 'd',
            'seen_at' => '2026-10-01 10:00:00.000000', 'created_at' => '2026-10-01 10:00:00.000000', 'updated_at' => '2026-10-01 10:00:00.000000',
        ]);
        DB::table('item')->insert([
            'id' => self::ITEM, 'kind' => 'preset', 'kind_version' => Container::SUPPORTED_KIND_VERSIONS['preset'], 'title' => 'An item',
            'description' => '', 'tags' => '[]', 'container' => json_encode((object) ['format' => 'coilbox']), 'author_id' => self::REPORTER,
            'author_name' => 'Reporter', 'created_at' => '2026-11-01 10:00:00.000000', 'updated_at' => '2026-11-01 10:00:00.000000', 'import_count' => 0,
        ]);
        $this->supabase(['/auth/v1/user' => self::reply(['id' => self::MODERATOR, 'factors' => [], 'email' => 'x@example.test', 'user_metadata' => []])]);
    }

    private function seedReport(string $id, ?string $reporter, bool $map): void
    {
        DB::table('report')->insert([
            'id' => $id, 'item_id' => $map ? null : self::ITEM, 'map_id' => $map ? self::MAP : null, 'kind' => $map ? 'other' : null,
            'reason' => 'Because', 'created_at' => '2026-11-01 10:00:00.000000', 'reporter_id' => $reporter,
        ]);
    }

    private function act(array $data): void
    {
        $this->withSession(['supabase' => [
            'access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600, 'user_id' => self::MODERATOR, 'aal' => 'aal1',
        ]])->post('/moderation/reports', $data)->assertRedirect('/moderation');
    }

    public function test_upholding_a_map_report_records_it_and_pays_the_reporter(): void
    {
        $this->seedReport(self::MAP_REPORT, self::REPORTER, true);

        $this->act(['report_id' => self::MAP_REPORT, 'outcome' => 'upheld']);

        $this->assertSame('upheld', DB::table('report')->where('id', self::MAP_REPORT)->value('outcome'));
        $this->assertSame(5, CommunityPoints::total(self::REPORTER));
    }

    public function test_dismissing_a_map_report_records_it_and_pays_nothing(): void
    {
        $this->seedReport(self::MAP_REPORT, self::REPORTER, true);

        $this->act(['report_id' => self::MAP_REPORT, 'outcome' => 'dismissed']);

        $this->assertSame('dismissed', DB::table('report')->where('id', self::MAP_REPORT)->value('outcome'));
        $this->assertNotNull(DB::table('report')->where('id', self::MAP_REPORT)->value('handled_at'));
        $this->assertSame(0, CommunityPoints::total(self::REPORTER));
    }

    public function test_withdrawing_a_reported_item_upholds_the_report(): void
    {
        $this->seedReport(self::ITEM_REPORT, self::REPORTER, false);

        $this->act(['report_id' => self::ITEM_REPORT, 'item_id' => self::ITEM, 'withdraw' => 'true']);

        $this->assertSame('upheld', DB::table('report')->where('id', self::ITEM_REPORT)->value('outcome'));
        $this->assertSame(5, CommunityPoints::total(self::REPORTER));
    }

    public function test_leaving_a_reported_item_dismisses_the_report(): void
    {
        $this->seedReport(self::ITEM_REPORT, self::REPORTER, false);

        $this->act(['report_id' => self::ITEM_REPORT, 'item_id' => self::ITEM, 'outcome' => 'dismissed']);

        $this->assertSame('dismissed', DB::table('report')->where('id', self::ITEM_REPORT)->value('outcome'));
        $this->assertSame(0, CommunityPoints::total(self::REPORTER));
    }

    public function test_a_signed_out_reporter_earns_nobody_points(): void
    {
        $this->seedReport(self::MAP_REPORT, null, true);

        $this->act(['report_id' => self::MAP_REPORT, 'outcome' => 'upheld']);

        $this->assertSame('upheld', DB::table('report')->where('id', self::MAP_REPORT)->value('outcome'));
        $this->assertSame(0, DB::table('community_point')->count());
    }

    public function test_a_moderator_earns_nothing_for_upholding_their_own_report(): void
    {
        $this->seedReport(self::MAP_REPORT, self::MODERATOR, true);

        $this->act(['report_id' => self::MAP_REPORT, 'outcome' => 'upheld']);

        $this->assertSame('upheld', DB::table('report')->where('id', self::MAP_REPORT)->value('outcome'));
        $this->assertSame(0, CommunityPoints::total(self::MODERATOR));
    }

    public function test_acting_on_a_missing_report_awards_nothing(): void
    {
        $this->act(['report_id' => self::MAP_REPORT, 'outcome' => 'upheld']);

        $this->assertSame(0, DB::table('community_point')->count());
    }
}
