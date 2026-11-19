<?php

namespace Tests\Feature\Games;

use App\Domain\Games\GameFacts;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SeedsGameCatalogue;
use Tests\TestCase;

/**
 * `POST /api/v1/games/facts` and the download offers with the catalogue in
 * MariaDB: `submit_game_facts`, `offer_game_download_sources` and
 * `decide_game_download_offer` done in PHP (#597).
 */
class LocalGameFactsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsGameCatalogue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLocalCatalogue();
        $this->seedCatalogue();
        $this->fakeSupabaseRemainder(['https://sb.test/auth/v1/user' => Http::response(['id' => self::OWNER])]);
    }

    private function submit(array $body): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer token')
            ->postJson('/api/v1/games/facts', $body + ['format' => GameFacts::FORMAT, 'version' => 1]);
    }

    public function test_a_first_report_creates_the_game_its_release_sides_and_units(): void
    {
        $response = $this->submit([
            'shortname' => 'NEW', 'release' => '0.1', 'name' => 'New Game', 'startUnits' => ['boss'],
            'factions' => [['key' => 'good', 'name' => 'Good'], ['key' => 'good', 'name' => 'Twice']],
            'units' => [
                ['name' => 'boss', 'fullName' => 'The Boss', 'factionKey' => 'good', 'buildOptions' => ['minion'], 'stats' => ['health' => 10]],
                ['name' => 'minion', 'fullName' => ' '],
            ],
        ]);

        // Answers are matched to the request by kind and name, so a side named
        // twice reads the second answer both times, as it did with Supabase.
        $refusedTwice = ['kind' => 'faction', 'name' => 'good', 'outcome' => 'refused', 'said' => 'duplicate key value violates unique constraint "game_faction_identity_idx"'];
        $response->assertOk()->assertJsonPath('results', [
            $refusedTwice,
            $refusedTwice,
            ['kind' => 'unit', 'name' => 'boss', 'outcome' => 'accepted'],
            ['kind' => 'unit', 'name' => 'minion', 'outcome' => 'accepted'],
        ]);
        $game = DB::table('game')->where('shortname', 'NEW')->first();
        $this->assertSame('New Game', $game->display_name);
        $this->assertSame(self::OWNER, $game->submitted_by);
        $this->assertSame('["boss"]', $game->start_units);
        $unit = DB::table('game_unit')->where('game_id', $game->id)->where('unit_name', 'boss')->first();
        $this->assertSame('["minion"]', $unit->build_options);
        $this->assertSame('{"health":10}', $unit->stats);
        $this->assertSame(1, DB::table('game_unit_revision')->where('unit_id', $unit->id)->where('version', '0.1')->count());
        $this->assertSame(1, DB::table('game_version')->where('game_id', $game->id)->count());
    }

    public function test_a_report_never_replaces_a_display_name_the_game_has(): void
    {
        $this->submit(['shortname' => 'NAMED', 'release' => '0.1', 'name' => 'First Name', 'units' => []])->assertOk();
        $this->submit(['shortname' => 'NAMED', 'release' => '0.2', 'name' => 'Second Name', 'units' => []])->assertOk();

        $this->assertSame('First Name', DB::table('game')->where('shortname', 'NAMED')->value('display_name'));
    }

    public function test_a_unit_the_table_refuses_costs_only_itself(): void
    {
        $results = Supabase::admin()->rpc('submit_game_facts', ['p_submitted_by' => self::OWNER, 'p_submission' => [
            'shortname' => 'BA', 'release' => '2.0', 'complete' => true,
            'units' => [
                ['unit' => ['name' => 'armcom', 'full_name' => str_repeat('x', 257), 'faction_key' => null, 'build_options' => [], 'stats' => (object) [], 'morph_targets' => []], 'facts_digest' => 'new'],
                ['unit' => ['name' => 'armsolar', 'full_name' => 'Solar', 'faction_key' => 'arm', 'build_options' => [], 'stats' => (object) [], 'morph_targets' => []], 'facts_digest' => 'newer'],
            ],
        ]]);

        $this->assertSame([
            ['kind' => 'unit', 'name' => 'armcom', 'outcome' => 'refused', 'said' => 'new row for relation "game_unit" violates check constraint "game_unit_full_name_check"'],
            ['kind' => 'unit', 'name' => 'armsolar', 'outcome' => 'accepted', 'said' => null],
        ], $results);
        // A refusal keeps the release from being marked as reported in full.
        $this->assertSame(0, (int) DB::table('game_version')->where('version', '2.0')->value('reported_in_full'));
        $this->assertSame('{}', DB::table('game_unit')->where('unit_name', 'armsolar')->value('stats'));
    }

    public function test_a_complete_report_retires_what_it_leaves_out_and_marks_the_release(): void
    {
        $response = $this->submit([
            'shortname' => 'BA', 'release' => '3.0', 'complete' => true, 'name' => 'Ignored',
            'units' => [['name' => 'armcom', 'fullName' => 'Arm Commander']],
        ]);

        $response->assertOk()->assertJsonPath('results.0.outcome', 'accepted');
        $this->assertSame('Balanced Annihilation', DB::table('game')->where('id', self::GAME)->value('display_name'));
        $live = DB::table('game_unit')->where('game_id', self::GAME)->whereNull('removed_at')->pluck('unit_name')->all();
        $this->assertSame(['armcom'], $live);
        $this->assertSame(1, (int) DB::table('game_version')->where('game_id', self::GAME)->where('version', '3.0')->value('reported_in_full'));
    }

    public function test_the_same_facts_again_are_recorded_for_a_new_release_and_unchanged_for_a_known_one(): void
    {
        $unit = ['name' => 'armcom', 'fullName' => 'Arm Commander'];
        $this->submit(['shortname' => 'BA', 'release' => '3.0', 'units' => [$unit]])->assertJsonPath('results.0.outcome', 'accepted');
        $this->submit(['shortname' => 'BA', 'release' => '3.1', 'units' => [$unit]])->assertJsonPath('results.0.outcome', 'recorded');
        $this->submit(['shortname' => 'BA', 'release' => '3.1', 'units' => [$unit]])->assertJsonPath('results.0.outcome', 'unchanged');
    }

    public function test_offers_are_held_once_and_the_owner_accepts_one(): void
    {
        $this->submit([
            'shortname' => 'BA', 'release' => '2.0', 'units' => [['name' => 'armcom']],
            'downloads' => [['kind' => 'rapid', 'value' => 'ba:test'], ['kind' => 'rapid', 'value' => 'ba:stable']],
        ])->assertOk();
        $this->submit(['shortname' => 'BA', 'release' => '2.0', 'units' => [['name' => 'armcom']], 'downloads' => [['kind' => 'rapid', 'value' => 'ba:test']]])->assertOk();
        $offers = DB::table('game_download_offer')->get();
        $this->assertCount(1, $offers);
        $this->assertSame('ba:test', $offers[0]->value);

        $this->assertTrue(Supabase::forToken('token', self::OWNER)->rpc('decide_game_download_offer', ['p_offer_id' => $offers[0]->id, 'p_accept' => true]));
        $this->assertSame(['ba:stable', 'ba:test'], DB::table('game_download_source')->orderBy('sort_order')->pluck('value')->all());
        $this->assertSame('accepted', DB::table('game_download_offer')->value('state'));
        $this->assertFalse(Supabase::forToken('token', self::OWNER)->rpc('decide_game_download_offer', ['p_offer_id' => $offers[0]->id, 'p_accept' => true]));
    }

    public function test_a_stranger_cannot_decide_an_offer_and_anon_cannot_submit(): void
    {
        DB::table('game_download_offer')->insert(['id' => 7, 'game_id' => self::GAME, 'kind' => 'rapid', 'value' => 'x:y', 'offered_by' => self::OWNER, 'state' => 'held', 'created_at' => '2026-11-01 00:00:00.000000']);

        try {
            Supabase::forToken('token', self::STRANGER)->rpc('decide_game_download_offer', ['p_offer_id' => 7, 'p_accept' => true]);
            $this->fail('Expected a refusal');
        } catch (SupabaseException $e) {
            $this->assertSame('42501', $e->errorCode);
        }
        try {
            Supabase::anon()->rpc('submit_game_facts', ['p_submission' => ['shortname' => 'X'], 'p_submitted_by' => self::OWNER]);
            $this->fail('Expected a refusal');
        } catch (SupabaseException $e) {
            $this->assertSame('42501', $e->errorCode);
        }
    }
}
