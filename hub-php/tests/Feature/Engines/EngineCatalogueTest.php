<?php

namespace Tests\Feature\Engines;

use App\Domain\Mirrors\Catalogue;
use App\Supabase\Supabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Moderation\ModerationTestCase;
use Tests\Unit\Mirrors\MirrorFixtures;

/** How engines appear in the signed catalogue. */
class EngineCatalogueTest extends ModerationTestCase
{
    use MirrorFixtures;
    use RefreshDatabase;

    private const USER = '11111111-1111-1111-1111-111111111111';

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
            'takes_engines' => true, 'created_at' => '2026-11-17 12:00:00.000000',
        ]);
    }

    /** @param array<string, mixed> $over */
    private function archive(string $n, array $over = []): void
    {
        $filename = $over['filename'] ?? "Map $n.sd7";
        DB::table('archive')->insert($over + [
            'id' => "dddddddd-0000-4000-8000-00000000000$n", 'sha256' => str_repeat($n, 64), 'filename' => $filename,
            'filename_lower' => mb_strtolower($filename), 'kind' => 'map', 'internal_name' => "Map $n", 'version' => '',
            'shortname' => null, 'author' => 'Beherith', 'description' => null, 'size' => 43, 'uploader' => self::USER,
            'state' => 'listed', 'game_id' => null, 'upload_mirror_id' => 'jj-darkstars', 'reserved_until' => null,
            'listed_at' => '2026-11-18 10:00:00.000000', 'created_at' => '2026-11-18 09:00:00.000000',
            'updated_at' => '2026-11-18 10:00:00.000000',
        ]);
    }

    /** @param array<string, mixed> $over */
    private function engine(string $n, string $version, string $platform, array $over = []): void
    {
        $this->archive($n, $over + [
            'kind' => 'engine', 'internal_name' => 'recoil', 'version' => $version, 'platform' => $platform,
            'filename' => "recoil_{$version}_{$platform}.7z", 'description' => "Note $n",
        ]);
    }

    /** @return array<string, mixed> */
    private function source(): array
    {
        $this->supabase();

        return Supabase::admin()->rpc('catalogue_source');
    }

    public function test_a_listed_engine_is_in_engines_and_not_archives(): void
    {
        $this->archive('1');
        $this->engine('2', '2026.09.03', 'arm64-macos');

        $source = $this->source();

        $this->assertSame(['Map 1.sd7'], array_column($source['archives'], 'filename'));
        $this->assertSame([[
            'filename' => 'recoil_2026.09.03_arm64-macos.7z', 'sha256' => str_repeat('2', 64), 'size' => 43,
            'version' => '2026.09.03', 'platform' => 'arm64-macos', 'note' => 'Note 2',
        ]], $source['engines']);
    }

    public function test_engines_are_ordered_by_listing_time_then_id(): void
    {
        $this->engine('3', '2', 'amd64-linux', ['listed_at' => '2026-11-18 10:00:00.000000']);
        $this->engine('2', '2', 'amd64-windows', ['listed_at' => '2026-11-18 10:00:00.000000']);
        $this->engine('1', '2', 'arm64-macos', ['listed_at' => '2026-11-18 11:00:00.000000']);
        $this->engine('4', '1', 'arm64-macos', ['listed_at' => '2026-11-18 09:00:00.000000', 'state' => 'uploading']);

        $this->assertSame(
            ['recoil_2_amd64-windows.7z', 'recoil_2_amd64-linux.7z', 'recoil_2_arm64-macos.7z'],
            array_column($this->source()['engines'], 'filename'),
        );
    }

    public function test_a_removed_engine_is_in_removed(): void
    {
        $this->engine('2', '2026.09.03', 'arm64-macos', ['state' => 'removed', 'removal_reason' => 'broken', 'removed_at' => '2026-11-18 11:00:00.000000']);

        $source = $this->source();

        $this->assertSame([], $source['engines']);
        $this->assertSame([], $source['archives']);
        $this->assertSame(['recoil_2026.09.03_arm64-macos.7z'], array_column($source['removed'], 'filename'));
        $this->assertSame(['broken'], array_column($source['removed'], 'reason'));
    }

    public function test_editing_nothing_else_keeps_map_entries_unchanged(): void
    {
        $this->archive('1');
        $before = json_decode(Catalogue::encode($this->source(), 1794787200, null), true);

        $this->engine('2', '2026.09.03', 'arm64-macos');
        $after = json_decode(Catalogue::encode($this->source(), 1794787200, null), true);

        $this->assertSame($before['archives'], $after['archives']);
        $this->assertSame($before['removed'], $after['removed']);
        $this->assertArrayNotHasKey('engines', $before);
        $this->assertCount(1, $after['engines']);
    }
}
