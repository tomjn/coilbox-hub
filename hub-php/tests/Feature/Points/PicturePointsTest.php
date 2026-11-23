<?php

namespace Tests\Feature\Points;

use App\Domain\Assets\AssetResolver;
use App\Domain\Assets\Upload;
use App\Domain\Points\CommunityPoints;
use App\Supabase\Local\Values;
use App\Supabase\Supabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Moderation\ModerationTestCase;

/** An approved picture earns 20 points, or 1 for a unit picture, and loses them when it stops being approved or changes hands. */
class PicturePointsTest extends ModerationTestCase
{
    use RefreshDatabase;

    private const UPLOADER = '11111111-1111-1111-1111-111111111111';

    private const REPLACER = '22222222-2222-2222-2222-222222222222';

    private const ASSET = 'aaaaaaaa-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.pictures_store' => 'mariadb', 'hub.games_store' => 'mariadb', 'hub.asset_cdn_base' => null]);
        Storage::fake('staged');
    }

    /** @param array<string, mixed> $over */
    private function asset(array $over = []): void
    {
        DB::table('asset')->insert([
            'id' => self::ASSET, 'game' => 'BYAR', 'unit_name' => null, 'map_name' => null, 'faction_key' => 'armada', 'variant' => 'logo',
            'source_hash' => 'raw-abc', 'hash' => str_repeat('a', 64), 'encode_profile' => 'webp-lossless-256',
            'path' => 'units/BYAR/logo/'.str_repeat('a', 64).'.webp', 'origin' => 'extracted', 'tier' => 'bucket',
            'mime' => 'image/webp', 'bytes' => 40, 'width' => 128, 'height' => 128, 'map_width' => null, 'map_height' => null,
            'source_archive' => 'byar_1.2.sdz', 'seen_at' => '2026-11-01 10:00:00.000000', 'promoted_at' => null,
            'uploaded_by' => self::UPLOADER, 'moderation' => 'pending', 'approval_source' => null,
            'created_at' => '2026-11-01 10:00:00.000000', 'updated_at' => Values::now(), 'world_height_min' => null,
            'world_height_max' => null, 'rejection_kind' => null, 'blob_path' => null, 'blob_path_tier' => null,
            'bytes_missing_at' => null, ...$over,
        ]);
    }

    public function test_an_upload_that_skips_the_queue_gives_20(): void
    {
        $this->supabase();
        $declaration = [
            'sourceHash' => 'raw-abc', 'encodeProfile' => 'webp-lossless-256', 'origin' => 'extracted', 'mime' => 'image/webp',
            'bytes' => 40, 'mapWidth' => null, 'mapHeight' => null, 'worldHeightMin' => null, 'worldHeightMax' => null,
            'sourceArchive' => 'byar_1.2.sdz', 'identity' => AssetResolver::faction('BYAR', 'armada'),
        ];

        $id = Upload::write(Supabase::admin(), self::UPLOADER, $declaration, str_repeat('b', 64), 'p.webp', ['width' => 64, 'height' => 64], null, true);

        $this->assertNotNull($id);
        $this->assertSame('approved', DB::table('asset')->value('moderation'));
        $this->assertSame(20, CommunityPoints::total(self::UPLOADER));
    }

    public function test_a_pending_upload_gives_nothing_until_a_moderator_approves_it(): void
    {
        $this->supabase();
        $this->asset();
        Supabase::admin()->from('asset')->update(['bytes' => 41])->eq('id', self::ASSET)->execute();
        $this->assertSame(0, CommunityPoints::total(self::UPLOADER));

        $this->signedIn()->post('/moderation/assets/approve', ['asset' => [self::ASSET]]);

        $this->assertSame(20, CommunityPoints::total(self::UPLOADER));
    }

    public function test_an_approved_unit_picture_gives_1(): void
    {
        $this->supabase();
        $this->asset(['unit_name' => 'armsolar', 'faction_key' => null, 'variant' => 'buildpic']);

        $this->signedIn()->post('/moderation/assets/approve', ['asset' => [self::ASSET]]);

        $this->assertSame(1, CommunityPoints::total(self::UPLOADER));
    }

    public function test_rejecting_an_approved_picture_takes_the_points_back(): void
    {
        $this->supabase();
        $this->asset(['moderation' => 'approved', 'approval_source' => 'moderator']);
        CommunityPoints::award(self::UPLOADER, CommunityPoints::PICTURE, self::ASSET);

        $this->signedIn()->post('/moderation/assets/reject', ['asset' => self::ASSET, 'kind' => 'editorial']);

        $this->assertSame(0, CommunityPoints::total(self::UPLOADER));
    }

    public function test_a_replacement_moves_the_points_to_the_new_uploader_once_approved(): void
    {
        $this->supabase(['GET /rest/v1/user_capability' => self::reply([['user_id' => self::REPLACER]])]);
        $this->asset(['moderation' => 'approved', 'approval_source' => 'moderator']);
        CommunityPoints::award(self::UPLOADER, CommunityPoints::PICTURE, self::ASSET);

        Supabase::admin()->from('asset')->update(['uploaded_by' => self::REPLACER, 'moderation' => 'pending', 'approval_source' => null])->eq('id', self::ASSET)->execute();
        $this->assertSame([0, 0], [CommunityPoints::total(self::UPLOADER), CommunityPoints::total(self::REPLACER)]);

        $this->signedIn()->post('/moderation/assets/approve', ['asset' => [self::ASSET]]);

        $this->assertSame([0, 20], [CommunityPoints::total(self::UPLOADER), CommunityPoints::total(self::REPLACER)]);
    }
}
