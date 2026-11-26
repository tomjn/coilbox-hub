<?php

namespace Tests\Feature\Replays;

use App\Domain\Replays\ReplayAnalyses;
use App\Jobs\CheckReplay;
use App\Jobs\CheckReplayAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Moderation\ModerationTestCase;

/**
 * Replay analyses from Coilbox (#664). `sf-tumult.events.jsonl` is the log
 * Coilbox's replay logger wrote playing `sf-tumult.sdfz` back, from a run
 * Coilbox found reproduced the match. Coilbox stamped it logger version 6.
 */
class ReplayAnalysisTest extends ModerationTestCase
{
    use RefreshDatabase;

    private const ALICE = '11111111-1111-1111-1111-111111111111';

    private const BOB = '22222222-2222-2222-2222-222222222222';

    private const SF = '9cf5d76a4468b019403831ac99faf0f7';

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.games_store' => 'mariadb', 'hub.maps_store' => 'mariadb', 'hub.mirrors_store' => 'mariadb', 'app.url' => 'https://hub.test']);
        Queue::fake();
        $bytes = file_get_contents(base_path('tests/Fixtures/replays/sf-tumult.sdfz'));
        DB::table('mirror')->insert([
            'id' => 'jj-darkstars', 'name' => 'jj.darkstars.co.uk', 'base_url' => 'https://mirror.test', 'public_key' => 'x',
            'enabled' => true, 'healthy' => true, 'takes_replays' => true, 'created_at' => '2026-11-17 12:00:00.000000',
        ]);
        DB::table('replay')->insert([
            'game_id' => self::SF, 'state' => 'checking', 'uploader' => self::ALICE, 'sha256' => hash('sha256', $bytes),
            'size' => strlen($bytes), 'upload_mirror_id' => 'jj-darkstars', 'upload_id' => '33333333-3333-4333-8333-333333333333',
            'summary' => file_get_contents(base_path('tests/Fixtures/replays/sf-tumult.summary.json')), 'reserved_until' => null,
            'received_at' => '2026-11-26 09:00:00.000000', 'created_at' => '2026-11-26 09:00:00.000000', 'updated_at' => '2026-11-26 09:00:00.000000',
        ]);
        DB::table('replay_sender')->insert(['game_id' => self::SF, 'user_id' => self::ALICE, 'name' => 'Player', 'sent_at' => '2026-11-26 09:00:00.000000']);
        // Each bearer token is one account: `alice` and `bob`.
        $this->supabase([
            '/auth/v1/user' => fn (Request $r) => self::reply(['id' => $r->header('Authorization')[0] === 'Bearer bob' ? self::BOB : self::ALICE]),
            'mirror.test/' => Http::response($bytes),
        ]);
        (new CheckReplay(self::SF))->handle();
        $this->assertSame('listed', DB::table('replay')->where('game_id', self::SF)->value('state'));
    }

    private static function events(): string
    {
        return file_get_contents(base_path('tests/Fixtures/replays/sf-tumult.events.jsonl'));
    }

    /** The log with its game over line changed by `$change`. */
    private static function gameOver(callable $change): string
    {
        $lines = explode("\n", rtrim(self::events(), "\n"));
        $over = json_decode(array_pop($lines), true);
        $change($over);

        return implode("\n", [...$lines, json_encode($over, JSON_PRESERVE_ZERO_FRACTION)])."\n";
    }

    private function send(string $token = 'alice', ?string $events = null, int $version = 6, string $gameId = self::SF): TestResponse
    {
        return $this->call('POST', '/api/v1/replays/'.$gameId.'/analyses', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['logger_version' => $version, 'events' => $events ?? self::events()]));
    }

    /** Sends an analysis and runs its check, answering the submission's row. */
    private function checked(string $token = 'alice', ?string $events = null, int $version = 6): object
    {
        $id = $this->send($token, $events, $version)->assertStatus(202)->json('analysis.id');
        (new CheckReplayAnalysis($id))->handle();

        return DB::table('replay_analysis_submission')->where('id', $id)->first();
    }

    public function test_a_real_analysis_passes_and_is_kept(): void
    {
        $response = $this->send();

        $response->assertStatus(202)->assertJson(['analysis' => ['game_id' => self::SF, 'logger_version' => 6, 'state' => 'checking', 'reason' => null]]);
        Queue::assertPushed(CheckReplayAnalysis::class, fn ($job) => $job->submissionId === $response->json('analysis.id'));

        (new CheckReplayAnalysis($response->json('analysis.id')))->handle();

        $row = DB::table('replay_analysis_submission')->first();
        $this->assertSame(['kept', null], [$row->state, $row->events]);
        $kept = DB::table('replay_analysis')->where('game_id', self::SF)->first();
        $this->assertSame([6, self::events()], [(int) $kept->logger_version, $kept->events]);
        $this->assertSame(['unit_created' => 72, 'unit_destroyed' => 7, 'game_over' => 1], array_intersect_key(json_decode($kept->counts, true), ['unit_created' => 0, 'unit_destroyed' => 0, 'game_over' => 0]));
        $this->assertSame(1, ReplayAnalyses::agreements(self::SF));
    }

    public function test_an_analysis_with_one_changed_total_is_refused_with_its_reason(): void
    {
        $row = $this->checked(events: self::gameOver(function (array &$over) {
            $over['teams'][0]['metalUsed'] += 1;
        }));

        $this->assertSame(['refused', 'total_mismatch', null], [$row->state, $row->reason, $row->events]);
        $this->assertStringContainsString("Team 0's metalUsed is 7425.65087890625 in the run and 7424.65087890625 in the replay.", $row->detail);
        $this->assertDatabaseCount('replay_analysis', 0);
        $this->assertSame(0, ReplayAnalyses::agreements(self::SF));
    }

    public function test_a_second_identical_analysis_from_someone_else_agrees(): void
    {
        $this->checked('alice');
        $second = $this->checked('bob');

        $this->assertSame('agreed', $second->state);
        $this->assertSame(2, ReplayAnalyses::agreements(self::SF));
        $this->assertDatabaseCount('replay_analysis', 1);
    }

    public function test_the_same_sender_sending_again_is_answered_with_the_first_and_counts_once(): void
    {
        $first = $this->checked('alice');

        $this->send('alice')->assertStatus(200)->assertJson(['analysis' => ['id' => $first->id, 'state' => 'kept']]);
        $this->assertDatabaseCount('replay_analysis_submission', 1);

        // Another run of theirs that passes still counts them once.
        $this->checked('alice', self::events()."\n");
        $this->assertSame(1, ReplayAnalyses::agreements(self::SF));
    }

    public function test_a_log_missing_a_death_is_refused_on_the_count(): void
    {
        $lines = explode("\n", self::events());
        $at = array_key_first(array_filter($lines, fn ($l) => str_contains($l, '"kind":"unit_destroyed"')));
        unset($lines[$at]);

        $row = $this->checked(events: implode("\n", $lines));

        $this->assertSame(['refused', 'count_mismatch'], [$row->state, $row->reason]);
        $this->assertStringContainsString('The log has 5 unit_destroyed lines for team 0, and the replay\'s unitsDied is 6.', $row->detail);
    }

    public function test_a_unit_still_dying_at_game_over_is_left_out_of_the_count(): void
    {
        $lines = explode("\n", rtrim(self::events(), "\n"));
        $over = json_decode(array_pop($lines), true);
        $over['dying'] = [99999];
        $lines[] = '{"kind":"unit_destroyed","frame":15054,"unit":99999,"def":20,"team":0,"x":1.0,"y":1.0,"z":1.0}';
        $lines[] = json_encode($over, JSON_PRESERVE_ZERO_FRACTION);

        $this->assertSame('kept', $this->checked(events: implode("\n", $lines))->state);
    }

    public function test_a_changed_winner_or_length_or_map_is_refused(): void
    {
        $winner = $this->checked(events: self::gameOver(function (array &$over) {
            $over['winners'] = [1];
        }));
        $length = $this->checked(events: self::gameOver(function (array &$over) {
            $over['frame'] += 30;
        }));
        $map = $this->checked(events: str_replace('"map":"Tumult"', '"map":"Tumult v2"', self::events()));
        $samples = $this->checked(events: self::gameOver(function (array &$over) {
            $over['teams'][1]['samples'] = 34;
        }));

        $this->assertSame(['outcome_mismatch', 'outcome_mismatch', 'wrong_map', 'total_mismatch'], [$winner->reason, $length->reason, $map->reason, $samples->reason]);
        $this->assertStringContainsString('The run lasted 502 game seconds, and the replay 501.', $length->detail);
    }

    public function test_a_log_with_no_game_over_or_a_broken_line_is_refused(): void
    {
        $lines = explode("\n", rtrim(self::events(), "\n"));
        array_pop($lines);

        $this->assertSame('no_game_over', $this->checked(events: implode("\n", $lines))->reason);
        $this->assertSame('unreadable', $this->checked(events: "nope\n".self::events())->reason);
    }

    public function test_a_newer_logger_version_replaces_the_kept_analysis_once_it_passes(): void
    {
        $this->checked('alice', version: 6);
        $this->checked('bob', version: 6);
        $this->assertSame('refused', $this->checked('bob', self::gameOver(function (array &$over) {
            $over['winners'] = [];
        }), 7)->state);
        $this->assertSame(6, (int) DB::table('replay_analysis')->value('logger_version'));

        $newer = $this->checked('bob', self::events()."\n", 7);

        $this->assertSame('kept', $newer->state);
        $kept = DB::table('replay_analysis')->first();
        $this->assertSame([7, $newer->id], [(int) $kept->logger_version, (int) $kept->submission_id]);
        $this->assertSame(1, ReplayAnalyses::agreements(self::SF));
        $this->assertSame(['older', 'agreed'], DB::table('replay_analysis_submission')->where('logger_version', 6)->orderBy('id')->pluck('state')->all());

        $this->assertSame('older', $this->checked('alice', self::events()."\n\n", 6)->state);
        $this->assertSame(1, ReplayAnalyses::agreements(self::SF));
    }

    public function test_an_analysis_for_a_replay_the_hub_does_not_list_is_refused(): void
    {
        $this->send(gameId: str_repeat('a', 32))->assertStatus(404)->assertJson(['reason' => 'not_listed']);

        $id = $this->send()->json('analysis.id');
        DB::table('replay')->where('game_id', self::SF)->update(['state' => 'removed']);
        (new CheckReplayAnalysis($id))->handle();
        $this->assertSame('not_listed', DB::table('replay_analysis_submission')->where('id', $id)->value('reason'));
    }

    public function test_a_check_that_fails_on_every_try_is_refused(): void
    {
        $id = $this->send()->json('analysis.id');

        (new CheckReplayAnalysis($id))->failed(new \RuntimeException('the database went away'));

        $row = DB::table('replay_analysis_submission')->where('id', $id)->first();
        $this->assertSame(['refused', 'check_failed', 'The check did not finish: the database went away'], [$row->state, $row->reason, $row->detail]);
    }

    public function test_bad_requests_and_signed_out_senders_are_refused(): void
    {
        $this->call('POST', '/api/v1/replays/'.self::SF.'/analyses', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(401);
        $this->call('POST', '/api/v1/replays/'.self::SF.'/analyses', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer alice', 'CONTENT_TYPE' => 'application/json'], 'nope')
            ->assertStatus(400)->assertJson(['reason' => 'bad_request']);
        $this->send(version: 0)->assertStatus(400)->assertJsonFragment(['reason' => 'bad_request']);
        $this->send(events: ' ')->assertStatus(400);
        $this->send(gameId: 'NOT-A-GAME-ID')->assertStatus(400);
        $this->call('OPTIONS', '/api/v1/replays/'.self::SF.'/analyses')->assertStatus(204);
        $this->assertDatabaseCount('replay_analysis_submission', 0);
    }
}
