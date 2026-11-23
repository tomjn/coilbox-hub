<?php

namespace Tests\Feature\Mirrors;

use App\Domain\Mirrors\Catalogue;
use App\Domain\Mirrors\MirrorCopy;
use App\Supabase\Local\MirrorStore;
use App\Supabase\Supabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Moderation\ModerationTestCase;
use Tests\Unit\Mirrors\MirrorFixtures;

/**
 * The hub mirrors read and written in MariaDB once they have moved (#600):
 * reports, the catalogue, uploads, removals, mismatch reports, the moderation
 * pages and the mirror copies listed on map and game pages, with Supabase
 * asked only for what has not moved.
 */
class LocalMirrorsTest extends ModerationTestCase
{
    use MirrorFixtures;
    use RefreshDatabase;

    private const USER = '11111111-1111-1111-1111-111111111111';

    private const GAME = 'bbbbbbbb-0000-4000-8000-000000000001';

    private const MAP = 'aaaaaaaa-0000-4000-8000-000000000001';

    private const ARCHIVE = 'dddddddd-0000-4000-8000-000000000001';

    /** The first hash the fixture report holds. */
    private const HELD = '58648994035e7d2b82ee74b1aa5a66e772655bddf03156fc4ee439a1b8872ef2';

    private const MIRROR_MODERATION_FUNCTIONS = '#/rest/v1/(mirror|archive|catalogue_state)|/rpc/(record_mirror_report|set_mirror_health|catalogue_source|store_catalogue|moderation_|begin_archive_upload|archive_upload_status|remove_archive|dismiss_archive_mismatches|report_archive_mismatch|my_archives|map_mirror_|game_mirror_sources)#';

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
        DB::table('game')->insert([
            'id' => self::GAME, 'shortname' => 'bar', 'display_name' => 'Beyond All Reason', 'links' => '[]', 'conquest_factions' => '[]',
            'owner_user_id' => self::USER, 'created_at' => '2026-10-01 10:00:00.000000', 'updated_at' => '2026-10-01 10:00:00.000000',
        ]);
        DB::table('map')->insert([
            'id' => self::MAP, 'map_name' => 'Comet Catcher 1.8', 'slug' => 'comet-catcher', 'width_elmos' => 6144, 'height_elmos' => 6144,
            'world_height_min' => -10.0, 'world_height_max' => 300.0, 'appearance' => '{}', 'curated_tags' => '[]', 'source_hash' => 'h',
            'source_archive' => 'Comet Catcher 1.8', 'catalog_version' => 1, 'facts_digest' => 'd', 'owner_user_id' => self::USER,
            'seen_at' => '2026-10-01 10:00:00.000000', 'created_at' => '2026-10-01 10:00:00.000000', 'updated_at' => '2026-10-01 10:00:00.000000',
        ]);
    }

    /** @param array<string, mixed> $over */
    private function archive(array $over = []): void
    {
        $filename = $over['filename'] ?? 'Comet Catcher 1.8.sd7';
        DB::table('archive')->insert($over + [
            'id' => self::ARCHIVE, 'sha256' => self::HELD, 'filename' => $filename, 'filename_lower' => mb_strtolower($filename),
            'kind' => 'map', 'internal_name' => 'Comet Catcher 1.8', 'version' => '', 'shortname' => null, 'author' => 'Beherith',
            'description' => null, 'size' => 43, 'uploader' => self::USER, 'state' => 'listed', 'game_id' => null,
            'upload_mirror_id' => 'jj-darkstars', 'reserved_until' => null, 'listed_at' => '2026-11-18 10:00:00.000000',
            'created_at' => '2026-11-18 09:00:00.000000', 'updated_at' => '2026-11-18 10:00:00.000000',
        ]);
    }

    private function held(string $archiveId = self::ARCHIVE): void
    {
        DB::table('archive_mirror')->insert([
            'archive_id' => $archiveId, 'mirror_id' => 'jj-darkstars',
            'first_seen_at' => '2026-11-18 10:00:00.000000', 'last_seen_at' => '2026-11-18 10:00:00.000000',
        ]);
    }

    /** @return list<Request> requests that reached Supabase's mirror tables or functions */
    private function mirrorRequests(): array
    {
        return array_values(array_filter($this->sent, fn (Request $r) => preg_match(self::MIRROR_MODERATION_FUNCTIONS, urldecode($r->url())) === 1));
    }

    private function report(): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/api/v1/mirrors/report', [], [], [], ['CONTENT_TYPE' => 'application/json'], self::fixture('report-upload.json'));
    }

    public function test_a_report_lists_the_upload_records_the_copy_checks_the_mirror_and_signs_a_catalogue(): void
    {
        DB::table('mirror')->update(['healthy' => false]);
        $this->archive(['state' => 'uploading', 'listed_at' => null, 'reserved_until' => '2099-01-01 00:00:00.000000']);
        $this->supabase(['HEAD mirror.test/maps/' => Http::response('', 200, ['Content-Length' => '43'])]);

        $this->report()->assertOk()->assertExactJson(['ok' => true]);

        $archive = DB::table('archive')->where('id', self::ARCHIVE)->first();
        $this->assertSame('listed', $archive->state);
        $this->assertNotNull($archive->listed_at);
        $this->assertNull($archive->reserved_until);
        $this->assertSame(1, DB::table('archive_mirror')->where('archive_id', self::ARCHIVE)->count());
        $mirror = DB::table('mirror')->first();
        $this->assertTrue((bool) $mirror->healthy);
        $this->assertSame(1794787260, (int) $mirror->last_report_sent_at);
        $this->assertSame(1, (int) $mirror->last_catalogue_seq);
        $this->assertNotNull($mirror->last_report_at);
        $this->assertNotNull($mirror->last_check_at);

        $state = DB::table('catalogue_state')->first();
        $this->assertSame(1, (int) $state->seq);
        $this->assertSame((int) $state->changes, (int) $state->built_changes);
        $catalogue = json_decode($state->body, true);
        $this->assertSame([self::HELD], array_column($catalogue['archives'], 'sha256'));
        $this->assertSame([], $this->mirrorRequests());
    }

    public function test_an_older_report_is_stale_and_changes_nothing(): void
    {
        DB::table('mirror')->update(['last_report_sent_at' => 1794787261]);
        $this->supabase();

        $this->report()->assertStatus(409)->assertExactJson(['error' => 'stale']);

        $this->assertNull(DB::table('mirror')->value('last_report_at'));
    }

    public function test_a_report_revives_a_lapsed_upload_unless_another_took_its_name(): void
    {
        $this->archive(['state' => 'failed', 'listed_at' => null]);
        $this->archive([
            'id' => 'dddddddd-0000-4000-8000-000000000002', 'sha256' => 'cffcb652ffa611d21fe7be0ec6419ee164f895279faea58a42d15b5c8152036e',
            'filename' => 'Taken.sd7', 'internal_name' => 'Taken', 'state' => 'failed', 'listed_at' => null,
        ]);
        $this->archive([
            'id' => 'dddddddd-0000-4000-8000-000000000003', 'sha256' => str_repeat('e', 64), 'filename' => 'Other.sd7',
            'internal_name' => 'Taken', 'state' => 'listed', 'upload_mirror_id' => null,
        ]);
        $this->supabase(['HEAD mirror.test' => Http::response('', 200, ['Content-Length' => '43'])]);

        $this->report()->assertOk();

        $this->assertSame('listed', DB::table('archive')->where('id', self::ARCHIVE)->value('state'));
        $this->assertSame('failed', DB::table('archive')->where('id', 'dddddddd-0000-4000-8000-000000000002')->value('state'));
    }

    public function test_the_catalogue_lists_mirrors_archives_and_removals_in_order(): void
    {
        $this->archive(['listed_at' => '2026-11-18 10:00:00.000000']);
        $this->archive([
            'id' => 'dddddddd-0000-4000-8000-000000000002', 'sha256' => str_repeat('b', 64), 'filename' => 'bar-1.sdz',
            'kind' => 'game', 'internal_name' => 'Beyond All Reason', 'version' => '1', 'shortname' => 'bar', 'game_id' => self::GAME,
            'listed_at' => '2026-11-17 10:00:00.000000',
        ]);
        $this->archive([
            'id' => 'dddddddd-0000-4000-8000-000000000003', 'sha256' => str_repeat('c', 64), 'filename' => 'Gone.sd7',
            'internal_name' => 'Gone', 'state' => 'removed', 'removal_reason' => 'rights', 'removed_at' => '2026-11-18 11:00:00.900000',
        ]);
        $this->supabase();

        $source = Supabase::admin()->rpc('catalogue_source');
        $body = Catalogue::encode($source, 1794787200, null);

        $this->assertSame(
            '{"v":1,"seq":1,"generated_at":1794787200,'
            .'"mirrors":[{"id":"jj-darkstars","base_url":"https://mirror.test","public_key":"'.self::fixtureKeys()['mirror_public_key'].'"}],'
            .'"archives":[{"filename":"bar-1.sdz","kind":"game","sha256":"'.str_repeat('b', 64).'","size":43,"internal_name":"Beyond All Reason","version":"1","shortname":"bar","author":"Beherith","description":null},'
            .'{"filename":"Comet Catcher 1.8.sd7","kind":"map","sha256":"'.self::HELD.'","size":43,"internal_name":"Comet Catcher 1.8","version":"","shortname":null,"author":"Beherith","description":null}],'
            .'"removed":[{"sha256":"'.str_repeat('c', 64).'","filename":"Gone.sd7","reason":"rights","removed_at":1794999600}]}',
            $body,
        );
        $this->assertSame(2, (int) DB::table('catalogue_state')->value('next_seq'));
        $this->assertSame(2, Supabase::admin()->rpc('catalogue_source')['seq']);
    }

    public function test_the_catalogue_endpoint_signs_once_and_rebuilds_after_a_change(): void
    {
        $this->archive();
        $this->supabase();

        $first = $this->get('/api/v1/catalogue')->assertOk()->getContent();
        $this->assertSame($first, $this->get('/api/v1/catalogue')->assertOk()->getContent());
        $this->assertSame(1, (int) DB::table('catalogue_state')->value('seq'));

        $this->signedIn()->post('/moderation/archives/remove', ['archive_id' => self::ARCHIVE, 'reason' => 'broken']);
        $this->assertSame('removed', DB::table('archive')->where('id', self::ARCHIVE)->value('state'));

        $this->get('/api/v1/catalogue')->assertOk();
        $this->assertSame(2, (int) DB::table('catalogue_state')->value('seq'));
        $this->assertSame([], $this->mirrorRequests());
    }

    private function upload(array $facts): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/api/v1/uploads', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer tok', 'CONTENT_TYPE' => 'application/json'], json_encode($facts + [
            'sha256' => str_repeat('a', 64), 'size' => 43, 'filename' => 'Comet Catcher 1.9.sd7', 'kind' => 'map',
            'internal_name' => 'Comet Catcher 1.8', 'version' => '1.9', 'shortname' => null, 'author' => 'someone', 'description' => null,
        ]));
    }

    private function fakeUploader(bool $moderator = false): void
    {
        $this->supabase([
            '/auth/v1/user' => self::reply(['id' => self::USER]),
            'POST /rpc/is_moderator' => self::reply($moderator),
        ]);
    }

    public function test_an_upload_is_reserved_then_reissued_then_checked(): void
    {
        $this->fakeUploader();

        $created = $this->upload([])->assertCreated()->json('upload');
        $this->assertSame('jj-darkstars', $created['mirror']);
        $this->assertSame('https://mirror.test/upload.php', $created['upload_url']);
        $row = DB::table('archive')->where('id', $created['id'])->first();
        $this->assertSame('uploading', $row->state);
        $this->assertSame(self::USER, $row->uploader);
        $this->assertSame('comet catcher 1.9.sd7', $row->filename_lower);
        $this->assertEqualsWithDelta(time() + 86400, $created['reserved_until'], 2);

        $again = $this->upload([])->assertOk()->json('upload');
        $this->assertSame($created['id'], $again['id']);
        $this->assertSame(1, DB::table('archive')->count());

        $this->call('GET', '/api/v1/uploads/'.$created['id'], [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer tok'])
            ->assertOk()->assertJsonPath('upload.state', 'uploading')->assertJsonPath('upload.filename', 'Comet Catcher 1.9.sd7');
        $this->assertSame([], $this->mirrorRequests());
    }

    public function test_uploads_are_refused_as_the_database_function_refused_them(): void
    {
        $this->archive(['uploader' => null]);
        $this->fakeUploader();

        $this->upload(['filename' => 'COMET CATCHER 1.8.SD7'])->assertStatus(409)
            ->assertJsonPath('duplicate.check', 'filename')->assertJsonPath('duplicate.url', 'https://hub.test/maps/comet-catcher');
        $this->upload(['internal_name' => 'Nowhere'])->assertStatus(422)->assertJsonPath('reason', 'unknown_map');
        $this->upload(['kind' => 'game', 'shortname' => 'nope', 'filename' => 'nope.sdz'])->assertStatus(422)->assertJsonPath('reason', 'unknown_game');
        DB::table('game')->update(['owner_user_id' => null]);
        $this->upload(['kind' => 'game', 'shortname' => 'bar', 'filename' => 'bar.sdz'])->assertStatus(403)->assertJsonPath('reason', 'not_owner');
        DB::table('mirror')->update(['healthy' => false]);
        $this->upload([])->assertStatus(503)->assertJsonPath('reason', 'no_mirror');
    }

    public function test_a_lapsed_reservation_frees_its_hash_for_another_upload(): void
    {
        $this->archive(['state' => 'uploading', 'uploader' => self::MODERATOR, 'sha256' => str_repeat('a', 64), 'reserved_until' => '2020-01-01 00:00:00.000000', 'listed_at' => null]);
        $this->fakeUploader();

        $this->upload(['filename' => 'Other name.sd7', 'version' => '2'])->assertCreated();

        $this->assertSame('failed', DB::table('archive')->where('id', self::ARCHIVE)->value('state'));
    }

    public function test_the_moderation_page_shows_a_mirror_health_after_its_report(): void
    {
        $this->supabase(['HEAD mirror.test' => Http::response('', 200, ['Content-Length' => '43'])]);
        $this->archive(['state' => 'uploading', 'listed_at' => null, 'reserved_until' => '2099-01-01 00:00:00.000000']);
        DB::table('mirror')->update(['healthy' => false]);
        $this->report()->assertOk();

        $this->signedIn()->get('/moderation/mirrors')->assertOk()
            ->assertSeeInOrder(['jj.darkstars.co.uk', 'Healthy', '1 archive']);
        $this->assertSame([], $this->mirrorRequests());
    }

    public function test_a_moderator_adds_removes_and_switches_mirrors_back_on(): void
    {
        $this->supabase();
        $key = self::fixtureKeys()['mirror_public_key'];
        $changes = fn () => (int) DB::table('catalogue_state')->value('changes');

        $this->signedIn()->post('/moderation/mirrors/add', ['id' => 'second', 'name' => 'Second', 'base_url' => 'https://second.test/', 'public_key' => $key]);
        $added = DB::table('mirror')->where('id', 'second')->first();
        $this->assertSame('https://second.test', $added->base_url);
        $this->assertFalse((bool) $added->healthy);
        $this->assertSame(1, $changes());

        $this->signedIn()->post('/moderation/mirrors/add', ['id' => 'second', 'name' => 'Again', 'base_url' => 'https://x.test', 'public_key' => $key])
            ->assertSessionHas('mirrorsState', ['ok' => false, 'message' => 'Another mirror already has that ID.']);

        $this->signedIn()->post('/moderation/mirrors/remove', ['mirror_id' => 'second']);
        $this->assertFalse(DB::table('mirror')->where('id', 'second')->exists());

        $this->archive();
        $this->signedIn()->post('/moderation/mirrors/remove', ['mirror_id' => 'jj-darkstars']);
        $this->assertFalse((bool) DB::table('mirror')->where('id', 'jj-darkstars')->value('enabled'));
        $this->signedIn()->post('/moderation/mirrors/enable', ['mirror_id' => 'jj-darkstars']);
        $mirror = DB::table('mirror')->where('id', 'jj-darkstars')->first();
        $this->assertTrue((bool) $mirror->enabled);
        $this->assertFalse((bool) $mirror->healthy);
        $this->assertSame(4, $changes());
    }

    public function test_a_stranger_changes_no_mirror(): void
    {
        $this->supabase(moderator: false);

        $this->signedIn()->post('/moderation/mirrors/remove', ['mirror_id' => 'jj-darkstars']);
        $this->signedIn()->post('/moderation/mirrors/add', ['id' => 'x', 'name' => 'X', 'base_url' => 'https://x.test', 'public_key' => self::fixtureKeys()['mirror_public_key']]);

        $this->assertSame(1, DB::table('mirror')->count());
        $this->assertTrue((bool) DB::table('mirror')->value('enabled'));
    }

    public function test_mismatch_reports_are_recorded_listed_and_dismissed(): void
    {
        $this->archive();
        $this->held();
        $this->supabase(['/rest/v1/profile' => self::reply([['user_id' => self::USER, 'display_name' => 'Uploader']])]);
        $send = fn (array $found, string $ip = '203.0.113.5') => $this->call('POST', '/api/v1/archives/mismatch', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode(['sha256' => self::HELD, 'found' => $found]));

        $send(['version' => '', 'kind' => 'map'])->assertStatus(422)->assertJsonPath('reason', 'agrees');
        $send(['version' => '2', 'kind' => 'map'])->assertStatus(202);
        $send(['version' => '3'])->assertOk()->assertJsonPath('outcome', 'already');
        $this->assertSame(['version' => '2'], json_decode(DB::table('archive_mismatch')->value('found'), true));

        $this->signedIn()->get('/moderation/archives')->assertOk()
            ->assertSeeInOrder(['Comet Catcher 1.8.sd7', '2', 'Uploader'])
            ->assertSee('https://mirror.test/maps/Comet%20Catcher%201.8.sd7', false)
            ->assertSee('/map/comet-catcher', false);

        $this->signedIn()->post('/moderation/archives/dismiss', ['archive_id' => self::ARCHIVE]);
        $report = DB::table('archive_mismatch')->first();
        $this->assertSame('dismissed', $report->handled_how);
        $this->assertNull($report->ip);
        $this->assertSame([], $this->mirrorRequests());
    }

    public function test_an_uploader_withdraws_their_archive_and_a_stranger_cannot(): void
    {
        $this->archive(['uploader' => self::MODERATOR]);
        $this->supabase(moderator: false);

        $this->signedIn()->get('/account/uploads')->assertOk()->assertSee('Comet Catcher 1.8.sd7');
        $this->signedIn()->post('/moderation/archives/remove', ['archive_id' => self::ARCHIVE, 'reason' => 'broken']);
        $this->assertSame('listed', DB::table('archive')->where('id', self::ARCHIVE)->value('state'));

        $this->signedIn()->post('/account/uploads/withdraw', ['archive_id' => self::ARCHIVE]);
        $archive = DB::table('archive')->where('id', self::ARCHIVE)->first();
        $this->assertSame('removed', $archive->state);
        $this->assertSame('withdrawn', $archive->removal_reason);
        $this->assertSame(self::MODERATOR, $archive->removed_by);
    }

    public function test_map_and_game_pages_list_the_healthy_mirror_copies(): void
    {
        $this->archive();
        $this->held();
        $this->archive([
            'id' => 'dddddddd-0000-4000-8000-000000000002', 'sha256' => str_repeat('b', 64), 'filename' => 'bar-1.sdz',
            'kind' => 'game', 'internal_name' => 'Beyond All Reason', 'version' => '1', 'shortname' => 'bar', 'game_id' => self::GAME,
        ]);
        $this->held('dddddddd-0000-4000-8000-000000000002');
        $this->supabase();
        $copy = ['kind' => 'url', 'value' => 'https://mirror.test/maps/Comet%20Catcher%201.8.sd7', 'sha256' => self::HELD, 'filename' => 'Comet Catcher 1.8.sd7'];

        $this->assertSame([$copy], Supabase::anon()->rpc('map_mirror_sources', ['p_map_name' => 'Comet Catcher 1.8']));
        $this->assertSame($copy, Supabase::admin()->rpc('map_facts', ['p_names' => ['Comet Catcher 1.8']])[0]['facts']['downloads'][0]);
        $browse = Supabase::anon()->from('map_browse')->select('map_name,uploaded_at,mirror_urls')->get();
        $this->assertSame([['map_name' => 'Comet Catcher 1.8', 'uploaded_at' => '2026-11-18T10:00:00+00:00', 'mirror_urls' => [$copy['value']]]], $browse);
        $game = Supabase::anon()->from('game_browse')->select('shortname,downloads')->get();
        $this->assertSame('https://mirror.test/games/bar-1.sdz', $game[0]['downloads'][0]['value']);

        DB::table('mirror')->update(['healthy' => false]);
        $this->assertSame([], Supabase::anon()->rpc('map_mirror_sources', ['p_map_name' => 'Comet Catcher 1.8']));
        $this->assertSame([], $this->mirrorRequests());
    }

    public function test_a_game_page_offers_its_newest_upload_from_each_mirror_and_lists_older_ones(): void
    {
        DB::table('mirror')->insert([
            'id' => 'second', 'name' => 'second.test', 'base_url' => 'https://second.test',
            'public_key' => self::fixtureKeys()['mirror_public_key'], 'enabled' => true, 'healthy' => true,
            'created_at' => '2026-11-17 12:00:00.000000',
        ]);
        $older = 'dddddddd-0000-4000-8000-000000000002';
        $newer = 'dddddddd-0000-4000-8000-000000000003';
        $game = ['kind' => 'game', 'internal_name' => 'Beyond All Reason', 'shortname' => 'bar', 'game_id' => self::GAME];
        $this->archive($game + ['id' => $older, 'sha256' => str_repeat('b', 64), 'filename' => 'bar-1.sdz', 'version' => '1']);
        $this->archive($game + [
            'id' => $newer, 'sha256' => str_repeat('c', 64), 'filename' => 'bar-2.sdz', 'version' => '2',
            'listed_at' => '2026-11-19 10:00:00.000000',
        ]);
        $this->held($older);
        $this->held($newer);
        // The page counts the game's items, and production reads those from MariaDB too.
        config(['hub.items_store' => 'mariadb']);
        DB::table('archive_mirror')->insert([
            'archive_id' => $newer, 'mirror_id' => 'second',
            'first_seen_at' => '2026-11-19 10:00:00.000000', 'last_seen_at' => '2026-11-19 10:00:00.000000',
        ]);
        $this->supabase();

        $this->get('/games/bar')->assertOk()->assertSeeInOrder([
            '/games/bar/download/0', 'Download bar-2.sdz from mirror.test',
            '/games/bar/download/1', 'Download bar-2.sdz from second.test',
            'Older versions', 'Version 1', '/games/bar/download/2', 'bar-1.sdz from mirror.test',
        ], false);

        $this->get('/games/bar/download/0')->assertRedirect('https://mirror.test/games/bar-2.sdz');
        $this->get('/games/bar/download/1')->assertRedirect('https://second.test/games/bar-2.sdz');
        $this->get('/games/bar/download/2')->assertRedirect('https://mirror.test/games/bar-1.sdz');
        $this->get('/games/bar/download/3')->assertNotFound();
    }

    public function test_the_map_mirror_status_is_for_its_editors_only(): void
    {
        $this->archive();
        $this->held();
        $this->supabase(moderator: false);
        $session = Supabase::forToken('access', self::USER);

        $status = $session->rpc('map_mirror_status', ['p_map_id' => self::MAP]);
        $this->assertSame([[
            'mine' => true, 'state' => 'listed',
            'mirrors' => [['name' => 'jj.darkstars.co.uk', 'linked' => true, 'last_seen_at' => '2026-11-18T10:00:00+00:00']],
            'filename' => 'Comet Catcher 1.8.sd7', 'listed_at' => '2026-11-18T10:00:00+00:00', 'created_at' => '2026-11-18T09:00:00+00:00',
            'removed_at' => null, 'removal_reason' => null,
        ]], $status);
        $this->assertNull(Supabase::forToken('access', self::MODERATOR)->rpc('map_mirror_status', ['p_map_id' => self::MAP]));
    }

    public function test_the_mirror_tables_are_closed_to_browsers(): void
    {
        $this->supabase();

        foreach (['mirror', 'archive', 'catalogue_state'] as $table) {
            try {
                Supabase::anon()->from($table)->select('*')->get();
                $this->fail('anon read '.$table);
            } catch (\App\Supabase\SupabaseException $e) {
                $this->assertSame('42501', $e->errorCode);
            }
        }
        $this->assertCount(1, Supabase::admin()->from('mirror')->select('id')->get());
        $this->expectException(\App\Supabase\SupabaseException::class);
        Supabase::admin()->from('archive')->select('id')->get();
    }

    public function test_a_game_write_no_longer_reaches_supabase_once_pictures_and_mirrors_have_moved(): void
    {
        $this->supabase();

        \App\Supabase\Local\Shadow::push([self::GAME]);
        $this->assertSame([], array_filter($this->sent, fn (Request $r) => str_contains($r->url(), '/rest/v1/game')));

        config(['hub.mirrors_store' => 'supabase']);
        $this->supabase(['POST /rest/v1/game' => self::reply([])]);
        \App\Supabase\Local\Shadow::push([self::GAME]);
        $this->assertCount(1, array_filter($this->sent, fn (Request $r) => str_contains($r->url(), '/rest/v1/game')));
    }

    public function test_the_copy_waits_for_games_and_maps_then_copies_and_switches(): void
    {
        config(['hub.mirrors_store' => null, 'hub.maps_store' => 'supabase']);
        DB::table('mirror')->delete();
        $this->supabase();

        $this->artisan('mirrors:copy')->expectsOutputToContain('Move maps first, with maps:copy.')->assertExitCode(1);
        $this->assertSame(MirrorStore::SUPABASE, MirrorStore::fresh());

        config(['hub.maps_store' => 'mariadb']);
        $rows = [
            'mirror' => [[
                'id' => 'jj-darkstars', 'name' => 'jj', 'base_url' => 'https://mirror.test', 'public_key' => self::fixtureKeys()['mirror_public_key'],
                'enabled' => true, 'healthy' => true, 'last_report_at' => '2026-11-18T10:00:00.5+00:00', 'last_report_sent_at' => 1794787260,
                'last_catalogue_seq' => 4, 'last_catalogue_generated_at' => 1794787200, 'last_check_at' => null, 'created_at' => '2026-11-17T12:00:00+00:00',
            ]],
            'archive' => [[
                'id' => self::ARCHIVE, 'sha256' => self::HELD, 'filename' => 'A.sd7', 'filename_lower' => 'a.sd7', 'kind' => 'map',
                'internal_name' => 'Comet Catcher 1.8', 'version' => '', 'shortname' => null, 'author' => null, 'description' => null, 'size' => 43,
                'uploader' => self::USER, 'state' => 'listed', 'game_id' => null, 'upload_mirror_id' => 'jj-darkstars', 'reserved_until' => null,
                'listed_at' => '2026-11-18T10:00:00+00:00', 'removed_at' => null, 'removal_reason' => null, 'removed_by' => null,
                'created_at' => '2026-11-18T09:00:00+00:00', 'updated_at' => '2026-11-18T10:00:00+00:00', 'removal_note' => null,
            ]],
            'archive_mirror' => [['archive_id' => self::ARCHIVE, 'mirror_id' => 'jj-darkstars', 'first_seen_at' => '2026-11-18T10:00:00+00:00', 'last_seen_at' => '2026-11-18T10:00:00+00:00']],
            'archive_mismatch' => [[
                'id' => 7, 'archive_id' => self::ARCHIVE, 'found' => ['version' => '2'], 'ip' => '203.0.113.5', 'created_at' => '2026-11-18T11:00:00+00:00',
                'handled_at' => null, 'handled_by' => null, 'handled_how' => null,
            ]],
            'catalogue_state' => [[
                'id' => true, 'next_seq' => 9, 'changes' => 12, 'built_changes' => 12, 'seq' => 8, 'generated_at' => 1794787200,
                'body' => '{"v":1}', 'sig' => 'sig', 'next_key_public' => null, 'next_key_since_seq' => null,
            ]],
        ];
        $this->supabase(['/rest/v1/' => function (Request $r) use ($rows) {
            preg_match('#/rest/v1/(\w+)\?#', $r->url(), $m);
            $table = $m[1];
            $range = ['Content-Range' => '0-0/'.count($rows[$table])];
            if ($r->method() === 'HEAD') {
                return Http::response('', 200, $range);
            }

            return self::reply(str_contains($r->url(), 'offset=0') ? $rows[$table] : [], 200, $range);
        }]);

        $this->artisan('mirrors:copy --dry-run')->expectsOutputToContain('archive: Supabase 1, MariaDB 0')->assertExitCode(0);
        $this->assertSame(0, DB::table('archive')->count());

        $this->artisan('mirrors:copy')->expectsOutputToContain('The hub now reads and writes the mirrors in MariaDB.')->assertExitCode(0);
        $this->assertSame(MirrorStore::MARIADB, MirrorStore::fresh());
        $this->assertSame('2026-11-18 10:00:00.500000', DB::table('mirror')->value('last_report_at'));
        $this->assertSame(9, (int) DB::table('catalogue_state')->value('next_seq'));
        $this->assertSame(['version' => '2'], json_decode(DB::table('archive_mismatch')->value('found'), true));
        $this->assertSame(1, (int) DB::table('archive')->value('live'));

        $this->artisan('mirrors:copy')->expectsOutputToContain('already reads the mirrors from MariaDB')->assertExitCode(1);
        $this->artisan('maps:store supabase')->expectsOutputToContain('Run mirrors:store supabase first.')->assertExitCode(1);
        $this->artisan('games:store supabase')->expectsOutputToContain('Run mirrors:store supabase first.')->assertExitCode(1);
    }

    public function test_going_back_pushes_every_game_and_map_to_supabase_first(): void
    {
        config(['hub.mirrors_store' => null]);
        MirrorStore::set(MirrorStore::MARIADB);
        $this->supabase(['POST /rest/v1/game' => self::reply([]), 'POST /rest/v1/map?' => self::reply([])]);

        $this->artisan('mirrors:store supabase')->expectsOutputToContain('The hub reads the mirrors from supabase.')->assertExitCode(0);

        $this->assertSame(MirrorStore::SUPABASE, MirrorStore::fresh());
        $pushed = array_values(array_filter($this->sent, fn (Request $r) => str_contains($r->url(), '/rest/v1/game')));
        $this->assertCount(1, $pushed);
        $this->assertSame(self::GAME, $pushed[0]->data()[0]['id']);
        $maps = array_values(array_filter($this->sent, fn (Request $r) => str_contains($r->url(), '/rest/v1/map?')));
        $this->assertCount(1, $maps);
        $this->assertSame(self::MAP, $maps[0]->data()[0]['id']);
        $this->assertSame(null, MirrorCopy::waitingFor());
    }
}
