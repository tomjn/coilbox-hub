<?php

namespace Tests\Feature\Engines;

use App\Domain\Engines\EngineUploaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Moderation\ModerationTestCase;
use Tests\Unit\Mirrors\MirrorFixtures;

/** The public engine list Coilbox reads, and the profile flag that shows the upload form. */
class EngineApiTest extends ModerationTestCase
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
            'app.url' => 'https://hub.test',
        ]);
        DB::table('mirror')->insert([
            'id' => 'jj-darkstars', 'name' => 'jj.darkstars.co.uk', 'base_url' => 'https://mirror.test',
            'public_key' => self::fixtureKeys()['mirror_public_key'], 'enabled' => true, 'healthy' => true,
            'takes_engines' => true, 'created_at' => '2026-11-17 12:00:00.000000',
        ]);
    }

    private function engine(string $version, string $platform, string $listedAt, string $state = 'listed', string $kind = 'engine', ?string $mirror = 'jj-darkstars'): string
    {
        $id = (string) Str::uuid();
        $filename = 'recoil_'.$version.'_'.$platform.'.7z';
        DB::table('archive')->insert([
            'id' => $id, 'kind' => $kind, 'internal_name' => 'recoil', 'version' => $version, 'platform' => $platform,
            'filename' => $filename, 'filename_lower' => strtolower($filename), 'sha256' => hash('sha256', $filename),
            'size' => 43, 'uploader' => self::USER, 'state' => $state, 'description' => 'Test build.',
            'listed_at' => $listedAt, 'created_at' => $listedAt, 'updated_at' => $listedAt,
        ]);
        if ($mirror !== null) {
            DB::table('archive_mirror')->insert(['archive_id' => $id, 'mirror_id' => $mirror, 'first_seen_at' => $listedAt, 'last_seen_at' => $listedAt]);
        }

        return $id;
    }

    public function test_the_api_lists_engines_with_their_sources_newest_first(): void
    {
        $this->engine('2026.09.03', 'arm64-macos', '2026-11-18 10:00:00.000000');
        $this->engine('2026.10.01', 'amd64-linux', '2026-11-19 10:00:00.000000');

        $response = $this->getJson('/api/v1/engines')->assertOk();

        $this->assertSame([
            'engines' => [
                [
                    'version' => '2026.10.01', 'platform' => 'amd64-linux', 'filename' => 'recoil_2026.10.01_amd64-linux.7z',
                    'size' => 43, 'sha256' => hash('sha256', 'recoil_2026.10.01_amd64-linux.7z'), 'note' => 'Test build.', 'listed_at' => '2026-11-19T10:00:00Z',
                    'page_url' => 'https://hub.test/engines/2026.10.01/amd64-linux',
                    'sources' => ['https://mirror.test/engines/recoil_2026.10.01_amd64-linux.7z'],
                ],
                [
                    'version' => '2026.09.03', 'platform' => 'arm64-macos', 'filename' => 'recoil_2026.09.03_arm64-macos.7z',
                    'size' => 43, 'sha256' => hash('sha256', 'recoil_2026.09.03_arm64-macos.7z'), 'note' => 'Test build.', 'listed_at' => '2026-11-18T10:00:00Z',
                    'page_url' => 'https://hub.test/engines/2026.09.03/arm64-macos',
                    'sources' => ['https://mirror.test/engines/recoil_2026.09.03_arm64-macos.7z'],
                ],
            ],
        ], $response->json());
    }

    public function test_the_api_filters_by_platform(): void
    {
        $this->engine('2026.09.03', 'arm64-macos', '2026-11-18 10:00:00.000000');
        $this->engine('2026.10.01', 'amd64-linux', '2026-11-19 10:00:00.000000');

        $response = $this->getJson('/api/v1/engines?platform=amd64-linux')->assertOk();

        $this->assertSame(['2026.10.01'], array_column($response->json('engines'), 'version'));
    }

    public function test_an_unknown_platform_is_400(): void
    {
        $this->getJson('/api/v1/engines?platform=beos')->assertStatus(400)->assertJsonStructure(['error']);
    }

    public function test_an_engine_no_healthy_mirror_holds_is_left_out(): void
    {
        $this->engine('2026.09.03', 'arm64-macos', '2026-11-18 10:00:00.000000', mirror: null);
        $this->engine('2026.09.04', 'arm64-macos', '2026-11-18 11:00:00.000000');
        DB::table('mirror')->update(['healthy' => false]);

        $this->assertSame([], $this->getJson('/api/v1/engines')->assertOk()->json('engines'));
    }

    public function test_removed_engines_and_maps_are_not_listed(): void
    {
        $this->engine('2026.09.03', 'arm64-macos', '2026-11-18 10:00:00.000000', state: 'removed');
        $this->engine('2026.09.04', 'arm64-macos', '2026-11-18 11:00:00.000000', kind: 'map');

        $this->assertSame([], $this->getJson('/api/v1/engines')->assertOk()->json('engines'));
    }

    public function test_the_api_needs_no_sign_in_and_allows_any_origin(): void
    {
        $this->getJson('/api/v1/engines')->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');
        $this->call('OPTIONS', '/api/v1/engines')->assertStatus(204)->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_profile_says_whether_the_caller_can_upload_engines(): void
    {
        $profile = function (bool $moderator) {
            $this->supabase([
                '/auth/v1/user' => self::reply(['id' => self::USER]),
                'POST /rpc/is_moderator' => self::reply($moderator),
            ]);

            return $this->call('GET', '/api/v1/profile', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer tok'])->assertOk()->json('can_upload_engines');
        };

        $this->assertFalse($profile(false));
        EngineUploaders::grant(self::USER, self::OTHER);
        $this->assertTrue($profile(false));
        EngineUploaders::revoke(self::USER);
        $this->assertTrue($profile(true));
    }
}
