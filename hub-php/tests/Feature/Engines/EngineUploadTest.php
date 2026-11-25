<?php

namespace Tests\Feature\Engines;

use App\Domain\Engines\EngineUploaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Moderation\ModerationTestCase;
use Tests\Unit\Mirrors\MirrorFixtures;

/** Who may upload an engine, and which mirror takes it. */
class EngineUploadTest extends ModerationTestCase
{
    use MirrorFixtures;
    use RefreshDatabase;

    private const USER = '11111111-1111-1111-1111-111111111111';

    private const OTHER = '22222222-2222-2222-2222-222222222222';

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
            'takes_engines' => true, 'created_at' => '2026-11-17 12:00:00.000000',
        ]);
        DB::table('map')->insert([
            'id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'map_name' => 'Comet Catcher 1.8', 'slug' => 'comet-catcher',
            'width_elmos' => 6144, 'height_elmos' => 6144, 'world_height_min' => -10.0, 'world_height_max' => 300.0,
            'appearance' => '{}', 'curated_tags' => '[]', 'source_hash' => 'h', 'source_archive' => 'Comet Catcher 1.8',
            'catalog_version' => 1, 'facts_digest' => 'd', 'owner_user_id' => self::USER,
            'seen_at' => '2026-10-01 10:00:00.000000', 'created_at' => '2026-10-01 10:00:00.000000', 'updated_at' => '2026-10-01 10:00:00.000000',
        ]);
    }

    /** @param array<string, mixed> $facts */
    private function upload(array $facts = []): TestResponse
    {
        return $this->call('POST', '/api/v1/uploads', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer tok', 'CONTENT_TYPE' => 'application/json'], json_encode($facts + [
            'sha256' => str_repeat('a', 64), 'size' => 43, 'filename' => 'recoil_2026.09.03_arm64-macos.7z', 'kind' => 'engine',
            'version' => '2026.09.03', 'platform' => 'arm64-macos', 'note' => 'Test build from the fix-lua branch.',
        ]));
    }

    private function fakeUploader(bool $moderator = false): void
    {
        $this->supabase([
            '/auth/v1/user' => self::reply(['id' => self::USER]),
            'POST /rpc/is_moderator' => self::reply($moderator),
        ]);
    }

    private function grant(string $user = self::USER): void
    {
        EngineUploaders::grant($user, self::OTHER);
    }

    public function test_a_grant_holder_uploads_an_engine(): void
    {
        $this->grant();
        $this->fakeUploader();

        $id = $this->upload()->assertCreated()->json('upload.id');

        $row = DB::table('archive')->where('id', $id)->first();
        $this->assertSame('engine', $row->kind);
        $this->assertSame('arm64-macos', $row->platform);
        $this->assertSame('recoil', $row->internal_name);
        $this->assertSame('2026.09.03', $row->version);
        $this->assertSame('Test build from the fix-lua branch.', $row->description);
    }

    public function test_a_moderator_uploads_an_engine_without_a_grant(): void
    {
        $this->fakeUploader(moderator: true);

        $this->upload()->assertCreated();

        $this->assertSame(1, DB::table('archive')->where('kind', 'engine')->count());
    }

    public function test_anyone_else_is_refused_with_403(): void
    {
        $this->grant(self::OTHER);
        $this->fakeUploader();

        $this->upload()->assertStatus(403)
            ->assertJsonPath('reason', 'not_engine_uploader')
            ->assertJsonPath('error', "You can't upload engines. Ask a moderator for the engine uploader grant.");

        $this->assertSame(0, DB::table('archive')->count());
    }

    public function test_only_a_mirror_that_takes_engines_is_chosen(): void
    {
        DB::table('mirror')->insert([
            'id' => 'other', 'name' => 'other', 'base_url' => 'https://other.test',
            'public_key' => self::fixtureKeys()['mirror_public_key'], 'enabled' => true, 'healthy' => true,
            'takes_engines' => false, 'created_at' => '2026-11-17 12:00:00.000000',
        ]);
        $this->grant();
        $this->fakeUploader();

        foreach (range(1, 10) as $i) {
            $this->upload([
                'sha256' => str_repeat(dechex($i), 64),
                'filename' => 'recoil_2026.09.0'.$i.'_arm64-macos.7z',
                'version' => '2026.09.0'.$i,
            ])->assertCreated()->assertJsonPath('upload.upload_url', 'https://mirror.test/upload.php');
        }
    }

    public function test_no_mirror_taking_engines_is_503(): void
    {
        DB::table('mirror')->update(['takes_engines' => false]);
        $this->grant();
        $this->fakeUploader();

        $this->upload()->assertStatus(503)
            ->assertJsonPath('reason', 'no_engine_mirror')
            ->assertJsonPath('error', 'No mirror takes engines yet.');
    }

    public function test_a_map_upload_still_uses_any_healthy_mirror(): void
    {
        DB::table('mirror')->update(['takes_engines' => false]);
        $this->fakeUploader();

        $this->upload([
            'kind' => 'map', 'filename' => 'Comet Catcher 1.9.sd7', 'internal_name' => 'Comet Catcher 1.8',
            'version' => '1.9', 'platform' => null,
        ])->assertCreated()->assertJsonPath('upload.mirror', 'jj-darkstars');
    }

    public function test_the_same_version_on_another_platform_is_not_a_duplicate(): void
    {
        $this->grant();
        $this->fakeUploader();

        $this->upload()->assertCreated();
        $this->upload([
            'sha256' => str_repeat('b', 64), 'filename' => 'recoil_2026.09.03_amd64-linux.7z', 'platform' => 'amd64-linux',
        ])->assertCreated();

        $this->assertSame(2, DB::table('archive')->count());
    }

    public function test_the_same_version_and_platform_is_a_duplicate_linking_the_engine_page(): void
    {
        $this->grant();
        $this->fakeUploader();
        DB::table('archive')->insert([
            'id' => 'dddddddd-0000-4000-8000-000000000001', 'sha256' => str_repeat('c', 64),
            'filename' => 'recoil_2026.09.03_arm64-macos_old.7z', 'filename_lower' => 'recoil_2026.09.03_arm64-macos_old.7z',
            'kind' => 'engine', 'internal_name' => 'recoil', 'version' => '2026.09.03', 'platform' => 'arm64-macos',
            'description' => 'x', 'size' => 43, 'uploader' => self::OTHER, 'state' => 'listed',
            'upload_mirror_id' => 'jj-darkstars', 'listed_at' => '2026-11-18 10:00:00.000000',
            'created_at' => '2026-11-18 09:00:00.000000', 'updated_at' => '2026-11-18 10:00:00.000000',
        ]);

        $this->upload()->assertStatus(409)
            ->assertJsonPath('duplicate.check', 'name_version')
            ->assertJsonPath('duplicate.url', 'https://hub.test/engines/2026.09.03/arm64-macos');
    }

    public function test_a_revoked_grant_stops_uploads(): void
    {
        $this->grant();
        $this->fakeUploader();
        $this->upload()->assertCreated();

        $this->assertTrue(EngineUploaders::revoke(self::USER));
        $this->upload([
            'sha256' => str_repeat('b', 64), 'filename' => 'recoil_2026.09.04_arm64-macos.7z', 'version' => '2026.09.04',
        ])->assertStatus(403)->assertJsonPath('reason', 'not_engine_uploader');
    }

    public function test_a_revoked_grant_stops_a_reissued_token(): void
    {
        $this->grant();
        $this->fakeUploader();
        $this->upload()->assertCreated();

        EngineUploaders::revoke(self::USER);

        $this->upload()->assertStatus(403)->assertJsonPath('reason', 'not_engine_uploader');
    }

    public function test_a_reissue_needs_a_mirror_that_takes_engines(): void
    {
        $this->grant();
        $this->fakeUploader();
        $this->upload()->assertCreated();

        DB::table('mirror')->update(['takes_engines' => false]);

        $this->upload()->assertStatus(503)->assertJsonPath('reason', 'no_engine_mirror');
    }

    public function test_grants_are_listed_newest_first_and_repeats_are_reported(): void
    {
        $this->assertFalse(EngineUploaders::has(self::USER));
        $this->assertFalse(EngineUploaders::has(null));
        $this->assertTrue(EngineUploaders::grant(self::USER, self::OTHER));
        $this->assertFalse(EngineUploaders::grant(self::USER, self::OTHER));
        $this->assertTrue(EngineUploaders::has(self::USER));
        $this->assertFalse(EngineUploaders::revoke(self::OTHER));

        DB::table('engine_uploader')->where('user_id', self::USER)->update(['granted_at' => '2026-01-01 00:00:00.000000']);
        EngineUploaders::grant(self::OTHER, self::USER);

        $this->assertSame([self::OTHER, self::USER], array_column(EngineUploaders::all(), 'user_id'));
        $this->assertSame(self::OTHER, EngineUploaders::all()[1]['granted_by']);
    }
}
