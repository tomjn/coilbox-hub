<?php

namespace Tests\Feature\Engines;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The archive key now includes the platform, so one engine version can ship for each platform. */
class EngineSchemaTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $over */
    private function archive(string $id, array $over = []): void
    {
        $filename = $over['filename'] ?? 'recoil_2026.09.03_arm64-macos.7z';
        DB::table('archive')->insert($over + [
            'id' => $id, 'sha256' => str_repeat(substr($id, 0, 1), 64), 'filename' => $filename,
            'filename_lower' => mb_strtolower($filename), 'kind' => 'engine', 'internal_name' => 'recoil',
            'version' => '2026.09.03', 'shortname' => null, 'author' => null, 'description' => null, 'size' => 43,
            'uploader' => null, 'state' => 'listed', 'game_id' => null, 'upload_mirror_id' => null,
            'reserved_until' => null, 'listed_at' => '2026-11-18 10:00:00.000000',
            'created_at' => '2026-11-18 09:00:00.000000', 'updated_at' => '2026-11-18 10:00:00.000000',
        ]);
    }

    public function test_two_engines_with_one_version_on_different_platforms_both_insert(): void
    {
        $this->archive('aaaaaaaa-0000-4000-8000-000000000001', ['platform' => 'arm64-macos']);
        $this->archive('bbbbbbbb-0000-4000-8000-000000000002', [
            'platform' => 'amd64-linux', 'filename' => 'recoil_2026.09.03_amd64-linux.7z',
        ]);

        $this->assertSame(2, DB::table('archive')->count());
    }

    public function test_two_engines_with_one_version_and_platform_clash(): void
    {
        $this->archive('aaaaaaaa-0000-4000-8000-000000000001', ['platform' => 'arm64-macos']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->archive('bbbbbbbb-0000-4000-8000-000000000002', [
            'platform' => 'arm64-macos', 'filename' => 'recoil_2026.09.03_arm64-macos_copy.7z',
        ]);
    }

    public function test_two_maps_with_one_name_and_version_still_clash(): void
    {
        $map = ['kind' => 'map', 'internal_name' => 'Comet Catcher 1.8', 'version' => ''];
        $this->archive('aaaaaaaa-0000-4000-8000-000000000001', $map + ['filename' => 'a.sd7']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->archive('bbbbbbbb-0000-4000-8000-000000000002', $map + ['filename' => 'b.sd7']);
    }

    public function test_a_failed_archive_frees_its_name_version_and_platform(): void
    {
        $this->archive('aaaaaaaa-0000-4000-8000-000000000001', ['platform' => 'arm64-macos', 'state' => 'failed']);
        $this->archive('bbbbbbbb-0000-4000-8000-000000000002', [
            'platform' => 'arm64-macos', 'filename' => 'recoil_2026.09.03_arm64-macos_2.7z',
        ]);

        $this->assertSame(2, DB::table('archive')->count());
    }

    public function test_mirror_takes_engines_defaults_to_false(): void
    {
        DB::table('mirror')->insert([
            'id' => 'm1', 'name' => 'm1', 'base_url' => 'https://mirror.test', 'public_key' => str_repeat('k', 43),
            'created_at' => '2026-11-17 12:00:00.000000',
        ]);

        $this->assertEquals(0, DB::table('mirror')->where('id', 'm1')->value('takes_engines'));
    }
}
