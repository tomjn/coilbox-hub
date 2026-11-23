<?php

namespace Tests\Feature\Points;

use App\Domain\Points\CommunityPoints;
use App\Supabase\Supabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Moderation\ModerationTestCase;

/** New game facts and new maps earn 5 points, and clearing a map takes its points back. */
class FactsPointsTest extends ModerationTestCase
{
    use RefreshDatabase;

    private const FIRST = '11111111-1111-1111-1111-111111111111';

    private const SECOND = '22222222-2222-2222-2222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.games_store' => 'mariadb', 'hub.maps_store' => 'mariadb']);
        $this->supabase(['POST /rest/v1/' => self::reply([], 201)]);
    }

    private function facts(string $by, string $release, string $digest = 'd1'): void
    {
        Supabase::admin()->rpc('submit_game_facts', ['p_submitted_by' => $by, 'p_submission' => [
            'shortname' => 'BA', 'release' => $release,
            'units' => [['unit' => ['name' => 'armcom', 'full_name' => 'Commander', 'faction_key' => null, 'build_options' => [], 'stats' => (object) [], 'morph_targets' => []], 'facts_digest' => $digest]],
        ]]);
    }

    /** @return array<string, mixed> */
    private function map(int $version = 1, string $digest = 'd1'): array
    {
        return ['slug' => 'comet', 'slug_alternative' => 'comet-2', 'facts_digest' => $digest, 'entry' => [
            'map_name' => 'Comet 1.0', 'source_archive' => 'Comet 1.0', 'source_hash' => 'src', 'catalog_version' => $version,
            'width_elmos' => 6144, 'height_elmos' => 6144, 'world_height_min' => 0, 'world_height_max' => 100,
        ]];
    }

    private function submitMap(string $by, array $map): void
    {
        Supabase::admin()->rpc('submit_map_facts', ['p_submitted_by' => $by, 'p_maps' => [$map]]);
    }

    public function test_the_first_facts_for_a_game_give_5(): void
    {
        $this->facts(self::FIRST, '1.0');

        $this->assertSame(5, CommunityPoints::total(self::FIRST));
    }

    public function test_the_same_facts_again_give_no_new_row(): void
    {
        $this->facts(self::FIRST, '1.0');
        $this->facts(self::SECOND, '1.0');

        $this->assertSame(1, DB::table('community_point')->count());
        $this->assertSame(0, CommunityPoints::total(self::SECOND));
    }

    public function test_facts_for_a_new_release_give_5_more(): void
    {
        $this->facts(self::FIRST, '1.0');
        $this->facts(self::FIRST, '2.0');

        $this->assertSame(10, CommunityPoints::total(self::FIRST));
    }

    public function test_changed_unit_facts_for_a_held_release_give_the_second_account_5(): void
    {
        $this->facts(self::FIRST, '1.0');
        $this->facts(self::SECOND, '1.0', 'd2');

        $this->assertSame(5, CommunityPoints::total(self::SECOND));
        $this->assertSame(5, CommunityPoints::total(self::FIRST));
    }

    public function test_a_new_map_gives_5_and_sending_it_again_gives_no_new_row(): void
    {
        $this->submitMap(self::FIRST, $this->map());
        $this->submitMap(self::SECOND, $this->map());

        $this->assertSame(5, CommunityPoints::total(self::FIRST));
        $this->assertSame(0, CommunityPoints::total(self::SECOND));
        $this->assertSame(1, DB::table('community_point')->count());
    }

    public function test_a_higher_catalog_version_from_a_second_account_gives_it_5(): void
    {
        $this->submitMap(self::FIRST, $this->map());
        $this->submitMap(self::SECOND, $this->map(2, 'd2'));

        $this->assertSame(5, CommunityPoints::total(self::SECOND));
    }

    public function test_clearing_a_map_revokes_its_rows(): void
    {
        $this->submitMap(self::FIRST, $this->map());
        $this->assertSame(5, CommunityPoints::total(self::FIRST));
        $id = (string) DB::table('map')->value('id');
        DB::table('map_source_conflict')->insert(['map_id' => $id, 'source_archive' => 'a', 'held_source_hash' => 'h', 'reported_source_hash' => 'x', 'at' => '2026-10-01 10:00:00.000000']);

        $this->assertTrue(Supabase::admin()->rpc('clear_map_facts', ['p_map_id' => $id]));

        $this->assertSame(0, CommunityPoints::total(self::FIRST));
        $this->assertSame(0, DB::table('community_point')->where('kind', CommunityPoints::MAP)->count());
    }
}
