<?php

namespace Tests\Feature\Replays;

use App\Domain\Replays\AnalysisFacts;
use App\Domain\Replays\ReplayData;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** The weekly replay data files and the `/replays/data` page that describes them (#672). */
class ReplayDataTest extends TestCase
{
    use RefreshDatabase;

    private const LISTED = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const REMOVED = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/replay-data-test-'.getmypid();
        File::ensureDirectoryExists($this->storage.'/app');
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function replay(string $gameId, string $state): void
    {
        DB::table('replay')->insert([
            'game_id' => $gameId, 'state' => $state, 'uploader' => '11111111-1111-1111-1111-111111111111',
            'sha256' => str_repeat('0', 64), 'size' => 100, 'upload_id' => '22222222-2222-2222-2222-222222222222',
            'summary' => json_encode(['units' => [['unit_def_id' => 7, 'name' => 'armcom'], ['unit_def_id' => 9, 'name' => 'armmex']]]),
            'created_at' => '2026-11-26 09:00:00', 'updated_at' => '2026-11-26 09:00:00',
            'game' => 'Beyond All Reason', 'game_version' => 'test-1', 'map' => 'Tangerine, "the" one', 'engine' => '2025.04',
            'started_at' => '2026-11-26 08:00:00', 'duration' => 935, 'winners' => json_encode([1]), 'player_count' => 2,
        ]);
        foreach ([0, 1] as $team) {
            DB::table('replay_team')->insert([
                'game_id' => $gameId, 'team' => $team, 'ally_team' => $team, 'won' => $team === 1,
                'metal_used' => 1500.5, 'units_killed' => 4 + $team, 'start_x' => 100.0 * ($team + 1), 'start_z' => 250.25,
                'build_orders' => json_encode($team === 0
                    ? [['frame' => 45, 'unit_def_id' => 9], ['frame' => 90, 'unit_def_id' => 9], ['frame' => 120, 'unit_def_id' => 3]]
                    : []),
            ]);
        }
        DB::table('replay_player')->insert([
            ['game_id' => $gameId, 'kind' => 'player', 'number' => 0, 'name' => '=Zoë, "the" first', 'team' => 0, 'ally_team' => 0,
                'faction' => 'armcom', 'won' => false, 'commands' => 300, 'unit_commands' => 200, 'mouse_pixels' => 9000, 'mouse_clicks' => 400, 'key_presses' => 50],
            ['game_id' => $gameId, 'kind' => 'player', 'number' => 1, 'name' => 'Second', 'team' => 1, 'ally_team' => 1,
                'faction' => null, 'won' => true, 'commands' => null, 'unit_commands' => null, 'mouse_pixels' => null, 'mouse_clicks' => null, 'key_presses' => null],
            ['game_id' => $gameId, 'kind' => 'spectator', 'number' => 2, 'name' => 'Watcher', 'team' => 0, 'ally_team' => 0,
                'faction' => null, 'won' => null, 'commands' => null, 'unit_commands' => null, 'mouse_pixels' => null, 'mouse_clicks' => null, 'key_presses' => null],
        ]);
        DB::table('replay_chat')->insert([
            'game_id' => $gameId, 'seq' => 0, 'frame' => 10, 'player' => 0, 'name' => 'Second', 'audience' => 'everyone', 'text' => 'a secret word',
        ]);
    }

    private function analyse(string $gameId, int $version = AnalysisFacts::VERSION): void
    {
        $id = DB::table('replay_analysis_submission')->insertGetId([
            'game_id' => $gameId, 'user_id' => '11111111-1111-1111-1111-111111111111', 'logger_version' => 6, 'sha256' => str_repeat('1', 64),
            'size' => 2, 'events' => null, 'state' => 'kept', 'sent_at' => '2026-11-26 10:00:00',
        ]);
        DB::table('replay_analysis')->insert([
            'game_id' => $gameId, 'logger_version' => 6, 'submission_id' => $id, 'sha256' => str_repeat('1', 64), 'events' => '',
            'counts' => '{}', 'kept_at' => '2026-11-26 10:00:00',
            'facts' => json_encode(['v' => $version, 'teams' => [
                ['team' => 0, 'buildings' => 3, 'units' => 5, 'unknown' => 1, 'kills' => 2, 'losses' => 6],
                ['team' => 1, 'buildings' => 4, 'units' => 7, 'unknown' => 0, 'kills' => 6, 'losses' => 2],
            ]]),
        ]);
    }

    /** @return list<array<string, string>> */
    private function rows(string $week, string $file): array
    {
        $handle = fopen(ReplayData::root()."/{$week}/{$file}.csv", 'r');
        $header = fgetcsv($handle, null, ',', '"', '');
        $rows = [];
        while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = array_combine($header, $line);
        }
        fclose($handle);

        return $rows;
    }

    public function test_the_files_hold_listed_replays_only_and_no_chat(): void
    {
        $this->replay(self::LISTED, 'listed');
        $this->replay(self::REMOVED, 'removed');
        $this->analyse(self::LISTED);

        $this->artisan('replays:data')->assertSuccessful();
        $week = gmdate('Y-m-d');

        $matches = $this->rows($week, 'matches');
        $this->assertSame([self::LISTED], array_column($matches, 'game_id'));
        $this->assertSame([
            'game' => 'Beyond All Reason', 'game_version' => 'test-1', 'map' => 'Tangerine, "the" one', 'engine' => '2025.04',
            'started_at' => '2026-11-26 08:00:00', 'duration_seconds' => '935', 'player_count' => '2', 'spectator_count' => '1',
            'ai_count' => '0', 'winning_ally_teams' => '1', 'analysed' => 'true', 'analysis_logger_version' => '6',
        ], array_slice($matches[0], 1));

        $players = $this->rows($week, 'players');
        $this->assertSame(['=Zoë, "the" first', 'Second', 'Watcher'], array_column($players, 'name'));
        $this->assertSame(['false', 'true', ''], array_column($players, 'won'));
        $this->assertSame(['0', '1', ''], array_column($players, 'team'));
        $this->assertSame(['armcom', '', ''], array_column($players, 'faction'));
        $this->assertSame(['300', '', ''], array_column($players, 'commands'));
        $this->assertSame(['1500.5', '1500.5', ''], array_column($players, 'team_metal_used'));
        $this->assertSame(['4', '5', ''], array_column($players, 'team_units_killed'));
        $this->assertSame(['', '', ''], array_column($players, 'team_energy_used'));
        $this->assertSame(['100', '200', ''], array_column($players, 'team_start_x'));
        $this->assertSame(['3', '4', ''], array_column($players, 'team_buildings_finished'));
        $this->assertSame(['1', '0', ''], array_column($players, 'team_other_finished'));
        $this->assertSame(['6', '2', ''], array_column($players, 'team_losses'));

        $orders = $this->rows($week, 'build_orders');
        $this->assertSame([
            ['game_id' => self::LISTED, 'team' => '0', 'position' => '1', 'frame' => '45', 'seconds' => '1.5', 'unit_def_id' => '9', 'unit_name' => 'armmex'],
            ['game_id' => self::LISTED, 'team' => '0', 'position' => '2', 'frame' => '90', 'seconds' => '3', 'unit_def_id' => '9', 'unit_name' => 'armmex'],
            ['game_id' => self::LISTED, 'team' => '0', 'position' => '3', 'frame' => '120', 'seconds' => '4', 'unit_def_id' => '3', 'unit_name' => ''],
        ], $orders);

        foreach (array_keys(ReplayData::FILES) as $file) {
            $text = file_get_contents(ReplayData::root()."/{$week}/{$file}.csv");
            $this->assertStringNotContainsString('a secret word', $text);
            $this->assertStringNotContainsString(self::REMOVED, $text);
        }
    }

    public function test_an_analysis_in_another_shape_leaves_the_analysis_columns_empty(): void
    {
        $this->replay(self::LISTED, 'listed');
        $this->analyse(self::LISTED, AnalysisFacts::VERSION + 1);

        $this->artisan('replays:data')->assertSuccessful();
        $week = gmdate('Y-m-d');

        $this->assertSame('false', $this->rows($week, 'matches')[0]['analysed']);
        $this->assertSame('', $this->rows($week, 'matches')[0]['analysis_logger_version']);
        $this->assertSame(['', '', ''], array_column($this->rows($week, 'players'), 'team_kills'));
    }

    public function test_the_page_describes_every_column_of_each_file_in_order(): void
    {
        $this->replay(self::LISTED, 'listed');
        $this->artisan('replays:data')->assertSuccessful();
        $week = gmdate('Y-m-d');

        $html = $this->get('/replays/data')->assertOk()->assertSee('<title>Replay data', false)->getContent();
        foreach (array_keys(ReplayData::FILES) as $file) {
            $this->assertSame(1, preg_match('/<table[^>]*data-columns="'.$file.'".*?<\/table>/s', $html, $table), "{$file} has a table");
            preg_match_all('/<th scope="row"[^>]*><code>([^<]+)<\/code><\/th>\s*<td[^>]*>([^<]+)<\/td>/', $table[0], $described);
            $handle = fopen(ReplayData::root()."/{$week}/{$file}.csv", 'r');
            $header = fgetcsv($handle, null, ',', '"', '');
            fclose($handle);
            $this->assertSame($header, $described[1], "{$file}.csv's columns are the ones the page describes");
            $this->assertNotContains('', array_map('trim', $described[2]));
        }
        $this->assertStringContainsString('href="/replays/data/'.$week.'/matches.csv"', $html);
        $this->assertStringContainsString('http://localhost/replays/data/latest/matches.csv', $html);
    }

    public function test_the_page_says_when_no_files_are_written_yet(): void
    {
        $this->get('/replays/data')->assertOk()
            ->assertSee('The first set has not been written yet.')
            ->assertSee('<code>game_id</code>', false)
            ->assertDontSee('/replays/data/latest/matches.csv');
    }

    public function test_files_download_by_week_and_as_latest(): void
    {
        $this->replay(self::LISTED, 'listed');
        $this->artisan('replays:data')->assertSuccessful();
        $week = gmdate('Y-m-d');

        $dated = $this->get("/replays/data/{$week}/players.csv")->assertOk();
        $this->assertStringStartsWith('text/csv', $dated->headers->get('Content-Type'));
        $this->assertStringContainsString("coilbox-replays-{$week}-players.csv", $dated->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('game_id,kind,number,name,', file_get_contents($dated->getFile()->getPathname()));

        $latest = $this->get('/replays/data/latest/matches.csv')->assertOk();
        $this->assertStringContainsString("coilbox-replays-{$week}-matches.csv", $latest->headers->get('Content-Disposition'));

        $this->get('/replays/data/latest/chat.csv')->assertNotFound();
        $this->get('/replays/data/latest/manifest.json')->assertNotFound();
        $this->get('/replays/data/2020-01-01/matches.csv')->assertNotFound();
        $this->get('/replays/data/..%2F..%2F.env/matches.csv')->assertNotFound();
    }

    public function test_it_keeps_the_newest_sets_and_replaces_one_written_the_same_day(): void
    {
        $this->replay(self::LISTED, 'listed');
        $weeks = ['2026-10-26', '2026-11-02', '2026-11-09', '2026-11-16', '2026-11-23'];
        foreach ($weeks as $week) {
            ReplayData::write($week);
        }
        $this->assertSame(array_reverse(array_slice($weeks, -ReplayData::KEEP)), array_column(ReplayData::weeks(), 'week'));

        DB::table('replay')->where('game_id', self::LISTED)->update(['state' => 'removed']);
        $this->assertSame(['matches' => 0, 'players' => 0, 'build_orders' => 0], ReplayData::write('2026-11-23'));
        $this->assertSame([], $this->rows('2026-11-23', 'matches'));
        $this->assertSame(['game_id'], array_slice(array_keys(ReplayData::columns()['matches']), 0, 1));
        $this->assertSame(0, ReplayData::weeks()[0]['files']['matches']['rows']);
        $this->assertSame(['2026-11-02', '2026-11-09', '2026-11-16', '2026-11-23'], array_map('basename', glob(ReplayData::root().'/*')));
    }

    public function test_the_scheduler_writes_the_files_every_monday(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'replays:data'));
        $this->assertCount(1, $events);
        $this->assertSame('0 4 * * 1', $events->first()->expression);
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        $this->replay(self::LISTED, 'listed');
        $this->artisan('replays:data --dry-run')->expectsOutputToContain('Would write 1 listed replays')->assertSuccessful();
        $this->assertSame([], ReplayData::weeks());
    }
}
