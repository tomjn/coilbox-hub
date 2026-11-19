<?php

namespace Tests\Feature\Assets;

use App\Domain\Assets\SourceConflict;
use App\Supabase\Local\PictureStore;
use App\Supabase\Local\StagedObjects;
use App\Supabase\Local\Values;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Moderation\ModerationTestCase;
use Tests\Unit\Assets\ImageAndCapsTest;

/**
 * Pictures read and written in MariaDB, and staged pictures on the disk, once
 * they have moved (#598): uploads, moderation, the triggers and functions, and
 * serving, with Supabase asked only for what has not moved.
 */
class LocalPicturesTest extends ModerationTestCase
{
    use RefreshDatabase;

    private const UPLOADER = '11111111-1111-1111-1111-111111111111';

    private const ASSET = 'aaaaaaaa-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.pictures_store' => 'mariadb', 'hub.asset_cdn_base' => null]);
        Storage::fake('staged');
    }

    /** @param array<string, mixed> $over */
    private function asset(array $over = []): array
    {
        $row = [
            'id' => self::ASSET, 'game' => 'BYAR', 'unit_name' => 'armsolar', 'map_name' => null, 'variant' => 'buildpic',
            'source_hash' => 'raw-abc', 'hash' => str_repeat('a', 64), 'encode_profile' => 'webp-lossless-256',
            'path' => 'units/BYAR/buildpic/'.str_repeat('a', 64).'.webp', 'origin' => 'extracted', 'tier' => 'bucket',
            'mime' => 'image/webp', 'bytes' => 40, 'width' => 128, 'height' => 128, 'map_width' => null, 'map_height' => null,
            'source_archive' => 'byar_1.2.sdz', 'seen_at' => '2026-11-01 10:00:00.000000', 'promoted_at' => null,
            'uploaded_by' => self::UPLOADER, 'moderation' => 'pending', 'approval_source' => null,
            'created_at' => '2026-11-01 10:00:00.000000', 'updated_at' => Values::now(), 'world_height_min' => null,
            'world_height_max' => null, 'rejection_kind' => null, 'blob_path' => null, 'blob_path_tier' => null,
            'bytes_missing_at' => null, 'faction_key' => null, ...$over,
        ];
        DB::table('asset')->insert($row);

        return $row;
    }

    private function stage(string $path, string $bytes): void
    {
        StagedObjects::upload($path, $bytes, 'image/webp', false);
    }

    /** @return list<Request> requests that reached Supabase's picture tables or storage */
    private function pictureRequests(): array
    {
        return array_values(array_filter($this->sent, fn (Request $r) => preg_match('#/rest/v1/(asset|staged_object)|/storage/v1/|/rpc/(approve_assets|reject_asset|return_asset|account_asset_bytes)#', $r->url()) === 1));
    }

    public function test_an_upload_lands_on_the_disk_and_in_mariadb_without_asking_supabase_for_pictures(): void
    {
        $this->supabase([
            'GET /auth/v1/user' => self::reply(['id' => self::UPLOADER]),
            'POST /rpc/has_capability' => self::reply(false),
        ]);
        $bytes = ImageAndCapsTest::webp(128, 128, true);
        $hash = hash('sha256', $bytes);
        $declaration = json_encode([
            'keyed_on' => 'unit', 'game' => 'BYAR', 'unit_name' => 'armsolar', 'variant' => 'buildpic',
            'source_hash' => 'raw-abc', 'encode_profile' => 'webp-lossless-256', 'origin' => 'extracted',
            'mime' => 'image/webp', 'bytes' => strlen($bytes), 'source_archive' => 'byar_1.2.sdz',
        ]);

        $this->withHeaders(['Authorization' => 'Bearer token'])
            ->post('/api/v1/assets/upload', ['asset' => $declaration, 'file' => UploadedFile::fake()->createWithContent('b.webp', $bytes)])
            ->assertStatus(201)
            ->assertJson(['moderation' => 'pending']);

        $path = 'units/BYAR/buildpic/'.$hash.'.webp';
        $this->assertSame($bytes, Storage::disk('staged')->get($path));
        $this->assertSame(strlen($bytes), (int) DB::table('staged_object')->where('path', $path)->value('bytes'));
        $row = DB::table('asset')->first();
        $this->assertSame(['pending', 'bucket', $path, self::UPLOADER], [$row->moderation, $row->tier, $row->path, $row->uploaded_by]);
        $this->assertSame(1, DB::table('asset_upload_ip')->where('asset_id', $row->id)->count());
        // A first upload arriving pending is on the row already, so no event.
        $this->assertSame(0, DB::table('asset_event')->count());
        $this->assertSame([], $this->pictureRequests());
    }

    public function test_an_upload_during_the_copy_is_refused_with_a_503(): void
    {
        config(['hub.pictures_store' => 'frozen']);
        $this->supabase([
            'GET /auth/v1/user' => self::reply(['id' => self::UPLOADER]),
            'POST /rpc/has_capability' => self::reply(false),
            'POST /rpc/account_asset_bytes' => self::reply(0),
            'GET /rest/v1/asset' => Http::response('[]', 200, ['Content-Range' => '*/0']),
        ]);
        $bytes = ImageAndCapsTest::webp(128, 128, true);
        $declaration = json_encode([
            'keyed_on' => 'unit', 'game' => 'BYAR', 'unit_name' => 'armsolar', 'variant' => 'buildpic',
            'source_hash' => 'raw-abc', 'encode_profile' => 'webp-lossless-256', 'origin' => 'extracted',
            'mime' => 'image/webp', 'bytes' => strlen($bytes), 'source_archive' => 'byar_1.2.sdz',
        ]);

        $this->withHeaders(['Authorization' => 'Bearer token'])
            ->post('/api/v1/assets/upload', ['asset' => $declaration, 'file' => UploadedFile::fake()->createWithContent('b.webp', $bytes)])
            ->assertStatus(503);

        $this->assertSame(0, DB::table('staged_object')->count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/storage/v1/'));
    }

    public function test_a_moderator_approves_and_the_event_names_them_and_the_address_goes(): void
    {
        $this->supabase();
        $this->asset();
        DB::table('asset_upload_ip')->insert(['asset_id' => self::ASSET, 'ip' => '203.0.113.7', 'at' => Values::now()]);

        $this->signedIn()->post('/moderation/assets/approve', ['asset' => [self::ASSET]])->assertRedirect('/moderation/assets');

        $row = DB::table('asset')->find(self::ASSET);
        $this->assertSame(['approved', 'moderator'], [$row->moderation, $row->approval_source]);
        $event = DB::table('asset_event')->sole();
        $this->assertSame(['approved', self::MODERATOR, self::UPLOADER], [$event->action, $event->actor, $event->uploader]);
        $this->assertSame(0, DB::table('asset_upload_ip')->count());
        $this->assertSame([], $this->pictureRequests());
    }

    public function test_somebody_who_does_not_moderate_changes_nothing(): void
    {
        $this->supabase(moderator: false);
        $this->asset();

        try {
            Supabase::forToken('access', self::MODERATOR)->rpc('approve_assets', ['ids' => [self::ASSET]]);
            $this->fail('Expected a refusal');
        } catch (SupabaseException $e) {
            $this->assertSame('42501', $e->errorCode);
        }
        $this->assertSame('pending', DB::table('asset')->value('moderation'));
    }

    public function test_a_safety_rejection_of_a_promoted_picture_names_the_uploader_lists_a_takedown_and_is_final(): void
    {
        $this->supabase([
            'GET /rest/v1/profile' => self::reply([['display_name' => 'Uploader']]),
            'GET /auth/v1/admin/users/' => self::reply(['id' => self::UPLOADER, 'user_metadata' => ['user_name' => ' gh-name ']]),
        ]);
        $this->asset(['moderation' => 'approved', 'approval_source' => 'moderator', 'tier' => 'static', 'path' => 'units/BYAR/static.webp']);

        $this->signedIn()->post('/moderation/assets/reject', ['asset' => self::ASSET, 'kind' => 'safety']);

        $event = DB::table('asset_event')->sole();
        $this->assertSame(['rejected', 'safety', 'Uploader', 'gh-name'], [$event->action, $event->rejection_kind, $event->uploader_display_name, $event->uploader_provider_name]);
        $this->assertSame('units/BYAR/static.webp', DB::table('asset_withdrawal')->where('asset_id', self::ASSET)->value('path'));

        try {
            Supabase::admin()->from('asset')->update(['path' => 'elsewhere.webp'])->eq('id', self::ASSET)->execute();
            $this->fail('Expected the safety rejection to hold');
        } catch (SupabaseException $e) {
            $this->assertSame('23514', $e->errorCode);
        }
        $this->assertFalse(Supabase::forToken('access', self::MODERATOR)->rpc('return_asset', ['asset_id' => self::ASSET]));
    }

    public function test_returning_works_until_an_editorial_rejection_lets_go_of_its_staged_bytes(): void
    {
        $this->supabase();
        $this->asset(['moderation' => 'rejected', 'rejection_kind' => 'editorial']);
        $moderator = Supabase::forToken('access', self::MODERATOR);

        $this->assertTrue($moderator->rpc('return_asset', ['asset_id' => self::ASSET]));
        $row = DB::table('asset')->find(self::ASSET);
        $this->assertSame(['pending', null], [$row->moderation, $row->rejection_kind]);
        $this->assertSame('returned', DB::table('asset_event')->value('action'));

        DB::table('asset')->where('id', self::ASSET)->update([
            'moderation' => 'rejected', 'rejection_kind' => 'editorial', 'updated_at' => now('UTC')->subDays(15)->format('Y-m-d H:i:s.u'),
        ]);
        $this->assertFalse($moderator->rpc('return_asset', ['asset_id' => self::ASSET]));
    }

    public function test_rejecting_a_live_side_logo_clears_it_from_the_side(): void
    {
        config(['hub.games_store' => 'mariadb']);
        $this->supabase();
        $game = '00000000-0000-4000-8000-0000000000aa';
        DB::table('game')->insert(['id' => $game, 'shortname' => 'BYAR', 'links' => '[]', 'conquest_factions' => '[]', 'created_at' => Values::now(), 'updated_at' => Values::now()]);
        DB::table('game_faction')->insert(['game_id' => $game, 'key' => 'arm', 'name' => 'Arm', 'logo_path' => 'factions/arm.webp', 'logo_hash' => 'h']);
        $this->asset(['unit_name' => null, 'faction_key' => 'arm', 'variant' => 'logo', 'moderation' => 'approved', 'approval_source' => 'moderator', 'tier' => 'static', 'path' => 'factions/arm.webp']);

        $this->assertTrue(Supabase::forToken('access', self::MODERATOR)->rpc('reject_asset', ['asset_id' => self::ASSET, 'kind' => 'editorial']));

        $this->assertNull(DB::table('game_faction')->value('logo_path'));
    }

    public function test_anon_sees_approved_pictures_only_and_none_of_the_moderation_tables(): void
    {
        $this->supabase();
        $this->asset();
        $this->asset(['id' => 'aaaaaaaa-0000-4000-8000-000000000002', 'unit_name' => 'armmex', 'moderation' => 'approved', 'approval_source' => 'seed', 'world_height_min' => null]);

        $this->assertSame([['unit_name' => 'armmex', 'bytes' => 40]], Supabase::anon()->from('asset')->select('unit_name,bytes')->get());
        try {
            Supabase::anon()->from('asset_event')->select('id')->get();
            $this->fail('Expected permission denied');
        } catch (SupabaseException $e) {
            $this->assertSame('42501', $e->errorCode);
        }
        $this->assertCount(2, Supabase::admin()->from('asset')->select('id')->get());
    }

    public function test_the_trail_reads_events_with_their_picture(): void
    {
        $this->supabase();
        $this->asset();
        DB::table('asset_event')->insert(['asset_id' => self::ASSET, 'action' => 'approved', 'actor' => self::MODERATOR, 'uploader' => self::UPLOADER, 'at' => '2026-11-02 10:00:00.5']);

        $rows = Supabase::admin()->from('asset_event')->select('action,at,asset(id,unit_name)')->order('at', ascending: false)->get();

        $this->assertSame([['action' => 'approved', 'at' => '2026-11-02T10:00:00.5+00:00', 'asset' => ['id' => self::ASSET, 'unit_name' => 'armsolar']]], $rows);
    }

    public function test_a_disagreement_is_recorded_once_listed_and_cleared(): void
    {
        $this->supabase();
        $this->asset(['moderation' => 'approved', 'approval_source' => 'moderator']);
        $conflict = ['assetId' => self::ASSET, 'sourceArchive' => 'byar_1.3.sdz', 'heldSourceHash' => 'raw-abc', 'reportedSourceHash' => 'raw-def', 'reportedBy' => self::UPLOADER];

        $this->assertTrue(SourceConflict::record(Supabase::admin(), $conflict));
        $this->assertTrue(SourceConflict::record(Supabase::admin(), $conflict));
        $this->assertSame(1, DB::table('asset_source_conflict')->count());

        $moderator = Supabase::forToken('access', self::MODERATOR);
        $listed = $moderator->rpc('approved_source_conflicts', ['p_limit' => 10]);
        $this->assertSame([self::ASSET, 'raw-def', 1], [$listed[0]['asset_id'], $listed[0]['reported_source_hash'], $listed[0]['total']]);

        $this->assertTrue($moderator->rpc('acknowledge_source_conflict', ['p_conflict_id' => $listed[0]['conflict_id']]));
        $this->assertFalse($moderator->rpc('acknowledge_source_conflict', ['p_conflict_id' => $listed[0]['conflict_id']]));
        $this->assertSame([], $moderator->rpc('approved_source_conflicts', ['p_limit' => 10]));
        $this->assertSame('conflict_acknowledged', DB::table('asset_event')->value('action'));
    }

    public function test_the_meters_and_the_sweep_list_read_the_disk_and_the_games(): void
    {
        $this->supabase([
            'GET /rest/v1/game' => self::reply([['logo_path' => 'games/BYAR/logo.webp', 'logo_staged_tier' => 'bucket', 'banner_path' => null, 'banner_staged_tier' => null]]),
        ]);
        $this->asset(['bytes' => 40]);
        $this->asset(['id' => 'aaaaaaaa-0000-4000-8000-000000000002', 'unit_name' => 'armmex', 'tier' => 'static', 'moderation' => 'approved', 'approval_source' => 'seed', 'bytes' => 7, 'path' => 'static.webp']);
        $this->stage($this->asset(['id' => 'aaaaaaaa-0000-4000-8000-000000000003', 'unit_name' => 'armwin', 'path' => 'units/held.webp'])['path'], 'held');
        $this->stage('units/orphan.webp', 'orphan!');
        $this->stage('games/BYAR/logo.webp', 'logo');
        $admin = Supabase::admin();

        $this->assertSame(80, $admin->rpc('account_asset_bytes', ['account' => self::UPLOADER]));
        $this->assertSame(15, $admin->rpc('staged_pictures_bucket_bytes'));
        $this->assertSame([
            ['tier' => 'bucket', 'variant' => 'buildpic', 'objects' => 2, 'bytes' => 80],
            ['tier' => 'static', 'variant' => 'buildpic', 'objects' => 1, 'bytes' => 7],
        ], $admin->rpc('asset_storage_usage'));
        $unclaimed = $admin->rpc('unclaimed_staged_objects', ['max_objects' => 10]);
        $this->assertSame(['units/orphan.webp'], array_column($unclaimed, 'object_path'));
        $this->assertSame(7, $unclaimed[0]['bytes']);
    }

    public function test_an_approved_staged_picture_is_served_from_the_disk_with_the_same_headers(): void
    {
        $this->supabase();
        $row = $this->asset(['moderation' => 'approved', 'approval_source' => 'moderator']);
        $this->stage($row['path'], 'webp bytes');

        $this->get('/assets/staged/'.$row['path'])
            ->assertOk()
            ->assertContent('webp bytes')
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        DB::table('asset')->update(['moderation' => 'pending', 'approval_source' => null]);
        $this->get('/assets/staged/'.$row['path'])->assertNotFound();
        $this->assertSame([], $this->pictureRequests());
    }

    public function test_the_bucket_refuses_to_overwrite_and_paths_that_leave_its_folder(): void
    {
        $this->stage('units/a.webp', 'one');
        try {
            $this->stage('units/a.webp', 'two');
            $this->fail('Expected a 409');
        } catch (SupabaseException $e) {
            $this->assertSame(409, $e->status);
        }
        StagedObjects::upload('units/a.webp', 'three', 'image/webp', true);
        $this->assertSame('three', StagedObjects::download('units/a.webp'));
        $this->assertNull(StagedObjects::download('../secret'));
        $this->expectException(SupabaseException::class);
        StagedObjects::upload('units/../../escape', 'x', 'image/webp', true);
    }

    public function test_deleting_an_account_clears_it_from_its_pictures(): void
    {
        $this->asset();
        DB::table('asset')->update(['uploaded_by' => self::MODERATOR]);
        $this->supabase([
            'GET /rest/v1/profile' => self::reply(['display_name' => 'Mod']),
            'DELETE /auth/v1/admin/users/' => self::reply([]),
            'POST /auth/v1/logout' => self::reply([]),
        ]);

        $this->signedIn()->post('/account/delete', ['confirm' => 'Mod']);

        $this->assertNull(DB::table('asset')->value('uploaded_by'));
    }

    public function test_the_store_follows_data_move_when_nothing_fixes_it(): void
    {
        config(['hub.pictures_store' => null]);
        $this->assertSame(PictureStore::SUPABASE, PictureStore::fresh());
        PictureStore::set(PictureStore::MARIADB);
        $this->assertSame(PictureStore::MARIADB, PictureStore::fresh());
        $this->assertSame('supabase', DB::table('data_move')->where('area', 'games')->value('state') ?? 'supabase');
    }
}
