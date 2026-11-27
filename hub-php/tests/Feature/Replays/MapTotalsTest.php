<?php

namespace Tests\Feature\Replays;

use App\Domain\Replays\MapTotals;
use App\Domain\Replays\ReplayAnalyses;
use App\Domain\Replays\ReplayListing;
use App\Domain\Replays\StartAreas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ChecksReplays;
use Tests\Feature\Moderation\ModerationTestCase;

/**
 * The totals on each map's page (#669), from the real SplinterFaction replay on
 * Tumult and its analysis, both put through the real checks. A second match on
 * the map is the first's rows under another game id, listed the real way.
 */
class MapTotalsTest extends ModerationTestCase
{
    use ChecksReplays;
    use RefreshDatabase;

    private const SECOND = 'ffffffffffffffffffffffffffffffff';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.games_store' => 'mariadb', 'hub.maps_store' => 'mariadb', 'hub.pictures_store' => 'mariadb',
            'hub.mirrors_store' => 'mariadb', 'hub.items_store' => 'mariadb', 'app.url' => 'https://hub.test',
            'hub.mirrors.secret_key' => self::fixtureKeys()['hub_secret_key'], 'hub.mirrors.next_secret_key' => '',
        ]);
        $this->replayMirror();
        Queue::fake();
        $this->catalogue();
    }

    /** The catalogue's Tumult, 7168 elmos square, with a minimap and a licence to show it. */
    private function catalogue(): void
    {
        $now = '2026-10-01 10:00:00.000000';
        DB::table('map')->insert([
            'id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'map_name' => 'Tumult', 'slug' => 'tumult', 'display_name' => 'Tumult',
            'width_elmos' => 7168, 'height_elmos' => 7168, 'world_height_min' => 0.0, 'world_height_max' => 100.0,
            'appearance' => '{}', 'curated_tags' => '[]', 'source_hash' => 'h', 'source_archive' => 'tumult',
            'catalog_version' => 1, 'facts_digest' => 'd', 'seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('asset_licence')->insert([
            'id' => 'bbbbbbbb-0000-4000-8000-000000000001', 'all_maps' => true, 'checked_at' => $now, 'checked_by' => 'test',
            'redistribute_extracted' => 'allowed', 'redistribute_rendered' => 'allowed', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function listed(): void
    {
        $this->uploaded(self::SF, 'sf-tumult', self::summary('sf-tumult'), 'Player');
        $this->check(self::SF);
        $this->assertSame('listed', DB::table('replay')->where('game_id', self::SF)->value('state'));
    }

    /** Another match on the map: the same starts, nobody won, 100 seconds long. */
    private function second(): void
    {
        $replay = (array) DB::table('replay')->where('game_id', self::SF)->first();
        DB::table('replay')->insert(['game_id' => self::SECOND, 'state' => 'checking', 'sha256' => str_repeat('e', 64), 'duration' => 100, 'winners' => '[]'] + $replay);
        foreach (DB::table('replay_team')->where('game_id', self::SF)->get() as $team) {
            DB::table('replay_team')->insert(['game_id' => self::SECOND, 'won' => false] + (array) $team);
        }
        $this->assertTrue(ReplayListing::publish(self::SECOND));
    }

    private function analyse(): string
    {
        $events = file_get_contents(base_path('tests/Fixtures/replays/sf-tumult.events.jsonl'));
        $id = DB::table('replay_analysis_submission')->insertGetId([
            'game_id' => self::SF, 'user_id' => self::USER, 'logger_version' => 6, 'sha256' => hash('sha256', $events),
            'size' => strlen($events), 'events' => $events, 'state' => 'checking', 'sent_at' => '2026-11-26 10:00:00.000000',
        ]);

        return ReplayAnalyses::check($id);
    }

    /** @return array<string, array{replays: int, sum: int}> */
    private function totals(): array
    {
        $out = [];
        foreach (DB::table('replay_map_total')->where('map', 'Tumult')->get() as $row) {
            $out[$row->layer] = ['replays' => (int) $row->replays, 'sum' => array_sum(array_column(json_decode($row->cells, true), 1))];
        }
        ksort($out);

        return $out;
    }

    private function snapshot(): array
    {
        return DB::table('replay_map_total')->orderBy('map')->orderBy('layer')->get(['map', 'layer', 'replays', 'cells'])->map(fn ($r) => (array) $r)->all();
    }

    private function orders(): int
    {
        return array_sum(array_column(json_decode(DB::table('replay')->where('game_id', self::SF)->value('order_heat'), true)['cells'], 1));
    }

    public function test_a_replay_that_passes_its_check_is_added(): void
    {
        $this->listed();

        $share = DB::table('replay_map_share')->where('game_id', self::SF)->first();
        $this->assertSame('Tumult', $share->map);
        $this->assertSame((int) DB::table('replay')->value('duration'), (int) $share->duration);
        $this->assertCount(DB::table('replay_team')->whereNotNull('start_x')->count(), json_decode($share->starts, true));
        $this->assertGreaterThan(0, $this->orders());
        $this->assertSame(['orders' => ['replays' => 1, 'sum' => $this->orders()]], $this->totals());
    }

    public function test_adding_and_removing_replays_keeps_the_totals_right(): void
    {
        $this->listed();
        $one = $this->snapshot();
        $orders = $this->orders();

        $this->second();
        $this->assertSame(['orders' => ['replays' => 2, 'sum' => 2 * $orders]], $this->totals());

        $this->assertTrue(ReplayListing::remove(self::SECOND, 'rights'));
        $this->assertSame($one, $this->snapshot());
        $this->assertSame([self::SF], DB::table('replay_map_share')->pluck('game_id')->all());

        $this->assertTrue(ReplayListing::remove(self::SF, 'rights'));
        $this->assertSame([], $this->snapshot());
        $this->assertSame(0, DB::table('replay_map_share')->count());
    }

    public function test_counting_a_replay_again_adds_nothing(): void
    {
        $this->listed();
        $once = $this->snapshot();

        MapTotals::refresh(self::SF);
        MapTotals::refresh(self::SF);

        $this->assertSame($once, $this->snapshot());
    }

    public function test_an_analysis_adds_deaths_and_damage_and_a_newer_one_swaps_them(): void
    {
        $this->listed();
        $this->second();
        $this->assertSame('kept', $this->analyse());

        $expected = [
            'damage' => ['replays' => 1, 'sum' => 9038 + 158 + 158],
            'deaths' => ['replays' => 1, 'sum' => 7],
            'orders' => ['replays' => 2, 'sum' => 2 * $this->orders()],
        ];
        $this->assertSame($expected, $this->totals());

        // A run from a newer logger replaces the kept one, with the same match's figures.
        DB::table('replay_analysis')->update(['logger_version' => 5]);
        $this->assertSame('kept', $this->analyse());
        $this->assertSame($expected, $this->totals());

        // Working the facts out again counts them once too.
        ReplayAnalyses::writeFacts(self::SF);
        $this->assertSame($expected, $this->totals());

        ReplayListing::remove(self::SF, 'rights');
        $this->assertSame(['orders' => ['replays' => 1, 'sum' => $this->orders()]], $this->totals());
    }

    public function test_changed_facts_swap_the_replays_share(): void
    {
        $this->listed();
        $this->second();
        $heat = json_decode(DB::table('replay')->where('game_id', self::SF)->value('order_heat'), true);
        DB::table('replay')->where('game_id', self::SF)->update(['order_heat' => json_encode(['cells' => [[5, 3]]] + $heat)]);

        MapTotals::refresh(self::SF);

        $other = array_sum(array_column(json_decode(DB::table('replay')->where('game_id', self::SECOND)->value('order_heat'), true)['cells'], 1));
        $this->assertSame(['orders' => ['replays' => 2, 'sum' => $other + 3]], $this->totals());
    }

    public function test_the_command_counts_replays_listed_before_the_totals_and_drops_removed_ones(): void
    {
        $this->listed();
        $counted = $this->snapshot();
        DB::table('replay_map_share')->delete();
        DB::table('replay_map_total')->delete();

        $this->artisan('replays:map-totals --dry-run')->expectsOutput(self::SF)->expectsOutput('Would count 1 replay.')->assertOk();
        $this->assertSame([], $this->snapshot());

        $this->artisan('replays:map-totals')->expectsOutput('Counted 1 replay.')->assertOk();
        $this->assertSame($counted, $this->snapshot());
        $this->artisan('replays:map-totals')->expectsOutput('Counted 0 replays.')->assertOk();

        // A replay that stopped being listed without being taken out.
        DB::table('replay')->update(['state' => 'removed']);
        $this->artisan('replays:map-totals')->expectsOutput('Counted 1 replay.')->assertOk();
        $this->assertSame([], $this->snapshot());
    }

    public function test_the_map_page_shows_the_figures_with_the_replays_behind_each(): void
    {
        $this->listed();
        $this->second();
        $this->analyse();

        $this->get('/map/tumult')->assertOk()
            ->assertSee('Match lengths from 2 replays.')
            ->assertSee('Where it is played')
            ->assertSee('Start areas (2 replays)')
            ->assertSee('Order density (2 replays)')
            ->assertSee('Deaths (1 replay)')
            ->assertSee('Damage dealt (1 replay)')
            ->assertSee('/map/tumult/heat.png?v=', false)
            ->assertSee('from 2 replays')
            ->assertSee('Won 1 of 1, and 1 with no result')
            ->assertSee('Won 0 of 1, and 1 with no result')
            ->assertDontSee('This match set no start boxes.');
        $this->get('/map/tumult?heat=deaths')->assertOk()
            ->assertSee('/map/tumult/heat.png?layer=deaths&amp;v=', false)
            ->assertSee('Where units died')
            ->assertSee('of one spot, from 1 replay');

        foreach (['', '?layer=deaths', '?layer=damage'] as $query) {
            $this->get('/map/tumult/heat.png'.$query)->assertOk()->assertHeader('Content-Type', 'image/png');
        }
        $this->get('/map/tumult/heat.png?layer=none')->assertNotFound();
    }

    public function test_a_map_with_no_replays_shows_none_of_it(): void
    {
        $this->get('/map/tumult')->assertOk()
            ->assertDontSee('Replays</h2>', false)
            ->assertDontSee('Where it is played')
            ->assertDontSee('Start areas')
            ->assertDontSee('heat.png', false);
        $this->get('/map/tumult/heat.png')->assertNotFound();

        $this->listed();
        ReplayListing::remove(self::SF, 'rights');

        $this->get('/map/tumult')->assertOk()->assertDontSee('Where it is played')->assertDontSee('heat.png', false);
        $this->get('/map/tumult/heat.png')->assertNotFound();
    }

    public function test_starts_are_counted_at_declared_positions_and_otherwise_by_group(): void
    {
        $start = fn (string $game, float $x, float $z, ?bool $won) => ['game_id' => $game, 'x' => $x, 'z' => $z, 'won' => $won];
        $declared = [['x' => 1000, 'z' => 1000], ['x' => 3000, 'z' => 1000]];

        $areas = StartAreas::of([
            $start('a', 1100, 1000, true), $start('b', 900, 950, false), $start('c', 1000, 1000, null),
            // Outside both positions' reach of 1000 elmos, and within 1/32 of the map's side of each other.
            $start('a', 5000, 5000, false), $start('a', 5100, 5000, true),
            $start('b', 7000, 7000, true),
        ], $declared, 8192, 8192);

        $this->assertSame([
            ['number' => 1, 'declared' => true, 'x' => 1000.0, 'z' => 1000.0, 'taken' => 3, 'known' => 2, 'won' => 1, 'replays' => 3],
            ['number' => 3, 'declared' => false, 'x' => 5050.0, 'z' => 5000.0, 'taken' => 2, 'known' => 2, 'won' => 1, 'replays' => 1],
            ['number' => 4, 'declared' => false, 'x' => 7000.0, 'z' => 7000.0, 'taken' => 1, 'known' => 1, 'won' => 1, 'replays' => 1],
        ], $areas);
    }
}
