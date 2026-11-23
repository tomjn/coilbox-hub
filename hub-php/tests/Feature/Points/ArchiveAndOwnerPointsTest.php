<?php

namespace Tests\Feature\Points;

use App\Domain\Points\CommunityPoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Moderation\ModerationTestCase;
use Tests\Unit\Mirrors\MirrorFixtures;

/** A listed archive earns 20 points, a removal takes them back, and game ownership earns 100. */
class ArchiveAndOwnerPointsTest extends ModerationTestCase
{
    use MirrorFixtures;
    use RefreshDatabase;

    private const USER = '11111111-1111-1111-1111-111111111111';

    private const OTHER = '22222222-2222-2222-2222-222222222222';

    private const GAME = 'bbbbbbbb-0000-4000-8000-000000000001';

    private const ARCHIVE = 'dddddddd-0000-4000-8000-000000000001';

    /** The first hash the fixture report holds. */
    private const HELD = '58648994035e7d2b82ee74b1aa5a66e772655bddf03156fc4ee439a1b8872ef2';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.games_store' => 'mariadb',
            'hub.maps_store' => 'mariadb',
            'hub.pictures_store' => 'mariadb',
            'hub.mirrors_store' => 'mariadb',
            'hub.mirrors.secret_key' => self::fixtureKeys()['hub_secret_key'],
            'hub.mirrors.next_secret_key' => '',
            'hub.mirrors.reservation' => 86400,
            'app.url' => 'https://hub.test',
        ]);
        DB::table('mirror')->insert([
            'id' => 'jj-darkstars', 'name' => 'jj.darkstars.co.uk', 'base_url' => 'https://mirror.test',
            'public_key' => self::fixtureKeys()['mirror_public_key'], 'enabled' => true, 'healthy' => true,
            'created_at' => '2026-11-17 12:00:00.000000',
        ]);
    }

    /** @param array<string, mixed> $over */
    private function archive(array $over = []): void
    {
        $filename = $over['filename'] ?? 'Comet Catcher 1.8.sd7';
        DB::table('archive')->insert($over + [
            'id' => self::ARCHIVE, 'sha256' => self::HELD, 'filename' => $filename, 'filename_lower' => mb_strtolower($filename),
            'kind' => 'map', 'internal_name' => 'Comet Catcher 1.8', 'version' => '', 'shortname' => null, 'author' => 'Beherith',
            'description' => null, 'size' => 43, 'uploader' => self::USER, 'state' => 'uploading', 'game_id' => null,
            'upload_mirror_id' => 'jj-darkstars', 'reserved_until' => '2099-01-01 00:00:00.000000', 'listed_at' => null,
            'created_at' => '2026-11-18 09:00:00.000000', 'updated_at' => '2026-11-18 10:00:00.000000',
        ]);
    }

    private function report(): void
    {
        $this->supabase(['HEAD mirror.test' => Http::response('', 200, ['Content-Length' => '43'])]);
        $this->call('POST', '/api/v1/mirrors/report', [], [], [], ['CONTENT_TYPE' => 'application/json'], self::fixture('report-upload.json'))->assertOk();
    }

    public function test_a_listed_upload_gives_its_uploader_20(): void
    {
        $this->archive();

        $this->report();

        $this->assertSame(20, CommunityPoints::total(self::USER));
    }

    public function test_the_same_report_again_gives_no_new_row(): void
    {
        $this->archive();

        $this->report();
        $this->report();

        $this->assertSame(1, DB::table('community_point')->count());
    }

    public function test_a_revived_archive_gives_20_and_one_that_clashes_gives_none(): void
    {
        $this->archive(['state' => 'failed', 'reserved_until' => null]);
        $this->archive([
            'id' => 'dddddddd-0000-4000-8000-000000000002', 'sha256' => 'cffcb652ffa611d21fe7be0ec6419ee164f895279faea58a42d15b5c8152036e',
            'filename' => 'Taken.sd7', 'internal_name' => 'Taken', 'state' => 'failed', 'uploader' => self::OTHER, 'reserved_until' => null,
        ]);
        $this->archive([
            'id' => 'dddddddd-0000-4000-8000-000000000003', 'sha256' => str_repeat('e', 64), 'filename' => 'Other.sd7',
            'internal_name' => 'Taken', 'state' => 'listed', 'upload_mirror_id' => null, 'uploader' => null, 'reserved_until' => null,
        ]);

        $this->report();

        $this->assertSame(20, CommunityPoints::total(self::USER));
        $this->assertSame(0, CommunityPoints::total(self::OTHER));
    }

    public function test_the_uploader_withdrawing_the_archive_takes_the_points_back(): void
    {
        $this->archive(['uploader' => self::MODERATOR]);
        $this->report();
        $this->assertSame(20, CommunityPoints::total(self::MODERATOR));

        $this->signedIn()->post('/account/uploads/withdraw', ['archive_id' => self::ARCHIVE]);

        $this->assertSame('removed', DB::table('archive')->where('id', self::ARCHIVE)->value('state'));
        $this->assertSame(0, CommunityPoints::total(self::MODERATOR));
    }

    public function test_a_moderator_removing_the_archive_takes_the_points_back(): void
    {
        $this->archive();
        $this->report();
        $this->assertSame(20, CommunityPoints::total(self::USER));

        $this->signedIn()->post('/moderation/archives/remove', ['archive_id' => self::ARCHIVE, 'reason' => 'broken']);

        $this->assertSame('removed', DB::table('archive')->where('id', self::ARCHIVE)->value('state'));
        $this->assertSame(0, CommunityPoints::total(self::USER));
    }

    private function game(string $owner): void
    {
        DB::table('game')->insert([
            'id' => self::GAME, 'shortname' => 'bar', 'display_name' => 'Beyond All Reason', 'links' => '[]', 'conquest_factions' => '[]',
            'owner_user_id' => $owner, 'created_at' => '2026-10-01 10:00:00.000000', 'updated_at' => '2026-10-01 10:00:00.000000',
        ]);
    }

    private function ask(int $id, string $by): void
    {
        DB::table('game_ownership_request')->insert([
            'id' => $id, 'game_id' => self::GAME, 'requested_by' => $by, 'requested_by_name' => 'Requester',
            'state' => 'open', 'created_at' => '2026-11-20 10:00:00.000000',
        ]);
    }

    private function decide(int $id, bool $approve): void
    {
        // Only the moderator check goes to Supabase.
        $this->supabase();
        $this->signedIn()->post('/moderation/games/decide', ['request_id' => (string) $id, 'approve' => $approve ? 'true' : 'false']);
    }

    public function test_approving_an_ownership_request_gives_100(): void
    {
        $this->game(self::OTHER);
        $this->ask(1, self::USER);

        $this->decide(1, true);

        $this->assertSame(self::USER, DB::table('game')->where('id', self::GAME)->value('owner_user_id'));
        $this->assertSame(100, CommunityPoints::total(self::USER));
    }

    public function test_approving_a_second_request_moves_the_100(): void
    {
        $this->game(self::OTHER);
        $this->ask(1, self::USER);
        $this->ask(2, self::OTHER);

        $this->decide(1, true);
        $this->decide(2, true);

        $this->assertSame(self::OTHER, DB::table('game')->where('id', self::GAME)->value('owner_user_id'));
        $this->assertSame(0, CommunityPoints::total(self::USER));
        $this->assertSame(100, CommunityPoints::total(self::OTHER));
    }

    public function test_a_declined_request_leaves_the_owners_100_alone(): void
    {
        $this->game(self::OTHER);
        $this->ask(1, self::USER);
        $this->ask(2, self::OTHER);
        $this->decide(2, true);
        $this->assertSame(100, CommunityPoints::total(self::OTHER));

        $this->decide(1, false);

        $this->assertSame('declined', DB::table('game_ownership_request')->where('id', 1)->value('state'));
        $this->assertSame(self::OTHER, DB::table('game')->where('id', self::GAME)->value('owner_user_id'));
        $this->assertSame(100, CommunityPoints::total(self::OTHER));
        $this->assertSame(0, CommunityPoints::total(self::USER));
    }
}
