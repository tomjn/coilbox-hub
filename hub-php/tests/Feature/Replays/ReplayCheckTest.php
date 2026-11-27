<?php

namespace Tests\Feature\Replays;

use App\Domain\Replays\BuildOrders;
use App\Domain\Replays\ReplayChecks;
use App\Domain\Replays\ReplayReader;
use App\Jobs\CheckReplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\ChecksReplays;
use Tests\Feature\Moderation\ModerationTestCase;

/**
 * The replay check (#663) on real replays. Each `<fixture>.summary.json` is
 * what Coilbox's own reader gives for the replay beside it: map, game, engine,
 * roster, winners, totals, start positions and build orders. Coilbox gets unit
 * names and costs from the game's archive, which these tests do not have, so
 * the Beyond All Reason names are the replay's own unit list and the others
 * are made up, and every cost is 0. The check reads no costs.
 */
class ReplayCheckTest extends ModerationTestCase
{
    use ChecksReplays;
    use RefreshDatabase;

    private const OTHER_USER = '22222222-2222-2222-2222-222222222222';

    private const MF = '4c6cd26ab1dba4f017aa9248910cc3e0';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.games_store' => 'mariadb',
            'hub.maps_store' => 'mariadb',
            'hub.pictures_store' => 'mariadb',
            'hub.mirrors_store' => 'mariadb',
            'hub.mirrors.secret_key' => self::fixtureKeys()['hub_secret_key'],
            'hub.mirrors.next_secret_key' => '',
            'app.url' => 'https://hub.test',
        ]);
        $this->replayMirror();
        Queue::fake();
    }

    private function assertRefused(string $gameId, string $reason, string $detail): void
    {
        $this->assertDatabaseMissing('replay', ['game_id' => $gameId]);
        $refusal = DB::table('replay_refusal')->where('game_id', $gameId)->first();
        $this->assertSame($reason, $refusal->reason);
        $this->assertStringContainsString($detail, $refusal->detail);
        $removed = DB::table('replay_removal')->where('game_id', $gameId)->first();
        $this->assertSame('refused', $removed->reason);
        $this->assertSame($refusal->sha256, $removed->sha256);
    }

    public function test_the_build_orders_match_what_coilbox_reads(): void
    {
        foreach (['bar-tangerine', 'sf-tumult', 'mf-valles'] as $fixture) {
            $want = [];
            foreach (self::summary($fixture)['build_orders'] as $entry) {
                $want[$entry['team']] = $entry['orders'];
            }
            $replay = (new ReplayReader)->read(base_path('tests/Fixtures/replays/'.$fixture.'.sdfz'));

            $this->assertSame($want, BuildOrders::of($replay), $fixture);
        }
    }

    public function test_a_matching_summary_is_stored_and_listed(): void
    {
        $this->uploaded(self::BAR, 'bar-tangerine', self::summary('bar-tangerine'), 'Charlson');

        $this->check(self::BAR);

        $replay = DB::table('replay')->where('game_id', self::BAR)->first();
        $this->assertSame('listed', $replay->state);
        $this->assertSame(['Beyond All Reason', 'test-32071-e1cbffe', 'Tangerine_Remake 1.0', '2026.09.03'], [$replay->game, $replay->game_version, $replay->map, $replay->engine]);
        $this->assertSame('2026-11-26 20:10:37', substr((string) $replay->started_at, 0, 19));
        $this->assertSame([935, 6, [0]], [(int) $replay->duration, (int) $replay->player_count, json_decode($replay->winners)]);
        $this->assertCount(13, json_decode($replay->extra, true)['bar.awards']);
        $this->assertContains('game_over', array_column(json_decode($replay->events, true), 'type'));

        $teams = DB::table('replay_team')->where('game_id', self::BAR)->orderBy('team')->get();
        $this->assertSame([false, false, false, true, true, true], $teams->map(fn ($t) => (bool) $t->won)->all());
        $this->assertSame(129507.2578125, (float) $teams[0]->metal_used);
        $this->assertSame(123, (int) $teams[0]->units_produced);
        $this->assertSame([pack('g', 7182.08837890625), pack('g', 6871.1025390625)], [pack('g', $teams[0]->start_x), pack('g', $teams[0]->start_z)]);
        $this->assertSame(self::summary('bar-tangerine')['build_orders'][0]['orders'], json_decode($teams[0]->build_orders, true));

        $players = DB::table('replay_player')->where('game_id', self::BAR)->orderBy('kind')->orderBy('number')->get();
        $this->assertSame(['player' => 6, 'spectator' => 2], $players->countBy('kind')->all());
        $charlson = $players->firstWhere('name', 'Charlson');
        // The start script says Cortex. Charlson picked Legion's commander.
        $this->assertSame([3, 0, 'legcom', true], [(int) $charlson->team, (int) $charlson->ally_team, $charlson->faction, (bool) $charlson->won]);
        $this->assertNotNull($charlson->commands);
        $this->assertNull($players->firstWhere('name', 'Slimelamp')->won);

        $chat = DB::table('replay_chat')->where('game_id', self::BAR)->get();
        $this->assertSame(['allies' => 8, 'everyone' => 38], $chat->countBy('audience')->sortKeys()->all());

        $this->assertSame([self::BAR.'.sdfz'], array_column($this->get('/api/v1/catalogue')->json('replays'), 'filename'));
        $this->assertDatabaseCount('replay_refusal', 0);
    }

    public function test_an_ai_team_is_stored_with_its_ai(): void
    {
        $this->uploaded(self::SF, 'sf-tumult', self::summary('sf-tumult'), 'Player');

        $this->check(self::SF);

        $this->assertSame('listed', DB::table('replay')->where('game_id', self::SF)->value('state'));
        $this->assertSame(['SplinterFaction', '0.1.95'], array_values((array) DB::table('replay')->where('game_id', self::SF)->first(['game', 'game_version'])));
        $ai = DB::table('replay_player')->where('game_id', self::SF)->where('kind', 'ai')->first();
        $this->assertSame(['Enemy', 1, false], [$ai->name, (int) $ai->team, (bool) $ai->won]);
        $this->assertSame(2, DB::table('replay_team')->where('game_id', self::SF)->count());
        $this->assertSame([35, 35], DB::table('replay_team')->where('game_id', self::SF)->orderBy('team')->pluck('samples')->map(fn ($s) => (int) $s)->all());
    }

    public function test_one_changed_winner_is_refused_and_the_game_id_can_be_sent_again(): void
    {
        $summary = self::summary('bar-tangerine');
        $summary['winners'] = [1];
        $this->uploaded(self::BAR, 'bar-tangerine', $summary, 'Charlson');

        $this->check(self::BAR);

        $this->assertRefused(self::BAR, 'summary_mismatch', 'The summary says ally teams [1] won and the file says [0].');
        $this->assertDatabaseCount('replay_sender', 0);
        $this->assertDatabaseCount('replay_team', 0);
        $sha = hash('sha256', self::file('bar-tangerine'));
        $this->assertSame([$sha], array_column($this->get('/api/v1/catalogue')->json('removed'), 'sha256'));

        $this->supabase(['/auth/v1/user' => fn () => self::reply(['id' => self::OTHER_USER])]);
        $again = $this->call('POST', '/api/v1/replays/uploads', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer bob', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['game_id' => self::BAR, 'sha256' => $sha, 'size' => strlen(self::file('bar-tangerine')), 'name' => 'Charlson', 'summary' => self::summary('bar-tangerine')], JSON_PRESERVE_ZERO_FRACTION));
        $again->assertStatus(201)->assertJsonPath('upload.state', 'uploading');
    }

    public function test_a_remix_is_refused_with_a_reason_and_the_original_can_be_sent(): void
    {
        // The match pointed at another build, with the marker Coilbox puts first in [game].
        $b = gzdecode(self::file('bar-tangerine'));
        $size = unpack('V', $b, 304)[1];
        $marker = "[coilbox]\n{\nremix=1;\nsource=Beyond All Reason test-32071-e1cbffe;\norigin=match.sdfz;\n}\n";
        $text = strtr(substr($b, 352, $size), ['gametype=Beyond All Reason test-32071-e1cbffe;' => 'gametype=Beyond All Reason test-99999-0000000;']);
        $text = substr_replace($text, "\n".$marker, strpos($text, '{') + 1, 0);
        $remix = gzencode(substr_replace(substr($b, 0, 352), pack('V', strlen($text)), 304, 4).$text.substr($b, 352 + $size));
        // Everything in the summary agrees with the remixed file.
        $summary = self::summary('bar-tangerine');
        $summary['game'] = 'Beyond All Reason test-99999-0000000';
        $this->uploaded(self::BAR, 'bar-tangerine', $summary, 'Charlson', bytes: $remix);

        $this->check(self::BAR);

        $this->assertRefused(self::BAR, 'remix', 'Beyond All Reason test-32071-e1cbffe');
        $this->supabase(['/auth/v1/user' => fn () => self::reply(['id' => self::USER])]);
        $this->call('GET', '/api/v1/replays/'.self::BAR.'/uploads/status', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer sender'])
            ->assertOk()->assertJsonPath('replay.state', 'refused')->assertJsonPath('replay.refusal.reason', 'remix')
            ->assertJsonPath('replay.refusal.message', 'The file sent was a remix, a copy of a replay changed to run on another game. The hub only takes the original replay.');
        $original = self::file('bar-tangerine');
        $this->call('POST', '/api/v1/replays/uploads', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer sender', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['game_id' => self::BAR, 'sha256' => hash('sha256', $original), 'size' => strlen($original), 'name' => 'Charlson', 'summary' => self::summary('bar-tangerine')], JSON_PRESERVE_ZERO_FRACTION))
            ->assertStatus(201)->assertJsonPath('upload.state', 'uploading');
    }

    public function test_one_changed_total_is_refused(): void
    {
        $summary = self::summary('bar-tangerine');
        $summary['totals'][2]['damage_dealt'] += 1;
        $this->uploaded(self::BAR, 'bar-tangerine', $summary, 'Charlson');

        $this->check(self::BAR);

        $this->assertRefused(self::BAR, 'summary_mismatch', "The summary's damage_dealt for team 2");
    }

    public function test_one_changed_build_order_is_refused(): void
    {
        $summary = self::summary('bar-tangerine');
        $summary['build_orders'][1]['orders'][5]['unit_def_id'] = $summary['build_orders'][1]['orders'][5]['unit_def_id'] === 61 ? 635 : 61;
        $summary['units'][] = ['unit_def_id' => 61, 'name' => 'armcom', 'display_name' => null, 'metal' => 0, 'energy' => 0, 'build_time' => null, 'category' => null];
        $summary['units'][] = ['unit_def_id' => 635, 'name' => 'corcom', 'display_name' => null, 'metal' => 0, 'energy' => 0, 'build_time' => null, 'category' => null];
        $summary['units'] = array_values(array_column($summary['units'], null, 'unit_def_id'));
        $this->uploaded(self::BAR, 'bar-tangerine', $summary, 'Charlson');

        $this->check(self::BAR);

        $this->assertRefused(self::BAR, 'summary_mismatch', "The summary's build orders for team 1 differ from the file's at order 5");
    }

    public function test_a_missing_build_order_is_refused(): void
    {
        $summary = self::summary('sf-tumult');
        array_pop($summary['build_orders'][0]['orders']);
        $this->uploaded(self::SF, 'sf-tumult', $summary, 'Player');

        $this->check(self::SF);

        $this->assertRefused(self::SF, 'summary_mismatch', 'the summary has 34 orders and the file 35');
    }

    /**
     * Beyond All Reason in the catalogue, with its three factions and their commanders.
     *
     * @param  list<string>  $startUnits
     */
    private function catalogueFactions(array $startUnits = ['armcom', 'corcom', 'legcom']): void
    {
        $now = '2026-08-21 10:00:00.000000';
        $id = 'bbbbbbbb-0000-4000-8000-000000000001';
        DB::table('game')->insert(['id' => $id, 'shortname' => 'BYAR', 'display_name' => 'Beyond All Reason', 'start_units' => json_encode($startUnits),
            'links' => '[]', 'conquest_factions' => '[]', 'created_at' => $now, 'updated_at' => $now]);
        foreach (['armada' => ['Armada', 'armcom'], 'cortex' => ['Cortex', 'corcom'], 'legion' => ['Legion', 'legcom']] as $key => [$name, $unit]) {
            DB::table('game_faction')->insert(['game_id' => $id, 'key' => $key, 'name' => $name]);
            DB::table('game_unit')->insert(['game_id' => $id, 'unit_name' => $unit, 'faction_key' => $key,
                'build_options' => '[]', 'stats' => '{}', 'facts_digest' => $unit, 'morph_targets' => '[]',
                'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /** @return array<string, mixed> the BAR summary with each player's faction as the catalogue names their pick */
    private static function playedSummary(): array
    {
        $summary = self::summary('bar-tangerine');
        $played = ['Manathan' => 'Legion', 'ex0rc1st' => 'Cortex', 'Charlson' => 'Legion', '[Error]MarketMaker' => 'Cortex'];
        foreach ($summary['players'] as $i => $player) {
            $summary['players'][$i]['faction'] = $played[$player['name']] ?? $player['faction'];
        }

        return $summary;
    }

    /** @return list<string> the BAR players' stored factions, by player number */
    private function storedFactions(): array
    {
        return DB::table('replay_player')->where('game_id', self::BAR)->where('kind', 'player')->orderBy('number')->pluck('faction')->all();
    }

    public function test_a_pick_is_stored_as_the_catalogues_faction(): void
    {
        $this->catalogueFactions();
        $this->uploaded(self::BAR, 'bar-tangerine', self::playedSummary(), 'Charlson');

        $this->check(self::BAR);

        $this->assertSame('listed', DB::table('replay')->where('game_id', self::BAR)->value('state'));
        $this->assertSame(['Armada', 'Legion', 'Cortex', 'Legion', 'Cortex', 'Cortex'], $this->storedFactions());
    }

    public function test_the_start_scripts_side_for_a_player_who_picked_another_faction_is_refused(): void
    {
        $this->catalogueFactions();
        $this->uploaded(self::BAR, 'bar-tangerine', self::summary('bar-tangerine'), 'Charlson');

        $this->check(self::BAR);

        $this->assertRefused(self::BAR, 'summary_mismatch', "The summary's players are not the file's");
    }

    public function test_a_pick_the_catalogue_cannot_name_is_stored_as_the_start_unit_and_not_checked(): void
    {
        $summary = self::summary('bar-tangerine');
        $summary['players'][3]['faction'] = 'Legion';
        $this->uploaded(self::BAR, 'bar-tangerine', $summary, 'Charlson');

        $this->check(self::BAR);

        $this->assertSame('listed', DB::table('replay')->where('game_id', self::BAR)->value('state'));
        $this->assertSame(['Armada', 'legcom', 'corcom', 'legcom', 'Cortex', 'corcom'], $this->storedFactions());
    }

    public function test_a_unit_the_catalogue_does_not_start_a_player_with_names_no_faction(): void
    {
        $this->catalogueFactions(['armcom', 'corcom']);
        $this->uploaded(self::BAR, 'bar-tangerine', self::playedSummary(), 'Charlson');

        $this->check(self::BAR);

        $this->assertSame(['Armada', 'legcom', 'Cortex', 'legcom', 'Cortex', 'Cortex'], $this->storedFactions());
    }

    public function test_a_hidden_game_names_no_faction(): void
    {
        $this->catalogueFactions();
        DB::table('game')->update(['hidden_at' => '2026-08-22 10:00:00.000000']);
        $this->uploaded(self::BAR, 'bar-tangerine', self::summary('bar-tangerine'), 'Charlson');

        $this->check(self::BAR);

        $this->assertSame(['Armada', 'legcom', 'corcom', 'legcom', 'Cortex', 'corcom'], $this->storedFactions());
    }

    public function test_a_changed_team_is_refused_for_a_player_whose_faction_is_not_checked(): void
    {
        $summary = self::summary('bar-tangerine');
        $summary['players'][3]['team'] = 7;
        $this->uploaded(self::BAR, 'bar-tangerine', $summary, 'Charlson');

        $this->check(self::BAR);

        $this->assertRefused(self::BAR, 'summary_mismatch', "The summary's players are not the file's");
    }

    public function test_a_changed_roster_or_start_position_is_refused(): void
    {
        // Harith23 picked nothing, so the start script's side is the faction.
        $summary = self::summary('bar-tangerine');
        $summary['players'][4]['faction'] = 'Legion';
        $this->uploaded(self::BAR, 'bar-tangerine', $summary, 'Charlson');
        $this->check(self::BAR);
        $this->assertRefused(self::BAR, 'summary_mismatch', "The summary's players are not the file's");

        $summary = self::summary('sf-tumult');
        $summary['start_positions'][1]['x'] += 0.5;
        $this->uploaded(self::SF, 'sf-tumult', $summary, 'Player');
        $this->check(self::SF);
        $this->assertRefused(self::SF, 'summary_mismatch', "The summary's start position for team 1");
    }

    /** @return array<string, mixed> the Splinter Faction summary naming the start unit its player picked */
    private static function pickedSummary(?string $faction): array
    {
        $summary = self::summary('sf-tumult');
        $summary['units'][] = ['unit_def_id' => 19, 'name' => 'fedcommander', 'display_name' => null, 'metal' => 0, 'energy' => 0, 'build_time' => null, 'category' => null];
        $summary['players'][0]['faction'] = $faction;

        return $summary;
    }

    /** Splinter Faction in the catalogue, with its commander in a faction or in none. */
    private function catalogueSplinterFaction(?string $factionKey): void
    {
        $now = '2026-08-21 10:00:00.000000';
        $id = 'aaaaaaaa-0000-4000-8000-000000000001';
        DB::table('game')->insert(['id' => $id, 'shortname' => 'SF', 'display_name' => 'SplinterFaction', 'start_units' => json_encode(['fedcommander']),
            'links' => '[]', 'conquest_factions' => '[]', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('game_faction')->insert(['game_id' => $id, 'key' => 'fed', 'name' => 'Federation of Kala']);
        DB::table('game_unit')->insert(['game_id' => $id, 'unit_name' => 'fedcommander', 'faction_key' => $factionKey,
            'build_options' => '[]', 'stats' => '{}', 'facts_digest' => 'fed', 'morph_targets' => '[]',
            'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function test_a_start_unit_the_summary_names_is_stored_as_the_catalogues_faction(): void
    {
        $this->catalogueSplinterFaction('fed');
        $this->uploaded(self::SF, 'sf-tumult', self::pickedSummary('Federation of Kala'), 'Player');

        $this->check(self::SF);

        $this->assertSame('listed', DB::table('replay')->where('game_id', self::SF)->value('state'));
        $this->assertSame('Federation of Kala', DB::table('replay_player')->where('game_id', self::SF)->where('kind', 'player')->value('faction'));
    }

    public function test_the_start_scripts_side_for_a_splinter_faction_pick_is_refused(): void
    {
        $this->catalogueSplinterFaction('fed');
        $this->uploaded(self::SF, 'sf-tumult', self::pickedSummary(null), 'Player');

        $this->check(self::SF);

        $this->assertRefused(self::SF, 'summary_mismatch', "The summary's players are not the file's");
    }

    public function test_a_start_unit_with_no_catalogue_faction_is_stored_by_the_summarys_name(): void
    {
        $this->catalogueSplinterFaction(null);
        $this->uploaded(self::SF, 'sf-tumult', self::pickedSummary(null), 'Player');

        $this->check(self::SF);

        $this->assertSame('listed', DB::table('replay')->where('game_id', self::SF)->value('state'));
        $this->assertSame('fedcommander', DB::table('replay_player')->where('game_id', self::SF)->where('kind', 'player')->value('faction'));
    }

    /**
     * The Splinter Faction file with a second player, Other, who never picks,
     * on the AI's team, whose lobby side is `Fed`, and these mod options.
     */
    private static function otherOnTeamOne(string $modOptions = ''): string
    {
        $b = gzdecode(self::file('sf-tumult'));
        $size = unpack('V', $b, 304)[1];
        $text = strtr(substr($b, 352, $size), [
            '[allyteam0]' => "[player1]\n{\nname=Other;\nteam=1;\nspectator=0;\n}\n[allyteam0]",
            "[team1]\n{\n" => "[team1]\n{\nside=Fed;\n",
            'allowuserwidgets=1;' => 'allowuserwidgets=1;'.$modOptions,
        ]);

        return gzencode(substr_replace(substr($b, 0, 352), pack('V', strlen($text)), 304, 4).$text.substr($b, 352 + $size));
    }

    /** @return array<string, mixed> the Splinter Faction summary with Other, as `otherOnTeamOne` adds them */
    private static function otherSummary(?string $faction): array
    {
        $summary = self::pickedSummary('fedcommander');
        $summary['players'][] = ['name' => 'Other', 'team' => 1, 'ally_team' => 1, 'faction' => $faction];

        return $summary;
    }

    public function test_a_splinter_faction_player_with_no_pick_is_stored_with_no_faction_and_not_checked(): void
    {
        // The lobby's side, another faction, or none: the summary may name any.
        foreach (['Fed', 'Loz', null] as $faction) {
            DB::table('replay')->where('game_id', self::SF)->delete();
            $this->uploaded(self::SF, 'sf-tumult', self::otherSummary($faction), 'Player', bytes: self::otherOnTeamOne());

            $this->check(self::SF);

            $this->assertSame('listed', DB::table('replay')->where('game_id', self::SF)->value('state'), (string) $faction);
            $this->assertNull(DB::table('replay_player')->where('game_id', self::SF)->where('name', 'Other')->value('faction'));
        }
    }

    public function test_a_splinter_faction_player_with_the_faction_chooser_skipped_is_stored_with_the_lobbys_side(): void
    {
        $bytes = self::otherOnTeamOne("\nskipfactionchooser=1;");
        $summary = self::otherSummary('Fed');
        // The game takes no pick, and the lobby set no side for Player's team.
        $summary['players'][0]['faction'] = null;
        $this->uploaded(self::SF, 'sf-tumult', $summary, 'Player', bytes: $bytes);

        $this->check(self::SF);

        $this->assertSame('listed', DB::table('replay')->where('game_id', self::SF)->value('state'));
        $this->assertSame(['Other' => 'Fed', 'Player' => null], DB::table('replay_player')->where('game_id', self::SF)->where('kind', 'player')->orderBy('name')->pluck('faction', 'name')->all());

        DB::table('replay')->where('game_id', self::SF)->delete();
        $summary['players'][1]['faction'] = 'Loz';
        $this->uploaded(self::SF, 'sf-tumult', $summary, 'Player', bytes: $bytes);

        $this->check(self::SF);

        $this->assertRefused(self::SF, 'summary_mismatch', "The summary's players are not the file's");
    }

    public function test_a_unit_name_that_is_not_the_replays_is_refused(): void
    {
        $summary = self::summary('bar-tangerine');
        $summary['units'][0]['name'] = 'armmadeup';
        $this->uploaded(self::BAR, 'bar-tangerine', $summary, 'Charlson');

        $this->check(self::BAR);

        $this->assertRefused(self::BAR, 'summary_mismatch', '"armmadeup" and the replay\'s unit list says');
    }

    public function test_a_unit_list_coilbox_ignores_is_passed_over_for_the_next(): void
    {
        // An empty list and one with an unusable name, ahead of the replay's own.
        $b = gzdecode(self::file('bar-tangerine'));
        $packets = '';
        foreach (['[]', '[""]'] as $json) {
            $data = 'unitdefs:'.gzcompress($json);
            $payload = chr(50).pack('v', 7 + strlen($data)).chr(0).pack('v', 100).chr(0).$data;
            $packets .= pack('g', 0.0).pack('V', strlen($payload)).$payload;
        }
        $start = 352 + unpack('V', $b, 304)[1];
        $b = substr_replace($b, pack('V', unpack('V', $b, 308)[1] + strlen($packets)), 308, 4);
        $this->uploaded(self::BAR, 'bar-tangerine', self::summary('bar-tangerine'), 'Charlson', bytes: gzencode(substr_replace($b, $packets, $start, 0)));

        $this->check(self::BAR);

        $this->assertSame('listed', DB::table('replay')->where('game_id', self::BAR)->value('state'));
    }

    public function test_unit_names_are_checked_against_a_release_the_catalogue_has_in_full(): void
    {
        $now = '2026-08-21 10:00:00.000000';
        DB::table('game')->insert(['id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'shortname' => 'SF', 'display_name' => 'SplinterFaction',
            'links' => '[]', 'conquest_factions' => '[]', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('game_version')->insert(['game_id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'version' => '0.1.95',
            'first_seen_at' => $now, 'last_seen_at' => $now, 'reported_in_full' => true]);
        $summary = self::summary('sf-tumult');
        foreach (array_slice($summary['units'], 1) as $i => $unit) {
            DB::table('game_unit')->insert(['game_id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'unit_name' => $unit['name'],
                'build_options' => '[]', 'stats' => '{}', 'facts_digest' => 'd'.$i, 'morph_targets' => '[]',
                'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->uploaded(self::SF, 'sf-tumult', $summary, 'Player');

        $this->check(self::SF);

        $this->assertRefused(self::SF, 'summary_mismatch', '"'.$summary['units'][0]['name'].'", which the catalogue does not have for this game');
    }

    public function test_a_replay_with_no_game_over_is_refused(): void
    {
        $this->uploaded(self::MF, 'mf-valles', self::summary('mf-valles'), 'probe');

        $this->check(self::MF);

        $this->assertRefused(self::MF, 'no_game_over', 'The file has no game over.');
    }

    public function test_a_file_whose_game_id_is_not_the_reserved_one_is_refused(): void
    {
        $other = '0a2c4b1ad07e5386c1f4a92b6e8d0375';
        $this->uploaded($other, 'sf-tumult', self::summary('sf-tumult'), 'Player');

        $this->check($other);

        $this->assertRefused($other, 'wrong_game_id', "The file's game id is ".self::SF);
    }

    public function test_an_uploader_who_is_not_in_the_file_is_refused(): void
    {
        $this->uploaded(self::SF, 'sf-tumult', self::summary('sf-tumult'), 'Mallory');

        $this->check(self::SF);

        $this->assertRefused(self::SF, 'sender_not_in_replay', '"Mallory"');
    }

    public function test_a_later_sender_who_is_not_in_the_file_is_dropped(): void
    {
        $this->uploaded(self::BAR, 'bar-tangerine', self::summary('bar-tangerine'), 'Charlson');
        DB::table('replay_sender')->insert([
            ['game_id' => self::BAR, 'user_id' => self::OTHER_USER, 'name' => 'Mallory', 'sent_at' => '2026-11-26 09:01:00.000000'],
            ['game_id' => self::BAR, 'user_id' => '33333333-3333-3333-3333-333333333333', 'name' => 'SKALLBAG', 'sent_at' => '2026-11-26 09:02:00.000000'],
        ]);

        $this->check(self::BAR);

        $this->assertSame('listed', DB::table('replay')->where('game_id', self::BAR)->value('state'));
        $this->assertSame(['Charlson', 'SKALLBAG'], DB::table('replay_sender')->orderBy('name')->pluck('name')->all());
    }

    public function test_a_mirror_that_serves_another_file_is_refused(): void
    {
        $this->uploaded(self::SF, 'sf-tumult', self::summary('sf-tumult'), 'Player', self::file('mf-valles'));

        $this->check(self::SF);

        $this->assertRefused(self::SF, 'wrong_file', 'The mirror gave a file of 3084 bytes');
    }

    public function test_a_mirror_that_fails_is_tried_again_and_refused_after_the_last_try(): void
    {
        $this->uploaded(self::SF, 'sf-tumult', self::summary('sf-tumult'), 'Player', Http::response('', 503));
        $job = new CheckReplay(self::SF);

        try {
            $job->handle();
            $this->fail('A mirror that answers 503 should make the job try again.');
        } catch (RuntimeException $e) {
            $this->assertSame('checking', DB::table('replay')->where('game_id', self::SF)->value('state'));
            $job->failed($e);
        }

        $this->assertRefused(self::SF, 'check_failed', 'The mirror answered 503');
    }

    public function test_a_replay_that_is_not_being_checked_is_left_alone(): void
    {
        $this->uploaded(self::SF, 'sf-tumult', self::summary('sf-tumult'), 'Player');
        DB::table('replay')->update(['state' => 'listed']);

        $this->assertNull(ReplayChecks::check(self::SF));
        $this->assertNull(ReplayChecks::failed(self::SF, 'late'));
        Http::assertNothingSent();
        $this->assertDatabaseCount('replay_refusal', 0);
    }
}
