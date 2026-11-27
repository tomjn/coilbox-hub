<?php

namespace Tests\Feature\Replays;

use App\Domain\Replays\HeatPicture;
use App\Domain\Replays\OrderHeat;
use App\Domain\Replays\Stream\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ChecksReplays;
use Tests\Feature\Moderation\ModerationTestCase;

/**
 * Each replay's page (#667) over real replays put through the real check: a
 * Beyond All Reason three against three on Tangerine and a SplinterFaction
 * player against an AI on Tumult. No Metal Factions replay with a game over
 * exists yet (#673), so its page is shown from rows shaped as the check
 * writes them.
 */
class ReplayPageTest extends ModerationTestCase
{
    use ChecksReplays;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.games_store' => 'mariadb',
            'hub.maps_store' => 'mariadb',
            'hub.pictures_store' => 'mariadb',
            'hub.mirrors_store' => 'mariadb',
            'hub.items_store' => 'mariadb',
            'hub.mirrors.secret_key' => self::fixtureKeys()['hub_secret_key'],
            'hub.mirrors.next_secret_key' => '',
            'app.url' => 'https://hub.test',
        ]);
        $this->replayMirror();
        Queue::fake();
    }

    /** The map catalogue holds a map with a minimap and a licence that lets it be shown. */
    private function catalogue(string $mapName, string $slug, int $width, int $height): void
    {
        $now = '2026-10-01 10:00:00.000000';
        $n = DB::table('map')->count() + 1;
        DB::table('map')->insert([
            'id' => 'aaaaaaaa-0000-4000-8000-00000000000'.$n, 'map_name' => $mapName, 'slug' => $slug, 'display_name' => ucfirst($slug),
            'width_elmos' => $width, 'height_elmos' => $height, 'world_height_min' => 0.0, 'world_height_max' => 100.0,
            'appearance' => '{}', 'curated_tags' => '[]', 'source_hash' => 'h', 'source_archive' => $slug,
            'catalog_version' => 1, 'facts_digest' => 'd', 'seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        if (DB::table('asset_licence')->doesntExist()) {
            DB::table('asset_licence')->insert([
                'id' => 'bbbbbbbb-0000-4000-8000-000000000001', 'all_maps' => true, 'checked_at' => $now, 'checked_by' => 'test',
                'redistribute_extracted' => 'allowed', 'redistribute_rendered' => 'allowed', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('asset')->insert([
            'id' => 'cccccccc-0000-4000-8000-00000000000'.$n, 'map_name' => $mapName, 'variant' => 'minimap',
            'source_hash' => 'raw', 'hash' => str_repeat((string) $n, 64), 'encode_profile' => 'webp', 'path' => 'maps/'.$slug.'.webp',
            'origin' => 'extracted', 'tier' => 'static', 'mime' => 'image/webp', 'bytes' => 40, 'width' => 128, 'height' => 128,
            'source_archive' => $slug, 'seen_at' => $now, 'moderation' => 'approved', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function listBar(): void
    {
        $this->catalogue('Tangerine_Remake 1.0', 'tangerine', 8192, 8192);
        $this->uploaded(self::BAR, 'bar-tangerine', self::summary('bar-tangerine'), 'Charlson');
        $this->check(self::BAR);
        $this->assertSame('listed', DB::table('replay')->where('game_id', self::BAR)->value('state'));
    }

    private function listSf(): void
    {
        $this->catalogue('Tumult', 'tumult', 6144, 6144);
        $this->uploaded(self::SF, 'sf-tumult', self::summary('sf-tumult'), 'Player');
        $this->check(self::SF);
        $this->assertSame('listed', DB::table('replay')->where('game_id', self::SF)->value('state'));
    }

    public function test_the_check_keeps_what_the_page_draws(): void
    {
        $this->listBar();

        $replay = DB::table('replay')->where('game_id', self::BAR)->first();
        $this->assertNotNull($replay->page_facts_at);
        $heat = json_decode($replay->order_heat, true);
        $this->assertSame([256, 256, 8192, 8192], [$heat['width'], $heat['height'], $heat['world_width'], $heat['world_height']]);
        $this->assertGreaterThan(0, count($heat['cells']));
        $this->assertSame(json_decode($replay->start_boxes, true), array_values(json_decode($replay->start_boxes, true)));
        $teams = DB::table('replay_team')->where('game_id', self::BAR)->orderBy('team')->get();
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $teams[0]->colour);
        // Beyond All Reason's autohost keeps final totals only.
        $this->assertNull($teams[0]->history);
    }

    public function test_a_beyond_all_reason_replay_has_its_page(): void
    {
        $this->listBar();

        $page = $this->get('/replays/'.self::BAR)->assertOk()
            ->assertSee('<h1 class="break-words text-2xl font-semibold tracking-tight">Tangerine</h1>', false)
            ->assertSeeInOrder(['Beyond All Reason test-32071-e1cbffe', '2026.09.03', 'datetime="2026-11-26T20:10:37Z"', '15:35', 'Team 1 won'], false)
            ->assertSee('href="https://mirror.test/replays/'.self::BAR.'.sdfz"', false)
            ->assertSee('Download replay')
            ->assertDontSee('Download track')
            ->assertDontSee('Open in Coilbox')
            // The headline tiles, then the roster by side with the winner marked.
            ->assertSeeInOrder(['Duration', '15:35', 'Result', 'Team 1 won', 'Players', '6', 'Damage dealt', 'across 6 teams', 'Units built'])
            ->assertSeeInOrder(['Players', 'Team 1', 'Winner', 'Charlson', 'legcom', 'Won', 'Team 2', 'Harith23', 'Armada', 'Lost'])
            ->assertSee('Spectators: ')
            ->assertSee('Slimelamp')
            // Beyond All Reason's own awards.
            ->assertSee('Awards')
            ->assertSee('Most enemy fighting units destroyed')
            ->assertDontSee('Won every award')
            ->assertSee('Details')
            // The map card in heat mode.
            ->assertSee('Map &middot; Tangerine', false)
            ->assertSee('maps/tangerine.webp')
            ->assertSee('/replays/'.self::BAR.'/heat.png?v=', false)
            ->assertSee('Where orders were aimed')
            ->assertSee('Start positions')
            // No history, so no Economy tab.
            ->assertDontSee('>Economy</a>', false);
        $this->assertMatchesRegularExpression('#aria-current="page"\s+class="block whitespace-nowrap[^"]*">Summary</a>#', $page->getContent());
    }

    public function test_the_events_tab_lists_events_and_chat_but_no_hidden_chat(): void
    {
        $this->listBar();
        $hidden = DB::table('replay_chat')->where('game_id', self::BAR)->where('audience', 'everyone')->orderBy('seq')->first();
        DB::table('replay_chat')->where('game_id', self::BAR)->where('seq', $hidden->seq)->update(['hidden_at' => '2026-11-27 10:00:00', 'hidden_by' => self::USER]);
        $allies = DB::table('replay_chat')->where('game_id', self::BAR)->where('audience', 'allies')->first();

        $page = $this->get('/replays/'.self::BAR.'?tab=events')->assertOk()
            ->assertSee('Events and chat')
            ->assertSee(e($allies->name.' to allies: '.$allies->text), false)
            ->assertSee('Game over. Team 1 won')
            ->assertDontSee(e($hidden->name.': '.$hidden->text), false)
            // 45 visible messages, the leaves, the deaths and one game over.
            ->assertSee('51 of 51 entries match.');
        $this->assertSame(1, substr_count($page->getContent(), 'Game over.'));

        $this->get('/replays/'.self::BAR.'?tab=events&show=chat')->assertSee('45 of 51 entries match.')->assertDontSee('Game over.');
        $this->get('/replays/'.self::BAR.'?tab=events&show=players')->assertSee('6 of 51 entries match.');
    }

    public function test_the_build_orders_tab_shows_each_team_and_cuts_the_opening(): void
    {
        $this->listBar();

        $this->get('/replays/'.self::BAR.'?tab=builds')->assertOk()
            ->assertSee('Build orders')
            ->assertSee('Cost by kind of unit')
            ->assertSee('Opening length in minutes')
            ->assertSeeInOrder(['Opening', '0:15', 'cfaa'], false)
            ->assertDontSee('All orders');

        $this->get('/replays/'.self::BAR.'?tab=builds&minutes=1')->assertOk()
            ->assertSee('Cost by kind of unit, to minute 1')
            ->assertSee('Opening, first 1 min')
            ->assertSee('All orders');
        $this->get('/replays/'.self::BAR.'?tab=builds&minutes=soon')->assertOk()->assertDontSee('All orders');
    }

    public function test_a_splinter_faction_replay_has_its_page_with_charts(): void
    {
        $this->listSf();

        $this->get('/replays/'.self::SF)->assertOk()
            ->assertSee('<h1 class="break-words text-2xl font-semibold tracking-tight">Tumult</h1>', false)
            ->assertSeeInOrder(['SplinterFaction 0.1.95', '8:21', 'Team 1 won'])
            ->assertSeeInOrder(['Players', '2', '1 of them bots'])
            ->assertSee('Enemy')
            ->assertSee('Bot')
            ->assertDontSee('Awards')
            ->assertSee('>Economy</a>', false)
            ->assertSee('This match set no start boxes.');

        $this->get('/replays/'.self::SF.'?tab=economy')->assertOk()
            ->assertSee('Match statistics')
            ->assertSee('Metal produced over the match.')
            ->assertSee('<polyline', false)
            // The start script gives no colours, so Coilbox's chart palette tells the teams apart.
            ->assertSee('stroke="#b979c5"', false)
            ->assertSee('stroke="#27a902"', false);
    }

    public function test_a_metal_factions_replay_has_its_page(): void
    {
        $gameId = 'ee000000000000000000000000000001';
        DB::table('replay')->insert([
            'game_id' => $gameId, 'state' => 'listed', 'uploader' => self::USER, 'sha256' => str_repeat('e', 64), 'size' => 120000,
            'upload_mirror_id' => 'jj-darkstars', 'upload_id' => '33333333-3333-4333-8333-333333333334', 'summary' => '{"units":[]}',
            'created_at' => '2026-11-26 09:00:00', 'updated_at' => '2026-11-26 09:00:00',
            'game' => 'Metal Factions', 'game_version' => 'v2.58', 'map' => 'Valles Marineris 2.6.1', 'engine' => '2026.09.01',
            'started_at' => '2026-11-20 18:00:00', 'duration' => 1260, 'winners' => '[1]', 'player_count' => 2,
            'events' => '[{"frame":37800,"type":"resign","player":0,"data":[]}]', 'extra' => '{}', 'checked_at' => '2026-11-26 09:05:00',
            'start_boxes' => '[]', 'order_heat' => null, 'page_facts_at' => '2026-11-26 09:05:00',
        ]);
        foreach ([0, 1] as $team) {
            DB::table('replay_team')->insert([
                'game_id' => $gameId, 'team' => $team, 'ally_team' => $team, 'won' => $team === 1, 'build_orders' => '[]', 'samples' => 1,
                'damage_dealt' => 5000.0 * ($team + 1), 'units_produced' => 40 + $team, 'colour' => $team === 0 ? '#ff0000' : '#0000ff',
            ]);
        }
        DB::table('replay_player')->insert([
            ['game_id' => $gameId, 'kind' => 'player', 'number' => 0, 'name' => 'Aven', 'team' => 0, 'ally_team' => 0, 'faction' => 'Aven', 'won' => false],
            ['game_id' => $gameId, 'kind' => 'player', 'number' => 1, 'name' => 'Gear', 'team' => 1, 'ally_team' => 1, 'faction' => null, 'won' => true],
        ]);

        $this->get('/replays/'.$gameId)->assertOk()
            ->assertSee('Valles Marineris 2.6.1')
            ->assertSeeInOrder(['Metal Factions v2.58', '21:00', 'Team 2 won'])
            ->assertSeeInOrder(['Team 1', 'Aven', 'Aven', 'Lost', 'Team 2', 'Winner', 'Gear', 'Won'])
            ->assertSee("The hub's map catalogue does not have this map", false)
            // Coilbox's `formatTotal` shortens from 10,000.
            ->assertSee('15K');
        $this->get('/replays/'.$gameId.'?tab=events')->assertSee('Aven resigned');
    }

    public function test_an_unknown_unlisted_or_malformed_game_id_is_not_found(): void
    {
        $this->listSf();

        $this->get('/replays/ffffffffffffffffffffffffffffffff')->assertNotFound();
        $this->get('/replays/not-a-game-id')->assertNotFound();
        $this->get('/replays/'.strtoupper(self::SF))->assertNotFound();
        DB::table('replay')->where('game_id', self::SF)->update(['state' => 'removed']);
        $this->get('/replays/'.self::SF)->assertNotFound();
        $this->get('/replays/'.self::SF.'/heat.png')->assertNotFound();
    }

    public function test_the_heat_picture_is_a_png_of_the_grid(): void
    {
        $this->listSf();

        $response = $this->get('/replays/'.self::SF.'/heat.png')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        [$width, $height] = getimagesizefromstring($response->getContent());
        $this->assertSame([256, 256], [$width, $height]);
    }

    public function test_a_track_and_an_unhealthy_mirror_change_the_downloads(): void
    {
        $this->listSf();
        DB::table('replay_track')->insert([
            'game_id' => self::SF, 'format_version' => 1, 'state' => 'listed', 'uploader' => self::USER, 'sha256' => str_repeat('d', 64),
            'size' => 100, 'upload_mirror_id' => 'jj-darkstars', 'upload_id' => '44444444-4444-4444-8444-444444444444',
            'recorder_version' => 1, 'created_at' => '2026-11-26 09:00:00', 'updated_at' => '2026-11-26 09:00:00',
        ]);

        $this->get('/replays/'.self::SF)->assertSee('href="https://mirror.test/tracks/'.self::SF.'.cbtrack"', false)->assertSee('Download track');

        DB::table('mirror')->update(['healthy' => false]);
        $this->get('/replays/'.self::SF)->assertOk()
            ->assertDontSee('Download track')
            ->assertDontSee('Download replay')
            ->assertSee('The replay file is not available to download just now.');
    }

    public function test_a_replay_with_no_map_size_gets_heat_when_its_facts_are_read_again(): void
    {
        $this->uploaded(self::SF, 'sf-tumult', self::summary('sf-tumult'), 'Player');
        $this->check(self::SF);
        $this->assertNull(DB::table('replay')->where('game_id', self::SF)->value('order_heat'));
        $this->artisan('replays:page-facts')->expectsOutputToContain('Wrote 0 replays.')->assertSuccessful();

        $this->catalogue('Tumult', 'tumult', 6144, 6144);
        $this->artisan('replays:page-facts --dry-run')->expectsOutputToContain('Would write 1 replay.')->assertSuccessful();
        $this->assertNull(DB::table('replay')->where('game_id', self::SF)->value('order_heat'));

        Http::fake(['https://mirror.test/replays/'.self::SF.'.sdfz' => Http::response(self::file('sf-tumult'))]);
        $this->artisan('replays:page-facts')->expectsOutputToContain('Wrote 1 replay.')->assertSuccessful();
        $this->assertNotNull(DB::table('replay')->where('game_id', self::SF)->value('order_heat'));
    }

    public function test_a_replay_listed_before_the_page_facts_gets_them_from_its_file(): void
    {
        $this->listSf();
        DB::table('replay')->update(['start_boxes' => null, 'order_heat' => null, 'page_facts_at' => null]);
        DB::table('replay_team')->update(['colour' => null, 'history' => null]);
        $this->get('/replays/'.self::SF)->assertOk()->assertDontSee('>Economy</a>', false)->assertSee('This replay has no order heat yet.');

        $this->artisan('replays:page-facts')->expectsOutputToContain('Wrote 1 replay.')->assertSuccessful();

        $this->get('/replays/'.self::SF)->assertOk()->assertSee('>Economy</a>', false)->assertSee('Where orders were aimed');
        $this->assertSame(35, count(json_decode(DB::table('replay_team')->where('game_id', self::SF)->where('team', 0)->value('history'), true)['frame']));
    }

    public function test_orders_are_counted_on_coilboxs_grid(): void
    {
        $this->assertSame(['width' => 256, 'height' => 128, 'world_width' => 8192, 'world_height' => 4096], OrderHeat::grid(8192, 4096));
        $this->assertSame(['width' => 171, 'height' => 256, 'world_width' => 6144, 'world_height' => 9216], OrderHeat::grid(6144, 9216));
        $this->assertNull(OrderHeat::grid(0, 4096));

        $grid = OrderHeat::grid(8192, 8192);
        $this->assertSame(0, OrderHeat::cell($grid, 0, 0));
        $this->assertSame(255 * 256 + 255, OrderHeat::cell($grid, 8192, 8192));
        $this->assertSame(1 * 256 + 2, OrderHeat::cell($grid, 70, 40));
        $this->assertNull(OrderHeat::cell($grid, -1, 10));
        $this->assertNull(OrderHeat::cell($grid, 10, 8193));

        // A move, a build, a unit target, a stop, an area reclaim, a repair inside an area, an insert, and an unknown command.
        $this->assertSame([100.0, 200.0], OrderHeat::aim(new Command(10, 0, [100.0, 5.0, 200.0])));
        $this->assertSame([1.0, 3.0], OrderHeat::aim(new Command(-7, 0, [1.0, 2.0, 3.0, 0.0])));
        $this->assertNull(OrderHeat::aim(new Command(-7, 0, [])));
        $this->assertNull(OrderHeat::aim(new Command(20, 0, [5.0])));
        $this->assertNull(OrderHeat::aim(new Command(0, 0, [])));
        $this->assertSame([9.0, 8.0], OrderHeat::aim(new Command(90, 0, [9.0, 0.0, 8.0, 300.0])));
        $this->assertSame([9.0, 8.0], OrderHeat::aim(new Command(40, 0, [77.0, 9.0, 0.0, 8.0, 300.0])));
        $this->assertSame([4.0, 6.0], OrderHeat::aim(new Command(1, 0, [0.0, 10.0, 0.0, 4.0, 5.0, 6.0])));
        $this->assertNull(OrderHeat::aim(new Command(31001, 0, [1.0, 2.0, 3.0])));
        $this->assertNull(OrderHeat::aim(new Command(10, 0, [NAN, 0.0, 1.0])));
    }

    public function test_the_legend_counts_orders_near_the_brightest_spot(): void
    {
        $grid = OrderHeat::grid(4096, 4096);
        $heat = $grid + ['cells' => [[OrderHeat::cell($grid, 1000, 1000), 5], [OrderHeat::cell($grid, 1050, 1000), 2], [OrderHeat::cell($grid, 3500, 3500), 1]]];

        $field = HeatPicture::field($heat);

        $this->assertSame(128.0, $field['radius']);
        $this->assertSame(7, $field['peak_within_radius']);
        $this->assertSame(8, $field['counted']);
    }
}
