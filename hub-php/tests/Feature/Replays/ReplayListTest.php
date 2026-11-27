<?php

namespace Tests\Feature\Replays;

use App\Domain\Replays\ReplayAnalyses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ChecksReplays;
use Tests\Concerns\SeedsGameCatalogue;
use Tests\Feature\Moderation\ModerationTestCase;

/**
 * The replays page (#666) over two real replays put through the real check: a
 * Beyond All Reason three against three on Tangerine, played 26 November 2026
 * for 15:35, and a SplinterFaction player against an AI on Tumult, played in
 * October for 8:21.
 */
class ReplayListTest extends ModerationTestCase
{
    use ChecksReplays;
    use RefreshDatabase;
    use SeedsGameCatalogue;

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
        // Every table the pages read is local here, so a request out would be a mistake.
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function listBar(): void
    {
        $this->uploaded(self::BAR, 'bar-tangerine', self::summary('bar-tangerine'), 'Charlson');
        $this->check(self::BAR);
        $this->assertSame('listed', DB::table('replay')->where('game_id', self::BAR)->value('state'));
    }

    private function listSf(): void
    {
        $this->uploaded(self::SF, 'sf-tumult', self::summary('sf-tumult'), 'Player');
        $this->check(self::SF);
        $this->assertSame('listed', DB::table('replay')->where('game_id', self::SF)->value('state'));
    }

    private function listBoth(): void
    {
        $this->listBar();
        $this->listSf();
    }

    /** @return list<string> the game ids the page lists, in order */
    private function listed(string $query = ''): array
    {
        $html = $this->get('/replays'.$query)->assertOk()->getContent();
        preg_match_all('#<h2[^>]*><a [^>]*href="/replays/([0-9a-f]{32})"#', $html, $found);

        return $found[1];
    }

    /** The map catalogue holds Tangerine, with a minimap, and a licence that lets it be shown. */
    private function catalogueTangerine(string $licence = 'allowed'): void
    {
        $now = '2026-10-01 10:00:00.000000';
        $id = 'aaaaaaaa-0000-4000-8000-000000000001';
        DB::table('map')->insert([
            'id' => $id, 'map_name' => 'Tangerine_Remake 1.0', 'slug' => 'tangerine-remake', 'display_name' => 'Tangerine',
            'width_elmos' => 8192, 'height_elmos' => 8192, 'world_height_min' => 0.0, 'world_height_max' => 100.0,
            'appearance' => '{}', 'curated_tags' => '[]', 'source_hash' => 'h', 'source_archive' => 'tangerine',
            'catalog_version' => 1, 'facts_digest' => 'd', 'seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('asset_licence')->insert([
            'id' => 'bbbbbbbb-0000-4000-8000-000000000001', 'all_maps' => true, 'checked_at' => $now, 'checked_by' => 'test',
            'redistribute_extracted' => $licence, 'redistribute_rendered' => $licence, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('asset')->insert([
            'id' => 'cccccccc-0000-4000-8000-000000000001', 'map_name' => 'Tangerine_Remake 1.0', 'variant' => 'minimap',
            'source_hash' => 'raw', 'hash' => str_repeat('a', 64), 'encode_profile' => 'webp', 'path' => 'maps/tangerine.webp',
            'origin' => 'extracted', 'tier' => 'static', 'mime' => 'image/webp', 'bytes' => 40, 'width' => 128, 'height' => 128,
            'source_archive' => 'tangerine', 'seen_at' => $now, 'moderation' => 'approved', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function analyse(string $gameId): void
    {
        $events = file_get_contents(base_path('tests/Fixtures/replays/sf-tumult.events.jsonl'));
        $id = DB::table('replay_analysis_submission')->insertGetId([
            'game_id' => $gameId, 'user_id' => self::USER, 'logger_version' => 6, 'sha256' => hash('sha256', $events),
            'size' => strlen($events), 'events' => $events, 'state' => 'checking', 'sent_at' => '2026-11-26 10:00:00.000000',
        ]);
        $this->assertSame('kept', ReplayAnalyses::check($id));
    }

    public function test_the_page_with_no_replays_says_so_and_is_in_the_navigation(): void
    {
        $this->get('/replays')->assertOk()
            ->assertSee('Nobody has shared a replay yet.')
            ->assertDontSee('Clear the filters')
            ->assertSeeInOrder(['href="/games"', 'href="/replays"', 'aria-current="page"'], false);
        $this->get('/replays?player=nobody')->assertOk()->assertSee('No replay matches that.')->assertSee('Clear the filters');
    }

    public function test_one_replay_shows_its_map_game_sides_winner_length_and_date(): void
    {
        $this->catalogueTangerine();
        $this->listBar();

        $this->get('/replays')->assertOk()
            ->assertSee('Tangerine')
            ->assertSee('maps/tangerine.webp')
            ->assertSee('alt="Minimap of Tangerine_Remake 1.0"', false)
            ->assertSee('Beyond All Reason test-32071-e1cbffe')
            ->assertSeeInOrder(['Team 1', 'Winner', 'Charlson', 'legcom', '[Error]NoHorny', 'Cortex', '[Error]MarketMaker', 'Team 2', 'Harith23', 'Armada', 'Manathan', 'ex0rc1st'])
            ->assertSee('15:35')
            ->assertSee('datetime="2026-11-26T20:10:37Z"', false)
            ->assertSee('6 players')
            ->assertSee('href="/replays?player=Charlson"', false)
            ->assertSee('href="/replays?player=%5BError%5DNoHorny"', false)
            ->assertDontSee('Slimelamp')
            ->assertDontSee('SKALLBAG')
            ->assertDontSee('No winner')
            ->assertDontSee('>Analysis</li>', false)
            ->assertSee('Page 1 of 1, 1 replay');
        $this->assertSame(1, substr_count($this->get('/replays')->getContent(), '>Winner</span>'));
    }

    public function test_a_start_unit_with_no_faction_is_shown_by_the_catalogues_name_for_it(): void
    {
        $this->listBar();
        $this->get('/replays')->assertSee('>legcom</span>', false);

        $this->seedCatalogue();
        DB::table('game')->where('id', self::GAME)->update(['display_name' => 'Beyond All Reason']);
        DB::table('game_unit')->where('unit_name', 'armcom')->update(['unit_name' => 'legcom', 'full_name' => 'Legion Commander']);

        $this->get('/replays')->assertSee('>Legion Commander</span>', false)->assertDontSee('>legcom</span>', false);
    }

    public function test_a_player_with_no_faction_shows_a_name_alone_and_an_ai_is_marked_and_not_linked(): void
    {
        $this->listSf();
        DB::table('replay_player')->where('game_id', self::SF)->update(['faction' => null]);

        $html = $this->get('/replays')->assertOk()
            ->assertSeeInOrder(['Team 1', 'Winner', 'Player', 'Team 2', 'Enemy (AI)'])
            ->assertSee('1 player')
            ->assertDontSee('player=Enemy', false)
            ->getContent();
        $this->assertMatchesRegularExpression('#>Player</a>\s*</li>#', $html);
    }

    public function test_a_map_the_catalogue_does_not_publish_gets_a_drawing(): void
    {
        $this->catalogueTangerine('refused');
        $this->listBar();

        $this->get('/replays')->assertOk()
            ->assertSee('Tangerine_Remake 1.0')
            ->assertDontSee('maps/tangerine.webp')
            ->assertSee('<svg viewBox', false);
    }

    public function test_only_listed_replays_appear(): void
    {
        $this->listBoth();
        foreach (['uploading', 'checking', 'removed'] as $state) {
            DB::table('replay')->where('game_id', self::BAR)->update(['state' => $state]);

            $this->assertSame([self::SF], $this->listed(), $state);
            $this->get('/replays')->assertDontSee('Charlson')->assertDontSee('Tangerine_Remake');
        }
    }

    public function test_the_newest_match_comes_first(): void
    {
        $this->listSf();
        $this->listBar();

        $this->assertSame([self::BAR, self::SF], $this->listed());
    }

    public function test_the_game_filter(): void
    {
        $this->listBoth();

        $this->assertSame([self::SF], $this->listed('?game=SplinterFaction'));
        $this->assertSame([self::BAR], $this->listed('?game=Beyond+All+Reason'));
        $this->assertSame([], $this->listed('?game=Beyond'));
        $this->get('/replays?game=SplinterFaction')->assertSee('<option value="SplinterFaction" selected>', false)->assertSee('Beyond All Reason</option>', false);
    }

    public function test_the_map_filter_keeps_a_map_with_no_replay_chosen(): void
    {
        $this->listBoth();

        $this->assertSame([self::BAR], $this->listed('?map=Tangerine_Remake+1.0'));
        $this->assertSame([], $this->listed('?map=Comet+1.8'));
        $this->get('/replays?map=Comet+1.8')->assertSee('<option value="Comet 1.8" selected>', false);
    }

    public function test_the_player_filter_matches_a_whole_player_name_in_any_case(): void
    {
        $this->listBoth();

        $this->assertSame([self::BAR], $this->listed('?player=charlson'));
        $this->assertSame([self::BAR], $this->listed('?player=%5BERROR%5Dnohorny'));
        $this->assertSame([self::SF], $this->listed('?player=Player'));
        $this->assertSame([], $this->listed('?player=Charl'));
        // A spectator and an AI are not players.
        $this->assertSame([], $this->listed('?player=Slimelamp'));
        $this->assertSame([], $this->listed('?player=Enemy'));
    }

    public function test_the_player_count_filter(): void
    {
        $this->listBoth();

        $this->assertSame([self::BAR], $this->listed('?min_players=2'));
        $this->assertSame([self::SF], $this->listed('?max_players=5'));
        $this->assertSame([self::BAR], $this->listed('?min_players=6&max_players=6'));
        $this->assertSame([], $this->listed('?min_players=7'));
    }

    public function test_the_length_filter_is_in_minutes(): void
    {
        $this->listBoth();

        $this->assertSame([self::BAR], $this->listed('?min_length=9'));
        $this->assertSame([self::SF], $this->listed('?max_length=15'));
        $this->assertSame([self::BAR, self::SF], $this->listed('?min_length=8&max_length=16'));
        $this->assertSame([], $this->listed('?min_length=16'));
    }

    public function test_the_date_filter_includes_both_days(): void
    {
        $this->listBoth();

        $this->assertSame([self::BAR], $this->listed('?from=2026-11-26'));
        $this->assertSame([self::SF], $this->listed('?to=2026-11-25'));
        $this->assertSame([self::BAR], $this->listed('?from=2026-11-26&to=2026-11-26'));
        $this->assertSame([], $this->listed('?from=2026-11-27'));
    }

    public function test_the_analysis_filter_and_mark(): void
    {
        $this->listBoth();
        $this->analyse(self::SF);

        $this->assertSame([self::SF], $this->listed('?analysis=yes'));
        $this->assertSame([self::BAR], $this->listed('?analysis=no'));
        $this->assertSame(1, substr_count($this->get('/replays')->getContent(), '>Analysis</li>'));
        $this->get('/replays?analysis=no')->assertDontSee('>Analysis</li>', false);
    }

    public function test_a_value_that_makes_no_sense_is_not_applied(): void
    {
        $this->listBoth();

        $this->assertSame([self::BAR, self::SF], $this->listed('?min_players=lots&max_length=-1&from=2026-13-01&to=yesterday&analysis=maybe&page=zero&nonsense=1&player=+'));
        $this->get('/replays?analysis=maybe')->assertDontSee('Clear');
    }

    public function test_paging_keeps_the_filters(): void
    {
        $this->listBar();
        $replay = (array) DB::table('replay')->where('game_id', self::BAR)->first();
        // Copies of the checked replay, each a minute older than the one before.
        for ($i = 1; $i <= 24; $i++) {
            DB::table('replay')->insert(['game_id' => sprintf('%032x', $i), 'started_at' => gmdate('Y-m-d H:i:s', strtotime('2026-11-26 00:00:00 UTC') - 60 * $i)] + $replay);
        }

        $first = $this->listed('?min_players=2');
        $this->assertCount(24, $first);
        $this->assertSame([self::BAR, sprintf('%032x', 1)], array_slice($first, 0, 2));
        $this->get('/replays?min_players=2')->assertSee('Page 1 of 2, 25 replays')->assertSee('href="/replays?min_players=2&amp;page=2"', false);

        $this->assertSame([sprintf('%032x', 24)], $this->listed('?min_players=2&page=2'));
        $this->get('/replays?min_players=2&page=2')->assertSee('Page 2 of 2')->assertSee('href="/replays?min_players=2"', false);

        $this->get('/replays?min_players=2&page=3')->assertOk()->assertSee('That page is past the last replay.');
    }

    public function test_a_game_page_links_to_its_replays_when_it_has_some(): void
    {
        $this->seedCatalogue();
        $this->get('/games/BA')->assertOk()->assertDontSee('/replays?game=', false);

        $this->listBar();
        DB::table('replay')->where('game_id', self::BAR)->update(['game' => 'Balanced Annihilation']);

        $this->get('/games/BA')->assertOk()
            ->assertSee('href="/replays?game=Balanced+Annihilation"', false)
            ->assertSee('1 shared match');
    }

    public function test_a_map_page_links_to_its_replays_when_it_has_some(): void
    {
        $this->catalogueTangerine();
        $this->get('/map/tangerine-remake')->assertOk()->assertDontSee('/replays?map=', false);

        $this->listBar();

        $this->get('/map/tangerine-remake')->assertOk()
            ->assertSee('href="/replays?map=Tangerine_Remake+1.0"', false)
            ->assertSee('See the 1 shared match on this map');
    }
}
