<?php

namespace Tests\Feature\Replays;

use App\Domain\Mirrors\B64;
use App\Domain\Mirrors\Signing;
use App\Domain\Replays\ReplayListing;
use App\Domain\Replays\ReplaySummary;
use App\Supabase\Supabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Moderation\ModerationTestCase;
use Tests\Unit\Mirrors\MirrorFixtures;

/** How replays appear in the signed catalogue (#676). */
class ReplayCatalogueTest extends ModerationTestCase
{
    use MirrorFixtures;
    use RefreshDatabase;

    private const USER = '11111111-1111-1111-1111-111111111111';

    private const GAME_ID = '9f2c4b1ad07e5386c1f4a92b6e8d0375';

    private const OTHER_ID = '0a2c4b1ad07e5386c1f4a92b6e8d0375';

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
            'app.url' => 'https://hub.test',
        ]);
        DB::table('mirror')->insert([
            'id' => 'jj-darkstars', 'name' => 'jj.darkstars.co.uk', 'base_url' => 'https://mirror.test',
            'public_key' => self::fixtureKeys()['mirror_public_key'], 'enabled' => true, 'healthy' => true,
            'takes_replays' => true, 'takes_engines' => true, 'created_at' => '2026-11-17 12:00:00.000000',
        ]);
        Queue::fake();
        $this->supabase(['/auth/v1/user' => fn () => self::reply(['id' => self::USER])]);
    }

    private function replay(string $gameId, string $state, string $sha): void
    {
        DB::table('replay')->insert([
            'game_id' => $gameId, 'state' => $state, 'uploader' => self::USER, 'sha256' => $sha, 'size' => 684112,
            'upload_mirror_id' => 'jj-darkstars', 'upload_id' => '33333333-3333-4333-8333-333333333333', 'summary' => '{}',
            'reserved_until' => null, 'received_at' => null,
            'created_at' => '2026-11-26 09:00:00.000000', 'updated_at' => '2026-11-26 09:00:00.000000',
        ]);
    }

    private function archive(string $sha, string $state): void
    {
        DB::table('archive')->insert([
            'id' => 'dddddddd-0000-4000-8000-000000000001', 'sha256' => $sha, 'filename' => 'Map.sd7', 'filename_lower' => 'map.sd7',
            'kind' => 'map', 'internal_name' => 'Map', 'version' => '', 'size' => 43, 'uploader' => self::USER, 'state' => $state,
            'upload_mirror_id' => 'jj-darkstars', 'listed_at' => '2026-11-18 10:00:00.000000',
            'created_at' => '2026-11-18 09:00:00.000000', 'updated_at' => '2026-11-18 10:00:00.000000',
        ]);
    }

    /** The catalogue as mirrors fetch it, with its signature checked. */
    private function catalogue(): array
    {
        $body = $this->get('/api/v1/catalogue')->assertOk()->getContent();
        $sig = $this->get('/api/v1/catalogue.sig')->assertOk()->getContent();
        $this->assertTrue(Signing::verify(Signing::CTX_CATALOGUE, $body, B64::decode($sig), B64::decode(self::fixtureKeys()['hub_public_key'])));

        return json_decode($body, true);
    }

    private static function sha(string $digit): string
    {
        return str_repeat($digit, 64);
    }

    public function test_a_listed_replay_is_in_replays(): void
    {
        $this->replay(self::GAME_ID, 'listed', self::sha('a'));

        $catalogue = $this->catalogue();

        $this->assertSame([['filename' => self::GAME_ID.'.sdfz', 'sha256' => self::sha('a'), 'size' => 684112]], $catalogue['replays']);
        $this->assertSame([], $catalogue['removed']);
    }

    public function test_replays_still_uploading_or_checking_are_left_out(): void
    {
        $this->replay(self::GAME_ID, 'uploading', self::sha('a'));
        $this->replay(self::OTHER_ID, 'checking', self::sha('b'));

        $catalogue = $this->catalogue();

        $this->assertArrayNotHasKey('replays', $catalogue);
        $this->assertSame([], $catalogue['removed']);
    }

    public function test_publishing_a_checked_replay_makes_a_newer_catalogue_that_lists_it(): void
    {
        $this->replay(self::GAME_ID, 'checking', self::sha('a'));
        $before = $this->catalogue();

        $this->assertTrue(ReplayListing::publish(self::GAME_ID));

        $after = $this->catalogue();
        $this->assertGreaterThan($before['seq'], $after['seq']);
        $this->assertSame([self::GAME_ID.'.sdfz'], array_column($after['replays'], 'filename'));
    }

    public function test_only_a_replay_being_checked_is_published(): void
    {
        $this->replay(self::GAME_ID, 'uploading', self::sha('a'));

        $this->assertFalse(ReplayListing::publish(self::GAME_ID));
        $this->assertSame('uploading', DB::table('replay')->value('state'));
    }

    public function test_a_removed_replay_moves_to_removed_in_a_newer_catalogue_matched_by_hash_alone(): void
    {
        $this->replay(self::GAME_ID, 'listed', self::sha('a'));
        $before = $this->catalogue();

        $this->assertTrue(ReplayListing::remove(self::GAME_ID, 'rights'));

        $after = $this->catalogue();
        $this->assertGreaterThan($before['seq'], $after['seq']);
        $this->assertArrayNotHasKey('replays', $after);
        // A mirror also matches removals by name, so the name must be one no upload can have.
        $this->assertSame([self::GAME_ID.'.sdfz.removed'], array_column($after['removed'], 'filename'));
        $this->assertSame([self::sha('a')], array_column($after['removed'], 'sha256'));
        $this->assertSame(['rights'], array_column($after['removed'], 'reason'));
        $this->assertIsInt($after['removed'][0]['removed_at']);
    }

    public function test_a_removal_outlives_the_freed_game_id(): void
    {
        $this->replay(self::GAME_ID, 'checking', self::sha('a'));
        ReplayListing::remove(self::GAME_ID, 'refused');
        DB::table('replay')->delete();
        $this->replay(self::GAME_ID, 'listed', self::sha('b'));
        $this->catalogue();

        $catalogue = $this->catalogue();

        $this->assertSame([self::sha('b')], array_column($catalogue['replays'], 'sha256'));
        $this->assertSame([self::sha('a')], array_column($catalogue['removed'], 'sha256'));
    }

    public function test_a_replay_that_is_not_there_to_remove_changes_nothing(): void
    {
        $this->replay(self::GAME_ID, 'uploading', self::sha('a'));

        $this->assertFalse(ReplayListing::remove(self::GAME_ID, 'refused'));
        $this->assertFalse(ReplayListing::remove(self::OTHER_ID, 'refused'));
        $this->assertSame(0, DB::table('replay_removal')->count());
    }

    public function test_sending_the_same_file_again_lifts_its_removal(): void
    {
        $this->replay(self::GAME_ID, 'checking', self::sha('a'));
        ReplayListing::remove(self::GAME_ID, 'refused');
        DB::table('replay')->delete();
        $this->assertCount(1, $this->catalogue()['removed']);

        $this->send(self::GAME_ID, self::sha('a'))->assertCreated();

        $this->assertSame([], $this->catalogue()['removed']);
    }

    public function test_a_reservation_that_moves_off_a_removed_file_brings_its_removal_back(): void
    {
        $this->replay(self::GAME_ID, 'checking', self::sha('a'));
        ReplayListing::remove(self::GAME_ID, 'refused');
        DB::table('replay')->delete();
        $this->send(self::GAME_ID, self::sha('a'))->assertCreated();
        $this->assertSame([], $this->catalogue()['removed']);

        $this->send(self::GAME_ID, self::sha('b'))->assertOk();

        $this->assertSame([self::sha('a')], array_column($this->catalogue()['removed'], 'sha256'));
    }

    public function test_a_refused_copy_of_a_file_another_replay_holds_is_not_removed(): void
    {
        $this->replay(self::GAME_ID, 'listed', self::sha('a'));
        $this->replay(self::OTHER_ID, 'checking', self::sha('a'));

        ReplayListing::remove(self::OTHER_ID, 'refused');

        $this->assertSame([], $this->catalogue()['removed']);
    }

    public function test_a_refused_replay_never_removes_an_archive_with_the_same_hash(): void
    {
        $this->archive(self::sha('a'), 'listed');
        $this->replay(self::GAME_ID, 'checking', self::sha('a'));

        ReplayListing::remove(self::GAME_ID, 'refused');

        $this->assertSame([], $this->catalogue()['removed']);
    }

    public function test_uploading_an_archive_with_a_refused_replays_hash_lifts_the_removal(): void
    {
        $this->replay(self::GAME_ID, 'checking', self::sha('a'));
        ReplayListing::remove(self::GAME_ID, 'refused');
        $this->assertCount(1, $this->catalogue()['removed']);

        $result = Supabase::admin()->rpc('begin_archive_upload', [
            'p_user' => self::USER, 'p_is_moderator' => true, 'p_reservation_seconds' => 3600,
            'p_facts' => [
                'kind' => 'engine', 'sha256' => self::sha('a'), 'filename' => 'recoil_1_amd64-linux.7z', 'size' => 43,
                'internal_name' => 'recoil', 'version' => '1', 'platform' => 'amd64-linux',
            ],
        ]);

        $this->assertSame('created', $result['outcome']);
        $this->assertSame([], $this->catalogue()['removed']);
    }

    private function send(string $gameId, string $sha): TestResponse
    {
        $totals = array_fill_keys(ReplaySummary::TOTALS, 0);
        $summary = [
            'v' => 1, 'engine' => '2025.04.08', 'game' => 'Beyond All Reason test-27000-abc', 'map' => 'Supreme Isthmus v2.1',
            'started_at' => 1794787260, 'duration' => 1312,
            'players' => [['name' => 'Alice', 'team' => 0, 'ally_team' => 0, 'faction' => 'Armada']],
            'spectators' => [], 'winners' => [0], 'totals' => [['team' => 0] + $totals], 'start_positions' => [],
            'build_orders' => [], 'units' => [],
        ];

        return $this->call('POST', '/api/v1/replays/uploads', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer alice', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['game_id' => $gameId, 'sha256' => $sha, 'size' => 684112, 'name' => 'Alice', 'summary' => $summary]));
    }
}
