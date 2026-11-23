<?php

namespace Tests\Feature\Points;

use App\Domain\Container\Container;
use App\Domain\Points\CommunityPoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\StartsWithNoPoints;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** `php artisan points:backfill` gives past work the points it would have earned. */
class BackfillTest extends TestCase
{
    use RefreshDatabase;
    use StartsWithNoPoints;

    private const OWNER = '11111111-1111-1111-1111-111111111111';

    private const SUBMITTER = '22222222-2222-2222-2222-222222222222';

    private const UPLOADER = '33333333-3333-3333-3333-333333333333';

    private const GAME = 'bbbbbbbb-0000-4000-8000-000000000001';

    private const NOW = '2026-11-01 10:00:00.000000';

    /** @param array<string, mixed> $over */
    private function game(array $over = []): void
    {
        DB::table('game')->insert($over + [
            'id' => self::GAME, 'shortname' => 'bar', 'display_name' => 'Beyond All Reason', 'links' => '[]', 'conquest_factions' => '[]',
            'owner_user_id' => self::OWNER, 'submitted_by' => self::SUBMITTER, 'description' => 'About the game',
            'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
    }

    /** @param array<string, mixed> $over */
    private function asset(string $id, array $over = []): void
    {
        DB::table('asset')->insert($over + [
            'id' => $id, 'game' => 'BYAR', 'unit_name' => null, 'map_name' => null, 'faction_key' => 'armada', 'variant' => 'logo',
            'source_hash' => 'raw-'.$id, 'hash' => str_repeat('a', 64), 'encode_profile' => 'webp-lossless-256',
            'path' => 'units/BYAR/logo/'.$id.'.webp', 'origin' => 'extracted', 'tier' => 'bucket',
            'mime' => 'image/webp', 'bytes' => 40, 'width' => 128, 'height' => 128, 'map_width' => null, 'map_height' => null,
            'source_archive' => 'byar_1.2.sdz', 'seen_at' => self::NOW, 'promoted_at' => null,
            'uploaded_by' => self::UPLOADER, 'moderation' => 'approved', 'approval_source' => null,
            'created_at' => self::NOW, 'updated_at' => self::NOW, 'world_height_min' => null,
            'world_height_max' => null, 'rejection_kind' => null, 'blob_path' => null, 'blob_path_tier' => null,
            'bytes_missing_at' => null,
        ]);
    }

    /** @param array<string, mixed> $over */
    private function archive(string $id, array $over = []): void
    {
        DB::table('archive')->insert($over + [
            'id' => $id, 'sha256' => str_repeat(substr($id, -1), 64), 'filename' => $id.'.sd7', 'filename_lower' => $id.'.sd7',
            'kind' => 'map', 'internal_name' => $id, 'version' => '', 'shortname' => null, 'author' => 'Beherith',
            'description' => null, 'size' => 43, 'uploader' => self::UPLOADER, 'state' => 'listed', 'game_id' => null,
            'upload_mirror_id' => null, 'reserved_until' => null, 'listed_at' => self::NOW,
            'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
    }

    /** @param array<string, mixed> $over */
    private function item(string $id, array $over = []): void
    {
        DB::table('item')->insert($over + [
            'id' => $id, 'kind' => 'preset', 'kind_version' => Container::SUPPORTED_KIND_VERSIONS['preset'], 'title' => 'An item',
            'description' => '', 'tags' => '[]', 'container' => json_encode((object) ['format' => 'coilbox']), 'author_id' => self::UPLOADER,
            'author_name' => 'Author', 'created_at' => self::NOW, 'updated_at' => self::NOW, 'import_count' => 0,
        ]);
    }

    /** @param array<string, mixed> $over */
    private function map(string $id, array $over = []): void
    {
        DB::table('map')->insert($over + [
            'id' => $id, 'map_name' => 'Map '.$id, 'slug' => 'map-'.$id, 'width_elmos' => 6144, 'height_elmos' => 6144,
            'world_height_min' => -10.0, 'world_height_max' => 300.0, 'appearance' => '{}', 'curated_tags' => '[]', 'source_hash' => 'h',
            'source_archive' => 'Map', 'catalog_version' => 1, 'facts_digest' => 'd', 'submitted_by' => self::SUBMITTER,
            'seen_at' => self::NOW, 'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
    }

    /** One of every source, with the owner holding the first text of a game description and a faction tagline. */
    private function seedEverything(): void
    {
        $this->game();
        DB::table('game_faction')->insert([
            'game_id' => self::GAME, 'key' => 'arm', 'name' => 'Arm', 'logo_path' => 'factions/arm.webp', 'logo_hash' => 'h',
            'tagline' => 'Fast', 'description' => '   ',
        ]);
        $this->asset('aaaaaaaa-0000-4000-8000-000000000001');
        $this->asset('aaaaaaaa-0000-4000-8000-000000000002', ['unit_name' => 'armcom', 'variant' => 'buildpic']);
        $this->archive('dddddddd-0000-4000-8000-000000000001');
        $this->item('11111111-1111-4111-8111-111111111111');
        $this->map('eeeeeeee-0000-4000-8000-000000000001');
    }

    public function test_one_row_of_each_source_gives_the_expected_totals(): void
    {
        $this->seedEverything();

        $this->artisan('points:backfill')
            ->expectsOutputToContain('game_owner: 1 awards, 100 points')
            ->expectsOutputToContain('archive: 1 awards, 20 points')
            ->expectsOutputToContain('item: 1 awards, 20 points')
            ->expectsOutputToContain('picture: 1 awards, 20 points')
            ->expectsOutputToContain('unit_picture: 1 awards, 1 points')
            ->expectsOutputToContain('game_facts: 1 awards, 5 points')
            ->expectsOutputToContain('map: 1 awards, 5 points')
            ->expectsOutputToContain('report: 0 awards, 0 points')
            ->expectsOutputToContain('first_text: 2 awards, 10 points')
            ->expectsOutputToContain('Accounts: 3')
            ->assertSuccessful();

        $this->assertSame(110, CommunityPoints::total(self::OWNER));
        $this->assertSame(10, CommunityPoints::total(self::SUBMITTER));
        $this->assertSame(61, CommunityPoints::total(self::UPLOADER));
        $this->assertSame(1, DB::table('community_point')->where('subject', 'game:'.self::GAME.':description')->count());
        $this->assertSame(1, DB::table('community_point')->where('subject', 'faction:'.self::GAME.':arm:tagline')->count());
        $this->assertSame(0, DB::table('community_point')->where('subject', 'faction:'.self::GAME.':arm:description')->count());
    }

    public function test_a_second_run_adds_no_rows(): void
    {
        $this->seedEverything();
        $this->artisan('points:backfill')->assertSuccessful();
        $rows = DB::table('community_point')->count();

        $this->artisan('points:backfill')->assertSuccessful();

        $this->assertSame($rows, DB::table('community_point')->count());
    }

    public function test_a_dry_run_prints_the_totals_and_writes_nothing(): void
    {
        $this->seedEverything();

        $this->artisan('points:backfill --dry-run')
            ->expectsOutputToContain('game_owner: 1 awards, 100 points')
            ->expectsOutputToContain('Accounts: 3')
            ->expectsOutputToContain('Dry run: nothing was written.')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('community_point')->count());
    }

    public function test_rejected_pictures_removed_archives_and_deleted_items_give_nothing(): void
    {
        $this->asset('aaaaaaaa-0000-4000-8000-000000000001', ['moderation' => 'rejected']);
        $this->asset('aaaaaaaa-0000-4000-8000-000000000002', ['uploaded_by' => null, 'faction_key' => 'cortex']);
        $this->archive('dddddddd-0000-4000-8000-000000000001', ['state' => 'removed']);
        $this->archive('dddddddd-0000-4000-8000-000000000002', ['uploader' => null]);
        $this->item('11111111-1111-4111-8111-111111111111', ['deleted_at' => self::NOW]);

        $this->artisan('points:backfill')->expectsOutputToContain('Accounts: 0')->assertSuccessful();

        $this->assertSame(0, DB::table('community_point')->count());
    }

    public function test_a_game_with_no_owner_gives_no_first_text_and_blank_text_gives_none(): void
    {
        $this->game(['owner_user_id' => null, 'submitted_by' => null]);
        DB::table('game_faction')->insert(['game_id' => self::GAME, 'key' => 'arm', 'name' => 'Arm', 'logo_path' => 'l', 'logo_hash' => 'h', 'tagline' => 'Fast', 'description' => null]);

        $this->artisan('points:backfill')->assertSuccessful();

        $this->assertSame(0, DB::table('community_point')->count());

        DB::table('game')->where('id', self::GAME)->update(['owner_user_id' => self::OWNER, 'description' => " \n"]);
        DB::table('game_faction')->where('game_id', self::GAME)->update(['tagline' => null]);

        $this->artisan('points:backfill')->assertSuccessful();

        $this->assertSame(['game_owner'], DB::table('community_point')->pluck('kind')->all());
    }
}
