<?php

namespace Tests\Feature\Maps;

use App\Supabase\Local\MapStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** `php artisan maps:copy`: the dry run, the live copy and its switch (#599). */
class MapCopyTest extends TestCase
{
    use RefreshDatabase;

    private const MAP = '00000000-0000-4000-8000-0000000000aa';

    private const PACK = '00000000-0000-4000-8000-0000000000bb';

    /** @var list<string> */
    private array $reads = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.maps_store' => null,
            'hub.supabase.url' => 'https://sb.test',
            'hub.supabase.publishable_key' => 'pk',
            'hub.supabase.service_role_key' => 'sk',
            'hub.supabase.timeout' => 0,
        ]);
    }

    /** Supabase with one map, its point and credit, a pack holding it and an alias, and `$points` as its point count. */
    private function fakeSupabase(int $points = 1): void
    {
        $rows = [
            'map' => [[
                'id' => self::MAP, 'map_name' => 'Comet 1.0', 'slug' => 'comet-1-0', 'display_name' => null, 'description' => null,
                'map_version' => null, 'archive_filename' => 'comet_1.0.sd7', 'width_elmos' => 6144, 'height_elmos' => 6144,
                'world_height_min' => -12.5, 'world_height_max' => 300, 'min_wind' => 0, 'max_wind' => 25.5, 'tidal_strength' => 20,
                'void_water' => false, 'void_ground' => null, 'water_coverage' => 0.2, 'appearance' => (object) [],
                'curated_tags' => ['classic'], 'source_hash' => 'h', 'source_archive' => 'comet_1.0.sd7', 'catalog_version' => 2,
                'facts_digest' => 'd', 'submitted_by' => null, 'seen_at' => '2026-10-01T10:00:00+00:00',
                'created_at' => '2026-10-01T10:00:00.25+00:00', 'updated_at' => '2026-10-01T10:00:00+00:00',
                'featured_at' => null, 'featured_by' => null, 'owner_user_id' => null,
            ]],
            'map_point' => [['id' => 7, 'map_id' => self::MAP, 'kind' => 'metal', 'ordinal' => 0, 'x' => 1.5, 'z' => 2, 'y' => null, 'meta' => (object) []]],
            'map_author' => [['id' => 3, 'map_id' => self::MAP, 'credit_index' => 0, 'raw' => 'Beherith', 'key' => 'beherith']],
            'author_alias' => [['from_key' => 'beh', 'to_key' => 'beherith', 'note' => null, 'set_by' => null, 'set_at' => '2026-10-02T10:00:00+00:00']],
            'map_pack' => [['id' => self::PACK, 'title' => 'Classics', 'blurb' => null, 'featured_at' => null, 'featured_by' => null, 'created_at' => '2026-10-03T10:00:00+00:00']],
            'map_pack_map' => [['pack_id' => self::PACK, 'map_name' => 'Comet 1.0', 'position' => 0]],
        ];
        $counts = ['map' => 1, 'map_point' => $points, 'map_author' => 1, 'author_alias' => 1, 'map_pack' => 1, 'map_pack_map' => 1];
        Http::fake(function (Request $request) use ($rows, $counts) {
            $url = urldecode($request->url());
            preg_match('#/rest/v1/([a-z_]+)#', $url, $m);
            $table = $m[1];
            if ($request->method() === 'HEAD') {
                return Http::response('', 200, ['Content-Range' => '*/'.($counts[$table] ?? 0)]);
            }
            $this->reads[] = $url;

            return Http::response(json_encode(str_contains($url, 'offset=0') ? ($rows[$table] ?? []) : []));
        });
    }

    public function test_the_dry_run_reports_counts_and_writes_nothing(): void
    {
        $this->fakeSupabase();

        $this->artisan('maps:copy', ['--dry-run' => true])
            ->expectsOutput('map: Supabase 1, MariaDB 0')
            ->expectsOutput('map_pack_map: Supabase 1, MariaDB 0')
            ->expectsOutput('map_source_conflict: Supabase 0, MariaDB 0')
            ->expectsOutput('Dry run: nothing was written.')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('map')->count());
        $this->assertSame(MapStore::SUPABASE, MapStore::fresh());
    }

    public function test_the_copy_keeps_ids_objects_and_times_then_switches(): void
    {
        $this->fakeSupabase();

        $this->artisan('maps:copy')
            ->expectsOutput('map_point: Supabase 1, copied 1, MariaDB 1')
            ->expectsOutput('author_alias: Supabase 1, copied 1, MariaDB 1')
            ->expectsOutput('The hub now reads and writes maps in MariaDB.')
            ->assertExitCode(0);

        $this->assertSame(MapStore::MARIADB, MapStore::fresh());
        $map = DB::table('map')->first();
        $this->assertSame(self::MAP, $map->id);
        $this->assertSame('2026-10-01 10:00:00.250000', $map->created_at);
        $this->assertSame('{}', $map->appearance);
        $this->assertSame('["classic"]', $map->curated_tags);
        $this->assertEquals(0.2, $map->water_coverage);
        $this->assertSame('{}', DB::table('map_point')->where('id', 7)->value('meta'));
        $this->assertSame('beherith', DB::table('author_alias')->where('from_key', 'beh')->value('to_key'));
        $this->assertSame(0, (int) DB::table('map_pack_map')->where('pack_id', self::PACK)->value('position'));
        // Tables without an id are read in their own key's order.
        $this->assertTrue(collect($this->reads)->contains(fn (string $url) => str_contains($url, '/map_pack_map?select=*&order=pack_id.asc,map_name.asc')));
        $this->assertTrue(collect($this->reads)->contains(fn (string $url) => str_contains($url, '/author_alias?select=*&order=from_key.asc')));

        $this->artisan('maps:copy')->assertExitCode(1);
    }

    public function test_a_short_copy_goes_back_to_supabase_and_keeps_nothing(): void
    {
        $this->fakeSupabase(points: 2);

        $this->artisan('maps:copy')
            ->expectsOutput('The hub still reads maps from Supabase.')
            ->assertExitCode(1);

        $this->assertSame(MapStore::SUPABASE, MapStore::fresh());
        $this->assertSame(0, DB::table('map')->count());
    }

    public function test_the_store_can_go_back_to_supabase_only(): void
    {
        MapStore::set(MapStore::MARIADB);

        $this->artisan('maps:store')->expectsOutput('The hub reads maps from mariadb.')->assertExitCode(0);
        $this->artisan('maps:store', ['state' => 'mariadb'])->assertExitCode(1);
        $this->artisan('maps:store', ['state' => 'supabase'])->assertExitCode(0);
        $this->assertSame(MapStore::SUPABASE, MapStore::fresh());
    }
}
