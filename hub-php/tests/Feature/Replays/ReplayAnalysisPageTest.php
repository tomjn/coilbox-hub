<?php

namespace Tests\Feature\Replays;

use App\Domain\Replays\AnalysisFacts;
use App\Domain\Replays\AnalysisLog;
use App\Domain\Replays\OrderHeat;
use App\Domain\Replays\ReplayAnalyses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ChecksReplays;
use Tests\Concerns\SeedsGameCatalogue;
use Tests\Feature\Moderation\ModerationTestCase;

/**
 * The analysis parts of a replay's page (#668), from the real SplinterFaction
 * replay on Tumult and the log Coilbox's replay logger wrote playing it back,
 * both put through the hub's real checks.
 */
class ReplayAnalysisPageTest extends ModerationTestCase
{
    use ChecksReplays;
    use RefreshDatabase;
    use SeedsGameCatalogue;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.games_store' => 'mariadb', 'hub.maps_store' => 'mariadb', 'hub.pictures_store' => 'mariadb',
            'hub.mirrors_store' => 'mariadb', 'hub.items_store' => 'mariadb', 'app.url' => 'https://hub.test',
        ]);
        $this->replayMirror();
        Queue::fake();
        $this->uploaded(self::SF, 'sf-tumult', self::summary('sf-tumult'), 'Player');
        $this->check(self::SF);
        $this->assertSame('listed', DB::table('replay')->where('game_id', self::SF)->value('state'));
    }

    private static function events(): string
    {
        return file_get_contents(base_path('tests/Fixtures/replays/sf-tumult.events.jsonl'));
    }

    /** Sends the real log as `$user` and runs the real check on it. */
    private function analysed(string $user = self::USER): string
    {
        $events = self::events();
        $id = DB::table('replay_analysis_submission')->insertGetId([
            'game_id' => self::SF, 'user_id' => $user, 'logger_version' => 6, 'sha256' => hash('sha256', $events),
            'size' => strlen($events), 'events' => $events, 'state' => 'checking', 'sent_at' => '2026-11-26 10:00:00.000000',
        ]);

        return ReplayAnalyses::check($id);
    }

    public function test_a_replay_with_no_analysis_shows_none_of_its_parts(): void
    {
        $this->get('/replays/'.self::SF)->assertOk()
            ->assertSee('Details')
            ->assertDontSee('id="replay-analysis"', false)
            ->assertDontSee('Value of living units')
            ->assertDontSee('Finished, kills and losses')
            ->assertDontSee('Awards')
            ->assertDontSee('Where units died')
            ->assertDontSee('aria-label="Heat layer"', false);
        $this->get('/replays/'.self::SF.'/heat.png?layer=deaths')->assertNotFound();
    }

    /** The real Tumult track, listed on the mirror the replay was uploaded to. */
    private function tracked(): void
    {
        DB::table('replay_track')->insert([
            'game_id' => self::SF, 'format_version' => 1, 'state' => 'listed', 'uploader' => self::USER,
            'sha256' => hash_file('sha256', base_path('tests/Fixtures/replays/sf-tumult.cbtrack')),
            'size' => filesize(base_path('tests/Fixtures/replays/sf-tumult.cbtrack')), 'upload_mirror_id' => 'jj-darkstars',
            'upload_id' => '44444444-4444-4444-8444-444444444444', 'recorder_version' => 5,
            'created_at' => '2026-11-26 09:00:00', 'updated_at' => '2026-11-26 09:00:00',
        ]);
    }

    public function test_a_replay_with_neither_has_no_units_mode(): void
    {
        $this->get('/replays/'.self::SF)->assertOk()
            ->assertDontSee('data-replay-units', false)
            ->assertDontSee('data-units-canvas', false)
            ->assertDontSee('resources/js/replays/units.ts', false)
            ->assertDontSee('Download the track');
    }

    public function test_a_replay_with_a_track_has_the_units_mode_reading_it_from_its_mirror(): void
    {
        $this->tracked();

        $page = $this->get('/replays/'.self::SF)->assertOk()
            ->assertSee('data-replay-units data-track="https://mirror.test/tracks/'.self::SF.'.cbtrack"', false)
            // The page's own team colours, which are Coilbox's chart palette for this match.
            ->assertSee('data-colours="{&quot;0&quot;:&quot;#b979c5&quot;,&quot;1&quot;:&quot;#27a902&quot;}"', false)
            ->assertSee('<canvas data-units-canvas hidden', false)
            ->assertSee('data-units-modes hidden', false)
            ->assertSee('data-units-controls hidden', false)
            ->assertSeeInOrder(['data-play', 'data-scrub', 'data-time', 'data-speed'], false)
            // What shows in place of the player when a browser cannot read the track.
            ->assertSee('The track could not be read from its mirror in this browser')
            ->assertSee('<a href="https://mirror.test/tracks/'.self::SF.'.cbtrack" download', false);
        // The heat mode is still what shows without JavaScript.
        $this->assertSame(2, substr_count($page->getContent(), 'data-heat-view'));
        $this->assertMatchesRegularExpression('#<script type="module" src="[^"]*units[^"]*\.js"#', $page->getContent());

        // With the mirror unhealthy the hub knows of no copy to read.
        DB::table('mirror')->update(['healthy' => false]);
        $this->get('/replays/'.self::SF)->assertOk()->assertDontSee('data-replay-units', false)->assertDontSee('units-', false);
    }

    public function test_a_replay_with_an_analysis_and_a_track_has_both(): void
    {
        $this->analysed();
        $this->tracked();

        $this->get('/replays/'.self::SF)->assertOk()
            ->assertSee('data-replay-units', false)
            ->assertSee('aria-label="Heat layer"', false)
            ->assertSee('Value of living units');
    }

    public function test_the_kept_analysis_has_its_facts_worked_out_from_the_real_log(): void
    {
        $this->assertSame('kept', $this->analysed());

        $facts = json_decode(DB::table('replay_analysis')->where('game_id', self::SF)->value('facts'), true);
        $this->assertSame(AnalysisFacts::VERSION, $facts['v']);
        // The log's header says Tumult is 7168 elmos square, which is Coilbox's 256 cell grid.
        $this->assertSame(['width' => 256, 'height' => 256, 'world_width' => 7168, 'world_height' => 7168], $facts['grid']);
        // Seven units died, each in a cell of its own.
        $this->assertCount(7, $facts['deaths']);
        $this->assertSame(7, array_sum(array_column($facts['deaths'], 1)));
        // The five damage lines into cell 34022 add up to 600 + 600 + 1950 + 5250 + 638.
        $this->assertSame([[34022, 9038.0], [34024, 158.0], [34281, 158.0]], $facts['damage']);
        // The trailer samples every 450 frames, so the chart does too, to the last whole period before frame 15054.
        $this->assertSame(15, $facts['living']['period']);
        $this->assertSame(range(0, 495, 15), $facts['living']['seconds']);
        // The summary gives no unit a cost, so nothing is priced.
        $this->assertSame([71, 71, []], [$facts['living']['finished'], $facts['living']['unpriced'], $facts['living']['teams']]);
        $this->assertSame([
            ['team' => 0, 'buildings' => 0, 'units' => 0, 'unknown' => 70, 'kills' => 1, 'losses' => 6],
            ['team' => 1, 'buildings' => 0, 'units' => 0, 'unknown' => 1, 'kills' => 0, 'losses' => 1],
        ], $facts['teams']);
        // The awards that need no cost. The commander kill at frame 14893 ends team 0's wait, and team 1 lost its last unit then.
        $this->assertSame([
            ['id' => 'produced', 'places' => [['place' => 1, 'team' => 0, 'score' => 9363.98046875]]],
            ['id' => 'damageTaken', 'places' => [['place' => 1, 'team' => 1, 'score' => 8850.0]]],
            ['id' => 'noKill', 'places' => [['place' => 1, 'team' => 0, 'score' => 14893 / 30], ['place' => 1, 'team' => 1, 'score' => 14893 / 30]]],
        ], $facts['awards']['awards']);
        $this->assertSame(1, $facts['awards']['unpriced']);
    }

    public function test_a_replay_with_an_analysis_shows_its_parts(): void
    {
        $this->analysed();
        $this->analysed('33333333-3333-4333-8333-333333333333');

        $page = $this->get('/replays/'.self::SF)->assertOk()
            ->assertSeeInOrder(['Awards', 'Most metal produced', 'Player', '9,364 metal', 'Most damage taken', 'Enemy (AI)', '8,850 damage', 'Longest time without a kill', '8:16'])
            ->assertSee('Worked out from the analysis below, as Coilbox works them out.')
            ->assertSee('One enemy unit destroyed has no known cost and counts nothing.')
            ->assertSeeInOrder(['Details', 'Analysis', '2 people sent separate analyses that agreed.', 'Value of living units', 'Finished, kills and losses'])
            ->assertSee('No finished unit with a metal cost is known for this match, so there is nothing to draw.')
            ->assertSee('71 finished units have no known cost, of 71, and add nothing.')
            ->assertSee('Finished, not known which')
            ->assertSeeInOrder(['Player', '0', '0', '70', '1', '6'])
            // The map card's heat layers. The catalogue has no Tumult, so there is no order heat and deaths come first.
            ->assertSee('aria-label="Heat layer"', false)
            ->assertSee('Where units died')
            ->assertSee('3 deaths within 224 elmos of one spot')
            ->assertSee('/replays/'.self::SF.'/heat.png?layer=deaths&amp;v=', false);
        $this->assertStringContainsString('?heat=damage', $page->getContent());

        $this->get('/replays/'.self::SF.'?heat=damage')->assertOk()
            ->assertSee('Where damage landed')
            ->assertSee('9,354 damage landed within 224 elmos of one spot')
            ->assertSee('/replays/'.self::SF.'/heat.png?layer=damage&amp;v=', false);
        $this->get('/replays/'.self::SF.'?value=energy')->assertOk()->assertSee('No finished unit with an energy cost');

        foreach (['deaths', 'damage'] as $layer) {
            $png = $this->get('/replays/'.self::SF.'/heat.png?layer='.$layer)->assertOk()->assertHeader('Content-Type', 'image/png')->getContent();
            $this->assertSame([256, 256], array_slice(getimagesizefromstring($png), 0, 2));
        }
        $this->get('/replays/'.self::SF.'/heat.png?layer=nothing')->assertNotFound();
        // A different shape of facts, from a later hub, is not drawn until it is worked out again.
        DB::table('replay_analysis')->update(['facts' => '{"v":99}']);
        $this->get('/replays/'.self::SF)->assertOk()->assertDontSee('Value of living units');
    }

    public function test_analyses_kept_before_their_facts_get_them_from_the_backfill(): void
    {
        $this->analysed();
        $facts = DB::table('replay_analysis')->value('facts');
        DB::table('replay_analysis')->update(['facts' => null]);
        $this->get('/replays/'.self::SF)->assertOk()->assertDontSee('Value of living units');

        $this->artisan('replays:analysis-facts --dry-run')->expectsOutputToContain('Would write 1 analysis.')->assertSuccessful();
        $this->assertNull(DB::table('replay_analysis')->value('facts'));
        $this->artisan('replays:analysis-facts')->expectsOutputToContain('Wrote 1 analysis.')->assertSuccessful();
        $this->assertSame(json_decode($facts, true), json_decode(DB::table('replay_analysis')->value('facts'), true));
        $this->artisan('replays:analysis-facts')->expectsOutputToContain('Wrote 0 analyses.')->assertSuccessful();
        $this->get('/replays/'.self::SF)->assertOk()->assertSee('Value of living units');
    }

    public function test_recorded_awards_win_over_ones_worked_out(): void
    {
        DB::table('replay')->where('game_id', self::SF)->update(['extra' => json_encode(['bar.awards' => [['name' => 'sleep', 'team' => 1, 'value' => 30]]])]);
        $this->analysed();

        $this->get('/replays/'.self::SF)->assertOk()
            ->assertSee('Longest time without a kill')
            ->assertSee('As SplinterFaction recorded them in the replay.')
            ->assertDontSee('Most metal produced')
            ->assertSee('Value of living units');
    }

    /**
     * A small match worked by hand: team 0 finishes a fighting unit and gives
     * it to team 1, then destroys team 1's metal extractor. The catalogue
     * says the extractor stands still.
     */
    public function test_living_value_awards_and_counts_follow_coilboxs_rules(): void
    {
        DB::table('game')->insert($this->gameRow(self::GAME, 'TG', ['display_name' => 'Test Game']));
        DB::table('game_unit')->insert($this->unitRow(1, self::GAME, 'tgmex', ['stats' => '{"metalCost":50,"maxVelocity":0}']));
        $replay = (object) ['game' => 'Test Game', 'summary' => json_encode(['units' => [
            ['unit_def_id' => 1, 'name' => 'tgtank', 'display_name' => null, 'metal' => 100, 'energy' => 1000, 'build_time' => null, 'category' => 'offence'],
            ['unit_def_id' => 2, 'name' => 'tgmex', 'display_name' => null, 'metal' => 0, 'energy' => 0, 'build_time' => null, 'category' => 'economy'],
        ]])];
        $teams = [
            (object) ['team' => 0, 'ally_team' => 0, 'history' => json_encode(['frame' => [0, 300, 600]])],
            (object) ['team' => 1, 'ally_team' => 1, 'history' => null],
        ];
        $lines = [
            ['kind' => 'header', 'format' => 1, 'mapSizeX' => 4096, 'mapSizeZ' => 4096, 'gridWidth' => 256, 'gridHeight' => 256],
            ['kind' => 'game_start', 'frame' => 0],
            ['kind' => 'unit_created', 'frame' => 30, 'unit' => 1, 'def' => 1, 'team' => 0],
            ['kind' => 'unit_finished', 'frame' => 60, 'unit' => 1, 'def' => 1, 'team' => 0],
            ['kind' => 'unit_finished', 'frame' => 60, 'unit' => 2, 'def' => 2, 'team' => 1],
            ['kind' => 'unit_given', 'frame' => 400, 'unit' => 1, 'def' => 1, 'team' => 1, 'from' => 0],
            ['kind' => 'damage', 'frame' => 450, 'team' => 0, 'target' => 1, 'at' => [5, 12.5, 5, 7.5, 70000, 1]],
            ['kind' => 'unit_destroyed', 'frame' => 700, 'unit' => 2, 'def' => 2, 'team' => 1, 'x' => 1000.0, 'z' => 2000.0, 'attackerTeam' => 0],
            ['kind' => 'game_over', 'frame' => 900, 'winners' => [0], 'teams' => [
                ['team' => 0, 'metalProduced' => 500, 'metalUsed' => 200, 'damageReceived' => 0],
                ['team' => 1, 'metalProduced' => 300, 'metalUsed' => 100, 'damageReceived' => 40],
            ]],
        ];
        $log = AnalysisLog::parse(implode("\n", array_map('json_encode', $lines)));

        $facts = AnalysisFacts::of($log, $replay, $teams);

        $grid = $facts['grid'];
        $this->assertSame([[OrderHeat::cell($grid, 1000.0, 2000.0), 1]], $facts['deaths']);
        // The cell past the grid is left out.
        $this->assertSame([[5, 20.0]], $facts['damage']);
        // Metal then energy for economy, defence, offence, other and unclassified, every 10 seconds.
        $zero = array_fill(0, 10, 0);
        $tank = [0, 0, 0, 0, 100, 1000, 0, 0, 0, 0];
        $this->assertSame(['period' => 10, 'seconds' => [0, 10, 20, 30], 'teams' => [
            ['team' => 0, 'samples' => [$zero, $tank, $zero, $zero]],
            ['team' => 1, 'samples' => [$zero, [50, 0, ...array_fill(0, 8, 0)], [50, 0, 0, 0, 100, 1000, 0, 0, 0, 0], $tank]],
        ], 'finished' => 2, 'unpriced' => 0], $facts['living']);
        $this->assertSame([
            ['team' => 0, 'buildings' => 0, 'units' => 1, 'unknown' => 0, 'kills' => 1, 'losses' => 0],
            ['team' => 1, 'buildings' => 1, 'units' => 0, 'unknown' => 0, 'kills' => 0, 'losses' => 1],
        ], $facts['teams']);
        // Team 0 held nothing from frame 400, so its kill ends no wait, and team 1 never killed.
        $this->assertSame([
            ['id' => 'economyKilled', 'places' => [['place' => 1, 'team' => 0, 'score' => 50.0]]],
            ['id' => 'efficiency', 'places' => [['place' => 1, 'team' => 0, 'score' => 0.25]]],
            ['id' => 'produced', 'places' => [['place' => 1, 'team' => 0, 'score' => 500.0]]],
            ['id' => 'damageTaken', 'places' => [['place' => 1, 'team' => 1, 'score' => 40.0]]],
            ['id' => 'noKill', 'places' => [['place' => 1, 'team' => 1, 'score' => 30.0]]],
        ], $facts['awards']['awards']);
    }
}
