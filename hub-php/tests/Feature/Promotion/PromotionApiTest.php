<?php

namespace Tests\Feature\Promotion;

use App\Domain\Promotion\JobToken;
use App\Supabase\Local\StagedObjects;
use App\Supabase\Local\Values;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsGameCatalogue;
use Tests\Feature\Moderation\ModerationTestCase;

/**
 * The promotion job's API (#616) with pictures and games in MariaDB and
 * staged pictures on the disk: the token, the moderation hold, what the
 * token may read and delete, a whole promotion, and the reservations that
 * keep an upload's object from being deleted under it.
 */
class PromotionApiTest extends ModerationTestCase
{
    use RefreshDatabase;
    use SeedsGameCatalogue;

    private const TOKEN = 'cbpj_test-token';

    private const ASSET = 'aaaaaaaa-0000-4000-8000-000000000001';

    private const OTHER = 'aaaaaaaa-0000-4000-8000-000000000002';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.pictures_store' => 'mariadb',
            'hub.games_store' => 'mariadb',
            'hub.promotion.token_hash' => hash('sha256', self::TOKEN),
            'hub.promotion.next_token_hash' => '',
        ]);
        Storage::fake('staged');
        CarbonImmutable::setTestNow('2026-11-19T12:00:00Z');
        $this->supabase([
            'POST /rest/v1/game' => self::reply([], 201),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** An approved unit picture staged two days ago, with its object on the disk. @param array<string, mixed> $over */
    private function asset(array $over = [], bool $stage = true): array
    {
        $hash = $over['hash'] ?? str_repeat('a', 64);
        $row = [
            'id' => self::ASSET, 'game' => 'BYAR', 'unit_name' => 'armsolar', 'map_name' => null, 'variant' => 'buildpic',
            'source_hash' => 'raw', 'hash' => $hash, 'encode_profile' => 'webp-lossless-256',
            'path' => 'units/BYAR/buildpic/'.$hash.'.webp', 'origin' => 'extracted', 'tier' => 'bucket',
            'mime' => 'image/webp', 'bytes' => 5, 'width' => 128, 'height' => 128, 'map_width' => null, 'map_height' => null,
            'source_archive' => 'byar.sdz', 'seen_at' => '2026-11-01 10:00:00.000000', 'promoted_at' => null,
            'uploaded_by' => null, 'moderation' => 'approved', 'approval_source' => 'moderator',
            'created_at' => '2026-11-01 10:00:00.000000', 'updated_at' => '2026-11-17 10:00:00.000000', 'world_height_min' => null,
            'world_height_max' => null, 'rejection_kind' => null, 'blob_path' => null, 'blob_path_tier' => null,
            'bytes_missing_at' => null, 'faction_key' => null, ...$over,
        ];
        DB::table('asset')->insert($row);
        if ($stage && ! DB::table('staged_object')->where('path', $row['path'])->exists()) {
            StagedObjects::upload($row['path'], 'bytes', 'image/webp', false);
        }

        return $row;
    }

    /** @param array<string, mixed> $over */
    private function game(array $over = []): void
    {
        DB::table('game')->insert($this->gameRow(self::GAME, 'BYAR', $over));
    }

    private function job(): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.self::TOKEN]);
    }

    public function test_every_route_needs_the_job_token(): void
    {
        $this->getJson('/api/v1/promotion/due')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer someone-elses'])->getJson('/api/v1/promotion/due')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer someone-elses'])->postJson('/api/v1/promotion/staged/delete', ['paths' => ['x']])->assertStatus(401);
        $this->assertStringContainsString('no-store', $this->job()->getJson('/api/v1/promotion/due')->assertOk()->headers->get('Cache-Control'));
    }

    public function test_an_unset_hash_lets_nothing_in(): void
    {
        config(['hub.promotion.token_hash' => '']);

        $this->job()->getJson('/api/v1/promotion/due')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer '])->getJson('/api/v1/promotion/due')->assertStatus(401);
        $this->assertFalse(JobToken::accepts(''));
    }

    public function test_the_next_hash_also_works_while_rotating(): void
    {
        config(['hub.promotion.token_hash' => hash('sha256', 'old'), 'hub.promotion.next_token_hash' => hash('sha256', self::TOKEN)]);

        $this->job()->getJson('/api/v1/promotion/due')->assertOk();
        $this->withHeaders(['Authorization' => 'Bearer old'])->getJson('/api/v1/promotion/due')->assertOk();
    }

    public function test_a_made_token_matches_its_hash(): void
    {
        $made = JobToken::make();
        config(['hub.promotion.token_hash' => $made['hash']]);

        $this->assertTrue(JobToken::accepts($made['token']));
        $this->assertFalse(JobToken::accepts($made['token'].'x'));
        $this->assertStringStartsWith('cbpj_', $made['token']);
    }

    public function test_due_is_approved_staged_pictures_past_the_hold_oldest_first(): void
    {
        $this->asset(['updated_at' => '2026-11-17 11:00:00.000000']);
        $this->asset(['id' => self::OTHER, 'unit_name' => 'armmex', 'hash' => str_repeat('b', 64), 'updated_at' => '2026-11-16 10:00:00.000000']);
        $this->asset(['id' => 'aaaaaaaa-0000-4000-8000-000000000003', 'unit_name' => 'a3', 'hash' => str_repeat('c', 64), 'updated_at' => '2026-11-19 11:00:00.000000']);
        $this->asset(['id' => 'aaaaaaaa-0000-4000-8000-000000000004', 'unit_name' => 'a4', 'hash' => str_repeat('d', 64), 'moderation' => 'pending', 'approval_source' => null]);
        $this->asset(['id' => 'aaaaaaaa-0000-4000-8000-000000000005', 'unit_name' => 'a5', 'hash' => str_repeat('e', 64), 'bytes_missing_at' => '2026-11-18 10:00:00.000000']);

        $due = $this->job()->getJson('/api/v1/promotion/due?limit=10')->assertOk()->json();

        $this->assertSame([self::OTHER, self::ASSET], array_column($due, 'id'));
        $this->assertSame(['id', 'game', 'unit_name', 'map_name', 'faction_key', 'variant', 'hash', 'mime', 'tier', 'path', 'bytes', 'updated_at'], array_keys($due[0]));
        $this->assertSame('2026-11-16T10:00:00+00:00', $due[0]['updated_at']);
        $this->assertSame([self::OTHER], array_column($this->job()->getJson('/api/v1/promotion/due?limit=1')->json(), 'id'));
        $this->job()->postJson('/api/v1/promotion/due/check', ['ids' => [self::ASSET, 'aaaaaaaa-0000-4000-8000-000000000004']])
            ->assertExactJson(['ids' => [self::ASSET]]);
    }

    public function test_the_job_reads_approved_pictures_and_staged_game_art_only(): void
    {
        $approved = $this->asset();
        $pending = $this->asset(['id' => self::OTHER, 'unit_name' => 'armmex', 'hash' => str_repeat('b', 64), 'moderation' => 'pending', 'approval_source' => null]);
        $this->game(['card_path' => 'games/BYAR/card.webp', 'card_hash' => 'h', 'card_staged_tier' => 'bucket']);
        StagedObjects::upload('games/BYAR/card.webp', 'card!', 'image/webp', false);
        StagedObjects::upload('games/BYAR/logo.webp', 'old logo', 'image/webp', false);

        $read = fn (string $path) => $this->job()->get('/api/v1/promotion/staged?path='.urlencode($path));

        $this->assertSame('bytes', $read($approved['path'])->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->getContent());
        $this->assertSame('card!', $read('games/BYAR/card.webp')->assertOk()->getContent());
        $read($pending['path'])->assertNotFound();
        $read('games/BYAR/logo.webp')->assertNotFound();
        $read('units/nothing.webp')->assertNotFound();
    }

    public function test_a_whole_promotion_moves_the_row_and_deletes_the_staged_file(): void
    {
        $row = $this->asset();
        $durable = 'units/BYAR/armsolar/buildpic/'.$row['hash'].'.webp';

        $moved = $this->job()->postJson('/api/v1/promotion/promote', ['ids' => [self::ASSET], 'paths' => [$durable]])->assertOk()->json();
        $this->assertSame([['id' => self::ASSET, 'blob_path' => $row['path'], 'blob_path_tier' => 'bucket']], $moved);
        $after = DB::table('asset')->find(self::ASSET);
        $this->assertSame(['static', $durable, $row['path']], [$after->tier, $after->path, $after->blob_path]);
        $this->assertNotNull($after->promoted_at);

        $pending = $this->job()->getJson('/api/v1/promotion/pending-deletions')->json();
        $this->assertSame([['id' => self::ASSET, 'path' => $durable, 'tier' => 'static', 'blob_path' => $row['path']]], $pending);

        // A second promote of the same row moves nothing.
        $this->job()->postJson('/api/v1/promotion/promote', ['ids' => [self::ASSET], 'paths' => [$durable]])->assertExactJson([]);

        // The sweep must leave a queued object to promotion. The drain may take it.
        $this->job()->postJson('/api/v1/promotion/staged/reserve', ['paths' => [$row['path']], 'queued_is_claimed' => true])->assertExactJson(['paths' => []]);
        $this->assertSame(0, DB::table('staged_object_deletion')->count());
        $this->job()->postJson('/api/v1/promotion/staged/reserve', ['paths' => [$row['path']], 'queued_is_claimed' => false])->assertExactJson(['paths' => [$row['path']]]);
        $this->job()->postJson('/api/v1/promotion/staged/delete', ['paths' => [$row['path']]])->assertExactJson(['deleted' => [$row['path']]]);
        $this->job()->postJson('/api/v1/promotion/staged/release', ['paths' => [$row['path']]])->assertExactJson(['released' => 1]);
        $this->job()->postJson('/api/v1/promotion/pending-deletions/clear', ['ids' => [self::ASSET]])->assertExactJson(['cleared' => 1]);

        Storage::disk('staged')->assertMissing($row['path']);
        $this->assertSame(0, DB::table('staged_object')->count());
        $this->assertNull(DB::table('asset')->value('blob_path'));
        $this->assertSame([], $this->job()->getJson('/api/v1/promotion/pending-deletions')->json());
    }

    public function test_promote_refuses_rows_that_are_not_approved_and_mismatched_lists(): void
    {
        $this->asset(['moderation' => 'rejected', 'rejection_kind' => 'editorial']);

        $this->job()->postJson('/api/v1/promotion/promote', ['ids' => [self::ASSET], 'paths' => ['units/x.webp']])->assertExactJson([]);
        $this->job()->postJson('/api/v1/promotion/promote', ['ids' => [self::ASSET], 'paths' => []])->assertStatus(400);
        $this->assertSame('bucket', DB::table('asset')->value('tier'));
        $this->expectException(SupabaseException::class);
        Supabase::admin()->rpc('promote_assets', ['ids' => [self::ASSET], 'paths' => []]);
    }

    public function test_only_reserved_paths_can_be_deleted(): void
    {
        $row = $this->asset();
        StagedObjects::upload('units/orphan.webp', 'orphan', 'image/webp', false);

        $this->job()->postJson('/api/v1/promotion/staged/delete', ['paths' => [$row['path']]])->assertStatus(409);
        $this->job()->postJson('/api/v1/promotion/staged/delete', ['paths' => ['units/orphan.webp', $row['path']]])->assertStatus(409);
        Storage::disk('staged')->assertExists($row['path']);
        Storage::disk('staged')->assertExists('units/orphan.webp');

        // A path a staged picture claims is never reserved.
        $this->job()->postJson('/api/v1/promotion/staged/reserve', ['paths' => [$row['path'], 'units/orphan.webp'], 'queued_is_claimed' => true])
            ->assertExactJson(['paths' => ['units/orphan.webp']]);
        $this->job()->postJson('/api/v1/promotion/staged/delete', ['paths' => ['units/orphan.webp']])->assertOk();
        Storage::disk('staged')->assertMissing('units/orphan.webp');
    }

    public function test_staged_game_art_claims_its_object_card_included(): void
    {
        $this->game([
            'logo_path' => 'games/BYAR/logo.webp', 'logo_hash' => 'l', 'logo_staged_tier' => 'bucket',
            'card_path' => 'games/BYAR/card.webp', 'card_hash' => 'c', 'card_staged_tier' => 'bucket',
        ]);
        foreach (['logo', 'card', 'banner'] as $kind) {
            StagedObjects::upload('games/BYAR/'.$kind.'.webp', $kind, 'image/webp', false);
        }

        $this->assertSame(['games/BYAR/banner.webp'], array_column($this->job()->getJson('/api/v1/promotion/staged/unclaimed')->json(), 'path'));
        $this->job()->postJson('/api/v1/promotion/staged/reserve', ['paths' => ['games/BYAR/logo.webp', 'games/BYAR/card.webp'], 'queued_is_claimed' => true])
            ->assertExactJson(['paths' => []]);
    }

    public function test_a_reserved_path_cannot_be_claimed_by_a_picture_or_a_game(): void
    {
        StagedObjects::upload('games/BYAR/card.webp', 'card', 'image/webp', false);
        $this->game();
        $this->job()->postJson('/api/v1/promotion/staged/reserve', ['paths' => ['games/BYAR/card.webp', 'units/x.webp'], 'queued_is_claimed' => true])->assertOk();

        try {
            Supabase::admin()->from('game')->update(['card_path' => 'games/BYAR/card.webp', 'card_hash' => 'c', 'card_staged_tier' => 'bucket'])
                ->eq('shortname', 'BYAR')->execute();
            $this->fail('the game claimed a reserved path');
        } catch (SupabaseException $e) {
            $this->assertSame('55006', $e->errorCode);
        }
        try {
            DB::transaction(fn () => Supabase::admin()->from('asset')->insert([$this->assetRequestRow('units/x.webp')])->execute());
            $this->fail('a picture claimed a reserved path');
        } catch (SupabaseException $e) {
            $this->assertSame('55006', $e->errorCode);
        }
        $this->assertNull(DB::table('game')->value('card_path'));
    }

    /** @return array<string, mixed> */
    private function assetRequestRow(string $path): array
    {
        return [
            'game' => 'BYAR', 'unit_name' => 'armsolar', 'variant' => 'buildpic', 'source_hash' => 'raw', 'hash' => str_repeat('f', 64),
            'encode_profile' => 'webp-lossless-256', 'path' => $path, 'origin' => 'extracted', 'tier' => 'bucket',
            'mime' => 'image/webp', 'bytes' => 4, 'width' => 128, 'height' => 128, 'moderation' => 'pending',
        ];
    }

    public function test_a_game_picture_is_listed_and_its_staged_tier_cleared_only_while_the_row_names_it(): void
    {
        $this->game(['banner_path' => 'games/BYAR/banner.webp', 'banner_hash' => 'h1', 'banner_staged_tier' => 'bucket']);

        $games = $this->job()->getJson('/api/v1/promotion/games')->assertOk()->json();
        $this->assertSame('games/BYAR/banner.webp', $games[0]['banner_path']);
        $this->assertSame('bucket', $games[0]['banner_staged_tier']);
        $this->assertArrayHasKey('card_hash', $games[0]);

        $clear = fn (string $hash) => $this->job()->postJson('/api/v1/promotion/games/clear-staged', [
            'shortname' => 'BYAR', 'kind' => 'banner', 'path' => 'games/BYAR/banner.webp', 'hash' => $hash,
        ]);
        $clear('h0')->assertExactJson(['cleared' => false]);
        $this->assertSame('bucket', DB::table('game')->value('banner_staged_tier'));
        $clear('h1')->assertExactJson(['cleared' => true]);
        $this->assertNull(DB::table('game')->value('banner_staged_tier'));
        $this->assertSame('games/BYAR/banner.webp', DB::table('game')->value('banner_path'));
        $this->job()->postJson('/api/v1/promotion/games/clear-staged', ['shortname' => 'BYAR', 'kind' => 'hash', 'path' => 'p', 'hash' => 'h'])->assertStatus(400);
    }

    public function test_the_takedown_list_and_settling_it(): void
    {
        $this->asset(['tier' => 'static'], false);
        $this->asset(['id' => self::OTHER, 'unit_name' => 'armmex', 'hash' => str_repeat('b', 64), 'tier' => 'static'], false);
        DB::table('asset_withdrawal')->insert([
            ['asset_id' => self::ASSET, 'path' => 'units/a.webp', 'at' => '2026-11-18 10:00:00.000000', 'withdrawn_at' => null],
            ['asset_id' => self::OTHER, 'path' => 'units/b.webp', 'at' => '2026-11-17 10:00:00.000000', 'withdrawn_at' => '2026-11-18 10:00:00.000000'],
        ]);

        $this->job()->getJson('/api/v1/promotion/withdrawals')->assertExactJson([
            ['asset_id' => self::ASSET, 'path' => 'units/a.webp', 'at' => '2026-11-18T10:00:00+00:00'],
        ]);
        $this->job()->postJson('/api/v1/promotion/withdrawals/settle', ['ids' => [self::ASSET, self::OTHER]])->assertExactJson(['settled' => 1]);
        $this->job()->getJson('/api/v1/promotion/withdrawals')->assertExactJson([]);
    }

    public function test_a_reservation_older_than_an_hour_is_abandoned(): void
    {
        DB::table('staged_object_deletion')->insert([
            ['path' => 'units/old.webp', 'reserved_at' => Values::stored('2026-11-19T10:00:00Z')],
            ['path' => 'units/new.webp', 'reserved_at' => Values::stored('2026-11-19T11:30:00Z')],
        ]);

        $this->job()->getJson('/api/v1/promotion/staged/abandoned')->assertExactJson(['units/old.webp']);
    }

    public function test_the_meters_measure_the_droplet_disk_once_pictures_have_moved(): void
    {
        StagedObjects::upload('units/x.webp', str_repeat('x', 2048), 'image/webp', false);

        $report = $this->job()->getJson('/api/v1/promotion/meters')->assertOk()->json();

        $this->assertSame('Droplet disk', $report['meters'][0]['name']);
        $this->assertSame('counted', $report['meters'][0]['basis']);
        $this->assertGreaterThan(0, $report['meters'][0]['used']);
        $this->assertGreaterThanOrEqual($report['meters'][0]['used'], $report['meters'][0]['allowance']);
        $this->assertStringContainsString('Staged pictures are 2.0 KiB', $report['meters'][0]['note']);
        $this->assertNotContains('Supabase Storage', array_column($report['meters'], 'name'));
    }
}
