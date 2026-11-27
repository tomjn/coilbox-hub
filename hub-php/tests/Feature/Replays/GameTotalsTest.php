<?php

namespace Tests\Feature\Replays;

use App\Domain\Replays\GameTotals;
use App\Domain\Replays\ReplayAnalyses;
use App\Domain\Replays\ReplayListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ChecksReplays;
use Tests\Feature\Moderation\ModerationTestCase;

/**
 * The totals on each game's page (#670), from the real SplinterFaction replay
 * and its analysis, and the real Beyond All Reason replay, put through the real
 * checks. Further matches are the first's rows under other game ids, listed the
 * real way.
 */
class GameTotalsTest extends ModerationTestCase
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
    }

    /** The SF fixture's summary has no unit names or costs, so these stand in for them. */
    private function sfSummary(): array
    {
        $summary = self::summary('sf-tumult');
        foreach ($summary['units'] as &$unit) {
            $unit['display_name'] = 'Unit number '.$unit['unit_def_id'];
            $unit['metal'] = 100;
        }
        // The attacker in the fixture's one kill of a start unit, which no order names.
        $summary['units'][] = ['unit_def_id' => 20, 'name' => 'unit20', 'display_name' => 'Start unit', 'metal' => 1000, 'energy' => 0, 'build_time' => null, 'category' => null];

        return $summary;
    }

    private function listed(): void
    {
        $this->uploaded(self::SF, 'sf-tumult', $this->sfSummary(), 'Player');
        $this->check(self::SF);
        $this->assertSame('listed', DB::table('replay')->where('game_id', self::SF)->value('state'));
    }

    /**
     * Another match of the game, with its own players: [faction, ally team, won, kind].
     *
     * @param  list<array{0: ?string, 1: int, 2: bool, 3?: string}>  $seats
     * @param  array<string, mixed>  $replay
     */
    private function another(string $gameId, array $seats, array $replay = []): void
    {
        $first = (array) DB::table('replay')->where('game_id', self::SF)->first();
        $winners = array_values(array_unique(array_column(array_filter($seats, fn ($s) => $s[2]), 1)));
        DB::table('replay')->insert($replay + ['game_id' => $gameId, 'state' => 'checking', 'sha256' => hash('sha256', $gameId), 'duration' => 100, 'winners' => json_encode($winners)] + $first);
        $team = (array) DB::table('replay_team')->where('game_id', self::SF)->orderBy('team')->first();
        foreach ($seats as $number => [$faction, $ally, $won]) {
            DB::table('replay_team')->insert(['game_id' => $gameId, 'team' => $number, 'ally_team' => $ally, 'won' => $won] + $team);
            DB::table('replay_player')->insert(['game_id' => $gameId, 'kind' => $seats[$number][3] ?? 'player', 'number' => $number, 'name' => 'P'.$number,
                'team' => $number, 'ally_team' => $ally, 'faction' => $faction, 'won' => $won]);
        }
        $this->assertTrue(ReplayListing::publish($gameId));
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

    private function snapshot(): array
    {
        return DB::table('replay_game_total')->orderBy('game')->orderBy('game_version')->get()->map(function ($row) {
            $figures = json_decode($row->figures, true);
            self::sort($figures);

            return ['figures' => $figures] + (array) $row;
        })->all();
    }

    private static function sort(array &$value): void
    {
        ksort($value);
        foreach ($value as &$child) {
            if (is_array($child)) {
                self::sort($child);
            }
        }
    }

    private function page(?string $version = null): ?array
    {
        return GameTotals::page((string) DB::table('replay')->where('game_id', self::SF)->value('game'), $version);
    }

    public function test_a_replay_that_passes_its_check_is_added(): void
    {
        $this->listed();

        $orders = json_decode(DB::table('replay_team')->where('game_id', self::SF)->where('team', 0)->value('build_orders'), true);
        $page = $this->page();
        $this->assertSame(1, $page['matches']);
        $duration = (int) DB::table('replay')->value('duration');
        $this->assertSame([$duration, $duration, $duration], [$page['shortest'], $page['median'], $page['longest']]);
        // The fixture's one player made no faction pick.
        $this->assertSame([], $page['factions']);
        $this->assertSame(['players' => 1, 'replays' => 1], $page['unknown']);
        $this->assertSame(1, $page['orders_from']);
        $this->assertSame(
            max(array_count_values(array_column($orders, 'unit_def_id'))),
            $page['orders'][0]['amount'],
        );
        $this->assertStringStartsWith('Unit number ', $page['orders'][0]['name']);
        $this->assertSame(1, $page['openings_of']);
        $this->assertSame(
            [['name' => 'Unit number 48', 'count' => 1], ['name' => 'Unit number 60', 'count' => 2], ['name' => 'Unit number 137', 'count' => 1]],
            array_slice($page['openings'][0]['entries'], 0, 3),
        );
        $this->assertCount(GameTotals::OPENING_ENTRIES, $page['openings'][0]['entries']);
        $this->assertSame(0, $page['analysed']);
        $this->assertSame([], $page['finished']);
        $this->assertSame([], $page['pairs']);
        $this->assertSame([], $page['awards']);
    }

    public function test_factions_pairings_and_team_sizes_add_up_across_replays_and_versions(): void
    {
        $this->listed();
        $this->another(str_repeat('a', 32), [['Fed', 0, true], ['Loz', 1, false]]);
        $this->another(str_repeat('b', 32), [['Loz', 0, true], ['Fed', 1, false]], ['duration' => 300]);
        // Nobody won, a mirror match, a team match with a player of no known faction, and a match against an AI.
        $this->another(str_repeat('c', 32), [['Fed', 0, false], ['Loz', 1, false]], ['game_version' => '9.9']);
        $this->another(str_repeat('d', 32), [['Fed', 0, true], ['Fed', 1, false]], ['game_version' => '9.9']);
        $this->another(str_repeat('e', 32), [['Fed', 0, true], ['Loz', 0, true], [null, 1, false], ['Loz', 1, false]]);
        $this->another(str_repeat('9', 32), [['Fed', 0, false], ['Loz', 1, true, 'ai']]);

        $page = $this->page();
        $this->assertSame(7, $page['matches']);
        $this->assertSame(
            ['Fed' => [6, 3, 1, 6], 'Loz' => [4, 2, 1, 4]],
            array_column(array_map(fn ($f) => [$f['name'], [$f['decided'], $f['won'], $f['undecided'], $f['replays']]], $page['factions']), 1, 0),
        );
        $this->assertSame(7, $page['factions_from']);
        // The first replay's player and one in the team match.
        $this->assertSame(['players' => 2, 'replays' => 2], $page['unknown']);
        $this->assertSame([
            ['first' => 'Fed', 'second' => 'Loz', 'mirror' => false, 'matches' => 3, 'first_won' => 1, 'second_won' => 1, 'undecided' => 1],
            ['first' => 'Fed', 'second' => 'Fed', 'mirror' => true, 'matches' => 1, 'first_won' => 0, 'second_won' => 0, 'undecided' => 0],
        ], $page['pairs']);
        $this->assertSame(4, $page['pairs_from']);
        $this->assertSame(['1 v 1' => 6, '2 v 2' => 1], array_column($page['sizes'], 'replays', 'size'));

        $newest = $this->page('9.9');
        $this->assertSame(['9.9', 2, 7], [$newest['version'], $newest['matches'], $newest['all']]);
        $this->assertSame(['9.9', DB::table('replay')->where('game_id', self::SF)->value('game_version')], array_map(strval(...), array_keys($newest['versions'])));
        $this->assertSame(1, $newest['pairs'][0]['matches']);
        // A version nobody shared shows every version.
        $this->assertSame([null, 7], [$this->page('0.0')['version'], $this->page('0.0')['matches']]);
    }

    public function test_a_random_side_is_no_known_faction(): void
    {
        $this->listed();
        $this->another(self::SECOND, [['Random', 0, true], ['Loz', 1, false]]);

        $page = $this->page();
        $this->assertSame(['Loz'], array_column($page['factions'], 'name'));
        $this->assertSame(['players' => 2, 'replays' => 2], $page['unknown']);
        $this->assertSame([], $page['pairs']);
    }

    public function test_removing_replays_takes_their_shares_back_out(): void
    {
        $this->listed();
        $one = $this->snapshot();
        $this->another(self::SECOND, [['Fed', 0, true], ['Loz', 1, false]]);
        $this->assertSame(2, $this->page()['matches']);

        $this->assertTrue(ReplayListing::remove(self::SECOND, 'rights'));
        $this->assertSame($one, $this->snapshot());
        $this->assertSame([self::SF], DB::table('replay_game_share')->pluck('game_id')->all());

        $this->assertTrue(ReplayListing::remove(self::SF, 'rights'));
        $this->assertSame([], $this->snapshot());
        $this->assertSame(0, DB::table('replay_game_share')->count());
    }

    public function test_counting_a_replay_again_adds_nothing(): void
    {
        $this->listed();
        $once = $this->snapshot();

        GameTotals::refresh(self::SF);
        GameTotals::refresh(self::SF);

        $this->assertSame($once, $this->snapshot());
    }

    public function test_an_analysis_adds_finished_units_and_kills_by_value_once(): void
    {
        $this->listed();
        $this->another(self::SECOND, [['Fed', 0, true], ['Loz', 1, false]]);
        $without = $this->snapshot();
        $this->assertSame('kept', $this->analyse());

        $page = $this->page();
        $this->assertSame(1, $page['analysed']);
        $this->assertNotSame([], $page['finished']);
        $lines = array_map(fn ($l) => json_decode($l, true), file(base_path('tests/Fixtures/replays/sf-tumult.events.jsonl'), FILE_IGNORE_NEW_LINES));
        $built = array_filter($lines, fn ($l) => $l['kind'] === 'unit_finished' && $l['team'] === 0 && ! ($l['startUnit'] ?? false));
        $this->assertSame(count($built), array_sum(array_column($page['finished'], 'amount')) + $page['unnamed_finished']);
        $kept = $this->snapshot();

        // A run from a newer logger replaces the kept one, with the same match's figures.
        DB::table('replay_analysis')->update(['logger_version' => 5]);
        $this->assertSame('kept', $this->analyse());
        ReplayAnalyses::writeFacts(self::SF);
        $this->assertSame($kept, $this->snapshot());

        ReplayListing::remove(self::SF, 'rights');
        ReplayListing::remove(self::SECOND, 'rights');
        $this->assertSame([], $this->snapshot());
        $this->assertNotSame($without, $kept);
    }

    public function test_a_units_kills_are_the_value_of_the_enemy_units_it_destroyed(): void
    {
        $this->listed();
        $this->analyse();
        // One finished enemy unit, def 20 on team 1, destroyed by a unit of def 59 on the player's team.
        $this->assertSame([['name' => 'Unit number 59', 'amount' => 1000, 'replays' => 1]], $this->page()['killers']);

        // A summary that does not name the attacker leaves its kills apart.
        $summary = $this->sfSummary();
        $summary['units'] = array_values(array_filter($summary['units'], fn ($u) => $u['unit_def_id'] !== 59));
        DB::table('replay')->update(['summary' => json_encode($summary)]);
        ReplayAnalyses::writeFacts(self::SF);

        $this->assertSame([], $this->page()['killers']);
        $this->assertSame(1000, $this->page()['unnamed_kills']);
    }

    public function test_beyond_all_reason_awards_are_counted_by_the_winners_faction(): void
    {
        $this->uploaded(self::BAR, 'bar-tangerine', self::summary('bar-tangerine'), 'Charlson');
        $this->check(self::BAR);
        $replay = DB::table('replay')->where('game_id', self::BAR)->first();
        $this->assertSame('listed', $replay->state);

        $page = GameTotals::page($replay->game);
        $this->assertSame(1, $page['awards_from']);
        // Nobody won the economy award, so it is left out. Team 5 came first for fighting units, and its
        // player picked a start unit the empty catalogue gives no faction.
        $this->assertSame(
            ['title' => 'Most enemy fighting units destroyed', 'replays' => 1, 'winners' => [['faction' => 'corcom', 'times' => 1]]],
            $page['awards'][0],
        );
        $this->assertNotContains('Most enemy economy destroyed', array_column($page['awards'], 'title'));
        $this->assertSame(['3 v 3' => 1], array_column($page['sizes'], 'replays', 'size'));
        $this->assertSame(6, array_sum(array_column($page['factions'], 'decided')) + array_sum(array_column($page['factions'], 'undecided')) + $page['unknown']['players']);

        ReplayListing::remove(self::BAR, 'rights');
        $this->assertNull(GameTotals::page($replay->game));
    }

    public function test_the_command_counts_replays_listed_before_the_totals_and_drops_removed_ones(): void
    {
        $this->listed();
        $this->analyse();
        $counted = $this->snapshot();

        // Facts kept before they held units, and no totals yet.
        $facts = json_decode(DB::table('replay_analysis')->value('facts'), true);
        unset($facts['by_unit']);
        DB::table('replay_analysis')->update(['facts' => json_encode($facts)]);
        DB::table('replay_game_share')->delete();
        DB::table('replay_game_total')->delete();

        $this->artisan('replays:game-totals --dry-run')->expectsOutput(self::SF)->expectsOutput('Would count 1 replay.')->assertOk();
        $this->assertSame([], $this->snapshot());

        $this->artisan('replays:game-totals')->expectsOutput('Counted 1 replay.')->assertOk();
        $this->assertSame($counted, $this->snapshot());
        $this->artisan('replays:game-totals')->expectsOutput('Counted 0 replays.')->assertOk();

        // A replay that stopped being listed without being taken out.
        DB::table('replay')->update(['state' => 'removed']);
        $this->artisan('replays:game-totals')->expectsOutput('Counted 1 replay.')->assertOk();
        $this->assertSame([], $this->snapshot());
    }

    private function game(string $name): void
    {
        $now = '2026-10-01 10:00:00.000000';
        DB::table('game')->insert([
            'id' => 'cccccccc-0000-4000-8000-000000000001', 'shortname' => 'sf', 'display_name' => $name, 'links' => '[]',
            'conquest_factions' => '[]', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function test_the_game_page_shows_the_figures_with_the_replays_behind_each(): void
    {
        $this->listed();
        $this->another(self::SECOND, [['Fed', 0, true], ['Loz', 1, false]], ['game_version' => '9.9']);
        $this->analyse();
        $this->game(DB::table('replay')->where('game_id', self::SF)->value('game'));

        $this->get('/games/sf')->assertOk()
            ->assertSee('From shared replays')
            ->assertSee('Match lengths from 2 replays.')
            ->assertSee('All versions (2)')
            ->assertSee('100% (1 of 1)')
            ->assertSee('1 player in 1 replay had no known faction and is left out.')
            ->assertSee('Fed against Loz')
            ->assertSee('Units ordered most')
            ->assertSee('Common openings')
            ->assertSee('From the analyses of 1 replay.')
            ->assertDontSee('Awards by faction');
        $this->get('/games/sf?version=9.9')->assertOk()
            ->assertSee('Match lengths from 1 replay.')
            ->assertDontSee('From the analyses of');
    }

    public function test_a_game_with_no_replays_shows_none_of_it(): void
    {
        $this->game('SplinterFaction');
        $this->get('/games/sf')->assertOk()->assertDontSee('From shared replays');

        $this->listed();
        ReplayListing::remove(self::SF, 'rights');

        $this->get('/games/sf')->assertOk()->assertDontSee('From shared replays');
    }
}
