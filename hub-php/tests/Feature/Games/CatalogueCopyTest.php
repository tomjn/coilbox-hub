<?php

namespace Tests\Feature\Games;

use App\Supabase\Local\GameStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** `php artisan games:copy`: the dry run, the live copy and its switch (#597). */
class CatalogueCopyTest extends TestCase
{
    use RefreshDatabase;

    private const GAME = '00000000-0000-4000-8000-0000000000aa';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.games_store' => null,
            'hub.supabase.url' => 'https://sb.test',
            'hub.supabase.publishable_key' => 'pk',
            'hub.supabase.service_role_key' => 'sk',
            'hub.supabase.timeout' => 0,
        ]);
    }

    /** Supabase with one game and one unit, and `$units` as its unit count. */
    private function fakeSupabase(int $units = 1): void
    {
        $game = [
            'id' => self::GAME, 'shortname' => 'BA', 'display_name' => null, 'description' => null, 'links' => [],
            'start_units' => ['armcom'], 'submitted_by' => null, 'created_at' => '2026-08-21T10:00:00.5+00:00',
            'updated_at' => '2026-08-21T10:00:00+00:00', 'owner_user_id' => null, 'logo_path' => null, 'logo_hash' => null,
            'banner_path' => null, 'banner_hash' => null, 'hidden_at' => null, 'hidden_by' => null, 'logo_staged_tier' => null,
            'banner_staged_tier' => null, 'featured_at' => null, 'featured_by' => null, 'card_path' => null, 'card_hash' => null,
            'card_staged_tier' => null, 'conquest_factions' => [], 'base_game' => null,
        ];
        $unit = [
            'id' => 9, 'game_id' => self::GAME, 'unit_name' => 'armcom', 'full_name' => null, 'faction_key' => null,
            'build_options' => [], 'stats' => (object) [], 'facts_digest' => 'd', 'source_version' => null, 'removed_at' => null,
            'last_seen_at' => '2026-08-21T10:00:00+00:00', 'created_at' => '2026-08-21T10:00:00+00:00',
            'updated_at' => '2026-08-21T10:00:00+00:00', 'snippet' => null, 'morph_targets' => [],
        ];
        Http::fake(function (Request $request) use ($game, $unit, $units) {
            $url = urldecode($request->url());
            preg_match('#/rest/v1/([a-z_]+)#', $url, $m);
            $table = $m[1];
            if ($request->method() === 'HEAD') {
                $count = ['game' => 1, 'game_unit' => $units][$table] ?? 0;

                return Http::response('', 200, ['Content-Range' => '*/'.$count]);
            }
            $rows = str_contains($url, 'offset=0') ? (['game' => [$game], 'game_unit' => [$unit]][$table] ?? []) : [];

            return Http::response(json_encode($rows));
        });
    }

    public function test_the_dry_run_reports_counts_and_writes_nothing(): void
    {
        $this->fakeSupabase();

        $this->artisan('games:copy', ['--dry-run' => true])
            ->expectsOutput('game: Supabase 1, MariaDB 0')
            ->expectsOutput('game_unit: Supabase 1, MariaDB 0')
            ->expectsOutput('Dry run: nothing was written.')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('game')->count());
        $this->assertSame(GameStore::SUPABASE, GameStore::fresh());
    }

    public function test_the_copy_keeps_ids_objects_and_times_then_switches(): void
    {
        $this->fakeSupabase();

        $this->artisan('games:copy')
            ->expectsOutput('game_unit: Supabase 1, copied 1, MariaDB 1')
            ->expectsOutput('The hub now reads and writes games in MariaDB.')
            ->assertExitCode(0);

        $this->assertSame(GameStore::MARIADB, GameStore::fresh());
        $game = DB::table('game')->first();
        $this->assertSame(self::GAME, $game->id);
        $this->assertSame('2026-08-21 10:00:00.500000', $game->created_at);
        $this->assertSame('["armcom"]', $game->start_units);
        $this->assertSame('{}', DB::table('game_unit')->where('id', 9)->value('stats'));

        $this->artisan('games:copy')->assertExitCode(1);
    }

    public function test_a_short_copy_goes_back_to_supabase_and_keeps_nothing(): void
    {
        $this->fakeSupabase(units: 2);

        $this->artisan('games:copy')
            ->expectsOutput('The hub still reads games from Supabase.')
            ->assertExitCode(1);

        $this->assertSame(GameStore::SUPABASE, GameStore::fresh());
        $this->assertSame(0, DB::table('game')->count());
    }

    public function test_the_store_can_go_back_to_supabase_only(): void
    {
        GameStore::set(GameStore::MARIADB);

        $this->artisan('games:store', ['state' => 'mariadb'])->assertExitCode(1);
        $this->artisan('games:store', ['state' => 'supabase'])->assertExitCode(0);
        $this->assertSame(GameStore::SUPABASE, GameStore::fresh());
    }
}
