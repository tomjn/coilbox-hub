<?php

namespace Tests\Feature\Games;

use App\Supabase\Local\GameStore;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsGameCatalogue;
use Tests\TestCase;

/**
 * The game catalogue read and written in MariaDB (#597): PostgREST's answers,
 * row level security and grants, as the code that calls Supabase sees them.
 */
class LocalCatalogueTest extends TestCase
{
    use RefreshDatabase;
    use SeedsGameCatalogue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLocalCatalogue();
        $this->seedCatalogue();
    }

    public function test_reads_answer_in_postgrest_shapes_without_asking_supabase(): void
    {
        Http::fake();

        $game = Supabase::anon()->from('game')
            ->select('shortname,start_units,links,created_at,game_version(version,last_seen_at,reported_in_full),game_faction(key,name)')
            ->eq('shortname', 'BA')
            ->order('last_seen_at', ascending: false, foreignTable: 'game_version')
            ->limit(1, foreignTable: 'game_version')
            ->maybeSingle();

        $this->assertSame([
            'shortname' => 'BA',
            'start_units' => ['armcom', 'corcom'],
            'links' => [],
            'created_at' => '2026-08-21T10:00:00+00:00',
            // PostgREST moves an embed with a limit or order to the front, and
            // an embedded row is jsonb, so its keys are shortest first.
            'game_version' => [['version' => '2.0', 'last_seen_at' => '2026-09-21T10:00:00.123456+00:00', 'reported_in_full' => false]],
            'game_faction' => [['key' => 'arm', 'name' => 'Arm'], ['key' => 'core', 'name' => 'Core'], ['key' => 'random', 'name' => 'Random']],
        ], $game);
        Http::assertNothingSent();
    }

    public function test_inner_embeds_filter_their_parents_and_counts_cover_the_whole_match(): void
    {
        $page = Supabase::anon()->from('game_unit')
            ->select('unit_name,full_name,game!inner(shortname)', 'exact')
            ->eq('game.shortname', 'BA')
            ->is('removed_at', null)
            ->or('unit_name.ilike.%COM%,full_name.ilike.%solar%')
            ->not('unit_name', 'in', '(corcom)')
            ->order('unit_name')
            ->range(0, 0)
            ->getWithCount();

        $this->assertSame(2, $page['count']);
        $this->assertSame([['unit_name' => 'armcom', 'full_name' => 'Arm Commander', 'game' => ['shortname' => 'BA']]], $page['rows']);
    }

    public function test_nested_inner_embeds_reach_through_two_tables(): void
    {
        $rows = Supabase::anon()->from('game_unit_revision')
            ->select('version,stats,game_unit!inner(unit_name,game!inner(shortname))')
            ->eq('game_unit.game.shortname', 'BA')
            ->eq('game_unit.unit_name', 'armcom')
            ->order('version', ascending: false)
            ->get();

        $this->assertSame([
            ['version' => '2.0', 'stats' => ['health' => 3000, 'metalCost' => 0], 'game_unit' => ['game' => ['shortname' => 'BA'], 'unit_name' => 'armcom']],
            ['version' => '1.0', 'stats' => ['health' => 2500], 'game_unit' => ['game' => ['shortname' => 'BA'], 'unit_name' => 'armcom']],
        ], $rows);
    }

    public function test_json_paths_select_inside_a_column(): void
    {
        $row = Supabase::anon()->from('game_unit')
            ->select('unit_name,health:stats->health,stats->>metalCost,missing:stats->weapons->0')
            ->eq('unit_name', 'armcom')
            ->maybeSingle();

        $this->assertSame(['unit_name' => 'armcom', 'health' => 3000, 'metalCost' => '0', 'missing' => null], $row);
    }

    public function test_an_offset_past_the_end_is_refused_as_postgrest_refuses_it(): void
    {
        try {
            Supabase::anon()->from('game_unit')->select('unit_name', 'exact')->range(50, 99)->getWithCount();
            $this->fail('Expected PGRST103');
        } catch (SupabaseException $e) {
            $this->assertSame('PGRST103', $e->errorCode);
            $this->assertSame(416, $e->status);
        }
    }

    public function test_a_hidden_game_is_invisible_to_anon_and_visible_to_its_owner(): void
    {
        $this->fakeSupabaseRemainder();

        $this->assertNull(Supabase::anon()->from('game')->select('shortname')->eq('shortname', 'HID')->maybeSingle());
        $this->assertSame([], Supabase::anon()->from('game_unit')->select('unit_name')->eq('unit_name', 'secret')->get());
        $this->assertSame([], Supabase::anon()->from('game_unit_revision')->select('version')->eq('unit_id', 5)->get());

        DB::table('game')->where('id', self::HIDDEN_GAME)->update(['owner_user_id' => self::OWNER]);
        $owner = Supabase::forToken('token', self::OWNER);
        $this->assertSame(['shortname' => 'HID'], $owner->from('game')->select('shortname')->eq('shortname', 'HID')->maybeSingle());
        $this->assertCount(1, $owner->from('game_unit_revision')->select('version')->eq('unit_id', 5)->get());
    }

    public function test_a_moderator_sees_a_hidden_game(): void
    {
        $this->fakeSupabaseRemainder(['https://sb.test/rest/v1/rpc/is_moderator' => Http::response('true')]);

        $moderator = Supabase::forToken('token', self::STRANGER);

        $this->assertSame(['shortname' => 'HID'], $moderator->from('game')->select('shortname')->eq('shortname', 'HID')->maybeSingle());
    }

    public function test_the_owner_edits_the_words_and_supabase_keeps_a_copy_of_the_row(): void
    {
        $this->fakeSupabaseRemainder();

        $rows = Supabase::forToken('token', self::OWNER)->from('game')
            ->update(['display_name' => 'BA Reloaded'])->eq('shortname', 'BA')->select('shortname')->get();

        $this->assertSame([['shortname' => 'BA']], $rows);
        $this->assertSame('BA Reloaded', DB::table('game')->where('id', self::GAME)->value('display_name'));
        $this->assertNotSame('2026-08-21 10:00:00.000000', DB::table('game')->where('id', self::GAME)->value('updated_at'));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_starts_with($request->url(), 'https://sb.test/rest/v1/game?')
            && str_contains(urldecode($request->url()), 'on_conflict=id')
            && $request->data()[0]['display_name'] === 'BA Reloaded'
            && $request->data()[0]['id'] === self::GAME);
    }

    public function test_a_stranger_updates_nothing_and_a_column_outside_the_grant_is_refused(): void
    {
        $this->fakeSupabaseRemainder();

        $rows = Supabase::forToken('token', self::STRANGER)->from('game')
            ->update(['display_name' => 'Mine now'])->eq('shortname', 'BA')->select('shortname')->get();
        $this->assertSame([], $rows);
        $this->assertSame('Balanced Annihilation', DB::table('game')->where('id', self::GAME)->value('display_name'));

        try {
            Supabase::forToken('token', self::OWNER)->from('game')->update(['owner_user_id' => self::STRANGER])->eq('shortname', 'BA')->execute();
            $this->fail('Expected a permission error');
        } catch (SupabaseException $e) {
            $this->assertSame('42501', $e->errorCode);
        }
        try {
            Supabase::anon()->from('game_download_source')->insert(['game_id' => self::GAME, 'kind' => 'rapid', 'value' => 'x'])->execute();
            $this->fail('Expected a permission error');
        } catch (SupabaseException $e) {
            $this->assertSame('42501', $e->errorCode);
        }
    }

    public function test_check_constraints_refuse_with_postgres_wording(): void
    {
        $this->fakeSupabaseRemainder();

        try {
            Supabase::forToken('token', self::OWNER)->from('game')->update(['display_name' => str_repeat('x', 257)])->eq('shortname', 'BA')->execute();
            $this->fail('Expected a check violation');
        } catch (SupabaseException $e) {
            $this->assertSame('23514', $e->errorCode);
            $this->assertSame('new row for relation "game" violates check constraint "game_display_name_check"', $e->getMessage());
        }
    }

    public function test_an_owner_replaces_download_sources_and_one_open_ownership_ask_is_allowed(): void
    {
        $this->fakeSupabaseRemainder();
        $owner = Supabase::forToken('token', self::OWNER);

        $owner->from('game_download_source')->insert([
            ['game_id' => self::GAME, 'kind' => 'url', 'value' => 'https://example.test/ba.sdz', 'asset' => null, 'filename' => 'ba.sdz', 'sort_order' => 0],
        ])->execute();
        $owner->from('game_download_source')->delete()->in('id', [1])->execute();
        $this->assertSame(['url'], DB::table('game_download_source')->pluck('kind')->all());

        $stranger = Supabase::forToken('token', self::STRANGER);
        $stranger->from('game_ownership_request')->insert(['game_id' => self::GAME, 'requested_by' => self::STRANGER, 'note' => 'mine'])->execute();
        $this->assertSame('Requester', DB::table('game_ownership_request')->value('requested_by_name'));
        try {
            $stranger->from('game_ownership_request')->insert(['game_id' => self::GAME, 'requested_by' => self::STRANGER, 'note' => 'again'])->execute();
            $this->fail('Expected a duplicate');
        } catch (SupabaseException $e) {
            $this->assertSame('23505', $e->errorCode);
        }
        try {
            $stranger->from('game_ownership_request')->insert(['game_id' => self::GAME, 'requested_by' => self::OWNER, 'note' => 'forged'])->execute();
            $this->fail('Expected a policy refusal');
        } catch (SupabaseException $e) {
            $this->assertSame('42501', $e->errorCode);
        }
    }

    public function test_game_browse_counts_and_lists_downloads_with_the_mirror_copies(): void
    {
        $this->fakeSupabaseRemainder([
            'https://sb.test/rest/v1/item*' => Http::sequence()->push([['game_key' => 'BA'], ['game_key' => 'BA']])->push([]),
            'https://sb.test/rest/v1/rpc/game_mirror_sources' => Http::response([['kind' => 'url', 'value' => 'https://m.test/games/ba.sdz', 'sha256' => 'abc', 'filename' => 'ba.sdz']]),
        ]);

        $row = Supabase::anon()->from('game_browse')->select('shortname,faction_count,unit_count,item_count,downloads')->eq('shortname', 'BA')->maybeSingle();

        $this->assertSame([
            'shortname' => 'BA',
            'faction_count' => 2,
            'unit_count' => 3,
            'item_count' => 2,
            'downloads' => [
                ['kind' => 'rapid', 'value' => 'ba:stable'],
                ['kind' => 'url', 'value' => 'https://m.test/games/ba.sdz', 'sha256' => 'abc', 'filename' => 'ba.sdz'],
            ],
        ], $row);
    }

    public function test_writes_are_refused_while_the_copy_runs_and_reads_go_to_supabase(): void
    {
        config(['hub.games_store' => null]);
        GameStore::set(GameStore::FROZEN);
        Http::fake(['https://sb.test/rest/v1/game*' => Http::response([['shortname' => 'FROM-SUPABASE']])]);

        $this->assertSame([['shortname' => 'FROM-SUPABASE']], Supabase::anon()->from('game')->select('shortname')->get());
        try {
            Supabase::admin()->from('game')->update(['featured_at' => null])->eq('shortname', 'BA')->execute();
            $this->fail('Expected a refusal');
        } catch (SupabaseException $e) {
            $this->assertSame('HUB_FROZEN', $e->errorCode);
            $this->assertSame(503, $e->status);
        }
    }

    public function test_sample_units_come_only_from_visible_games(): void
    {
        $rows = Supabase::anon()->rpc('game_sample_units', ['p_shortnames' => ['BA', 'HID'], 'p_count' => 10]);

        $this->assertCount(3, $rows);
        $this->assertSame(['BA'], array_values(array_unique(array_column($rows, 'shortname'))));
        $this->assertEqualsCanonicalizing(['armcom', 'armsolar', 'corcom'], array_column($rows, 'unit_name'));
    }
}
