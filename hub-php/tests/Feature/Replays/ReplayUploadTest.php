<?php

namespace Tests\Feature\Replays;

use App\Domain\Mirrors\B64;
use App\Domain\Mirrors\Signing;
use App\Domain\Replays\ReplayChecks;
use App\Domain\Replays\ReplaySummary;
use App\Jobs\CheckReplay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Moderation\ModerationTestCase;
use Tests\Unit\Mirrors\MirrorFixtures;

/** Coilbox sends a match replay, stored once however many players send it (#662). */
class ReplayUploadTest extends ModerationTestCase
{
    use MirrorFixtures;
    use RefreshDatabase;

    private const ALICE = '11111111-1111-1111-1111-111111111111';

    private const BOB = '22222222-2222-2222-2222-222222222222';

    private const GAME_ID = '9f2c4b1ad07e5386c1f4a92b6e8d0375';

    private const SHA = '77ab12c4d0e9f8a7b6c5d4e3f2a1b0c9d8e7f6a5b4c3d2e1f0a9b8c7d6e5f4a3';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.mirrors_store' => 'mariadb',
            'hub.mirrors.secret_key' => self::fixtureKeys()['hub_secret_key'],
            'hub.mirrors.next_secret_key' => '',
            'hub.mirrors.reservation' => 86400,
            'app.url' => 'https://hub.test',
        ]);
        DB::table('mirror')->insert([
            'id' => 'jj-darkstars', 'name' => 'jj.darkstars.co.uk', 'base_url' => 'https://mirror.test',
            'public_key' => self::fixtureKeys()['mirror_public_key'], 'enabled' => true, 'healthy' => true,
            'takes_replays' => true, 'created_at' => '2026-11-17 12:00:00.000000',
        ]);
        Queue::fake();
        // Each bearer token is one account: `alice` and `bob`.
        $this->supabase([
            '/auth/v1/user' => fn (Request $r) => self::reply(['id' => $r->header('Authorization')[0] === 'Bearer bob' ? self::BOB : self::ALICE]),
            'mirror.test/' => Http::response('', 200),
        ]);
    }

    /** @return array<string, mixed> */
    private static function summary(): array
    {
        $totals = array_fill_keys(ReplaySummary::TOTALS, 0) + ['metal_produced' => 1520.5];

        return [
            'v' => 1, 'engine' => '2025.04.08', 'game' => 'Beyond All Reason test-27000-abc', 'map' => 'Supreme Isthmus v2.1',
            'started_at' => 1794787260, 'duration' => 1312,
            'players' => [
                ['name' => 'Alice', 'team' => 0, 'ally_team' => 0, 'faction' => 'Armada'],
                ['name' => 'Bob', 'team' => 1, 'ally_team' => 1, 'faction' => null],
            ],
            'spectators' => ['Carol'],
            'winners' => [0],
            'totals' => [['team' => 0] + $totals, ['team' => 1] + $totals],
            'start_positions' => [['team' => 0, 'x' => 812.5, 'z' => 1660.5], ['team' => 1, 'x' => 7300, 'z' => 1660.5]],
            'build_orders' => [['team' => 0, 'orders' => [['frame' => 95, 'unit_def_id' => 12], ['frame' => 410, 'unit_def_id' => 12]]], ['team' => 1, 'orders' => []]],
            'units' => [['unit_def_id' => 12, 'name' => 'armmex', 'display_name' => 'Metal Extractor', 'metal' => 50, 'energy' => 500, 'build_time' => 1800, 'category' => 'economy']],
        ];
    }

    /** @param array<string, mixed> $over */
    private function send(string $token = 'alice', array $over = []): TestResponse
    {
        return $this->call('POST', '/api/v1/replays/uploads', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'], json_encode($over + [
            'game_id' => self::GAME_ID, 'sha256' => self::SHA, 'size' => 684112,
            'name' => $token === 'bob' ? 'Bob' : 'Alice', 'summary' => self::summary(),
        ]));
    }

    /** @param list<string> $held */
    private function report(array $held, array $kinds = ['map', 'game', 'engine', 'replay', 'track']): TestResponse
    {
        $payload = json_encode([
            'v' => 1, 'mirror' => 'jj-darkstars', 'event' => 'sync', 'upload' => null, 'sent_at' => 1794787260,
            'catalogue_seq' => null, 'catalogue_generated_at' => null, 'held' => $held, 'unknown' => [], 'kinds' => $kinds,
        ]);
        $sig = Signing::sign(Signing::CTX_REPORT, $payload, B64::decode(self::fixtureKeys()['mirror_secret_key']));

        return $this->call('POST', '/api/v1/mirrors/report', [], [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['payload' => B64::encode($payload), 'sig' => B64::encode($sig)]));
    }

    /** @return array<string, mixed> */
    private static function tokenPayload(string $token): array
    {
        return json_decode(B64::decode(explode('.', $token)[0]), true);
    }

    public function test_the_first_sender_gets_a_replay_token_and_reserves_the_match(): void
    {
        $upload = $this->send()->assertCreated()
            ->assertJsonPath('upload.game_id', self::GAME_ID)
            ->assertJsonPath('upload.state', 'uploading')
            ->assertJsonPath('upload.mirror', 'jj-darkstars')
            ->assertJsonPath('upload.filename', self::GAME_ID.'.sdfz')
            ->assertJsonPath('upload.upload_url', 'https://mirror.test/upload.php')
            ->assertJsonPath('upload.limits_url', 'https://mirror.test/limits.php')
            ->json('upload');

        $token = self::tokenPayload($upload['token']);
        $this->assertSame([
            'v' => 1, 'mirror' => 'jj-darkstars', 'upload' => $upload['id'], 'sha256' => self::SHA, 'size' => 684112,
            'filename' => self::GAME_ID.'.sdfz', 'kind' => 'replay', 'start_by' => $upload['start_by'],
        ], $token);
        $this->assertTrue(Signing::verify(Signing::CTX_TOKEN, B64::decode(explode('.', $upload['token'])[0]),
            B64::decode(explode('.', $upload['token'])[1]), B64::decode(self::fixtureKeys()['hub_public_key'])));

        $row = DB::table('replay')->where('game_id', self::GAME_ID)->first();
        $this->assertSame('uploading', $row->state);
        $this->assertSame(self::ALICE, $row->uploader);
        $this->assertSame(self::summary(), json_decode($row->summary, true));
        $this->assertSame([['user_id' => self::ALICE, 'name' => 'Alice']], DB::table('replay_sender')->get(['user_id', 'name'])->map(fn ($r) => (array) $r)->all());
    }

    public function test_two_players_sending_one_match_store_one_replay_with_two_senders_and_the_second_never_uploads(): void
    {
        $this->send()->assertCreated();
        $this->report([self::SHA])->assertOk();

        $this->send('bob', ['sha256' => str_repeat('b', 64)])->assertOk()
            ->assertExactJson(['replay' => ['game_id' => self::GAME_ID, 'state' => 'checking', 'url' => null]]);

        $this->assertSame(1, DB::table('replay')->count());
        $this->assertSame(self::SHA, DB::table('replay')->value('sha256'));
        $this->assertEqualsCanonicalizing([self::ALICE, self::BOB], DB::table('replay_sender')->pluck('user_id')->all());
        $this->assertSame('Bob', DB::table('replay_sender')->where('user_id', self::BOB)->value('name'));
    }

    public function test_a_listed_replay_answers_with_its_page(): void
    {
        $this->send()->assertCreated();
        DB::table('replay')->update(['state' => 'listed']);

        $this->send('bob')->assertOk()->assertJsonPath('replay.url', 'https://hub.test/replays/'.self::GAME_ID);
    }

    public function test_sending_a_held_replay_again_records_the_sender_once(): void
    {
        $this->send()->assertCreated();
        $this->report([self::SHA]);

        $this->send()->assertOk()->assertJsonPath('replay.state', 'checking');

        $this->assertSame(1, DB::table('replay_sender')->count());
    }

    public function test_a_mirror_report_holding_the_file_queues_the_check(): void
    {
        $this->send()->assertCreated();

        $this->report([str_repeat('c', 64)])->assertOk();
        $this->assertSame('uploading', DB::table('replay')->value('state'));
        Queue::assertNothingPushed();

        $this->report([self::SHA])->assertOk();

        $row = DB::table('replay')->first();
        $this->assertSame('checking', $row->state);
        $this->assertNotNull($row->received_at);
        $this->assertNull($row->reserved_until);
        Queue::assertPushed(CheckReplay::class, fn (CheckReplay $job) => $job->gameId === self::GAME_ID);
        Queue::assertPushed(CheckReplay::class, 1);
    }

    public function test_only_the_mirror_given_the_upload_can_finish_it(): void
    {
        DB::table('mirror')->insert([
            'id' => 'other', 'name' => 'other', 'base_url' => 'https://other.test',
            'public_key' => self::fixtureKeys()['other_mirror_public_key'], 'enabled' => true, 'healthy' => true,
            'takes_replays' => false, 'created_at' => '2026-11-17 12:00:00.000000',
        ]);
        $this->send()->assertCreated();
        DB::table('replay')->update(['upload_mirror_id' => 'other']);

        $this->report([self::SHA])->assertOk();

        $this->assertSame('uploading', DB::table('replay')->value('state'));
    }

    public function test_another_sender_waits_while_the_first_is_uploading(): void
    {
        $this->send()->assertCreated();

        $until = CarbonImmutable::parse(DB::table('replay')->value('reserved_until'), 'UTC')->getTimestamp();

        $this->send('bob')->assertStatus(409)
            ->assertJsonPath('reason', 'uploading')
            ->assertJsonPath('reserved_until', $until);

        $this->assertSame([self::ALICE], DB::table('replay_sender')->pluck('user_id')->all());
    }

    public function test_the_same_sender_asking_again_gets_a_new_token_for_the_same_upload(): void
    {
        $first = $this->send()->assertCreated()->json('upload.id');

        $this->send()->assertOk()->assertJsonPath('upload.id', $first);
        $other = $this->send('alice', ['sha256' => str_repeat('d', 64)])->assertOk()->json('upload.id');

        $this->assertNotSame($first, $other);
        $this->assertSame(str_repeat('d', 64), DB::table('replay')->value('sha256'));
        $this->assertSame(1, DB::table('replay_sender')->count());
    }

    public function test_an_unfinished_upload_lapses_and_frees_the_match_for_another_sender(): void
    {
        $first = $this->send()->assertCreated()->json('upload.id');
        DB::table('replay')->update(['reserved_until' => '2020-01-01 00:00:00.000000']);

        $upload = $this->send('bob', ['sha256' => str_repeat('b', 64)])->assertCreated()->json('upload');

        $this->assertNotSame($first, $upload['id']);
        $row = DB::table('replay')->first();
        $this->assertSame(self::BOB, $row->uploader);
        $this->assertSame(str_repeat('b', 64), $row->sha256);
        $this->assertSame([self::BOB], DB::table('replay_sender')->pluck('user_id')->all());
        $this->assertSame(str_repeat('b', 64), self::tokenPayload($upload['token'])['sha256']);
    }

    public function test_a_lapsed_upload_nobody_took_still_finishes_when_the_mirror_reports_it(): void
    {
        $this->send()->assertCreated();
        DB::table('replay')->update(['reserved_until' => '2020-01-01 00:00:00.000000']);

        $this->report([self::SHA])->assertOk();

        $this->assertSame('checking', DB::table('replay')->value('state'));
    }

    public function test_the_sender_must_be_in_the_summary_as_a_player_or_spectator(): void
    {
        $this->send('alice', ['name' => 'Carol'])->assertCreated();
        DB::table('replay')->delete();

        $this->send('alice', ['name' => 'Mallory'])->assertStatus(422)
            ->assertJsonPath('reason', 'not_in_replay')
            ->assertJsonPath('error', 'Nobody called "Mallory" played or watched this match.');
        $this->assertSame(0, DB::table('replay')->count());
    }

    public function test_only_mirrors_that_take_replays_are_given_uploads(): void
    {
        DB::table('mirror')->update(['takes_replays' => false]);

        $this->send()->assertStatus(503)->assertJsonPath('reason', 'no_replay_mirror');
        $this->assertSame(0, DB::table('replay')->count());
    }

    public function test_a_report_sets_takes_replays_from_its_kinds(): void
    {
        $this->report([], ['map', 'game'])->assertOk();
        $this->assertFalse((bool) DB::table('mirror')->value('takes_replays'));

        $this->report([], ['map', 'game', 'engine', 'replay', 'track'])->assertOk();
        $this->assertTrue((bool) DB::table('mirror')->value('takes_replays'));
    }

    public function test_signed_out_senders_are_refused(): void
    {
        $this->call('POST', '/api/v1/replays/uploads', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(401);
    }

    public function test_bad_requests_say_what_is_wrong(): void
    {
        $this->call('POST', '/api/v1/replays/uploads', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer alice', 'CONTENT_TYPE' => 'application/json'], 'nope')
            ->assertStatus(400)->assertJsonPath('error', 'The request body must be JSON.');
        $this->send('alice', ['game_id' => 'ABC'])->assertStatus(400)->assertJsonPath('reason', 'bad_request');

        $summary = self::summary();
        $summary['build_orders'][0]['orders'][0]['unit_def_id'] = 99;
        $this->send('alice', ['summary' => $summary])->assertStatus(400)
            ->assertJsonPath('error', 'summary.build_orders[0].orders[0] names unit_def_id 99, which summary.units does not list.');

        $this->assertSame(0, DB::table('replay')->count());
    }

    public function test_the_upload_route_answers_a_preflight(): void
    {
        $this->call('OPTIONS', '/api/v1/replays/uploads')->assertNoContent();
    }

    private function ask(string $token = 'alice', string $gameId = self::GAME_ID): TestResponse
    {
        return $this->call('GET', '/api/v1/replays/'.$gameId.'/uploads/status', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    /** @return array<string, mixed> */
    private static function answer(string $state, array $over = []): array
    {
        return ['replay' => $over + ['game_id' => self::GAME_ID, 'state' => $state, 'url' => null, 'reserved_until' => null, 'refusal' => null]];
    }

    public function test_status_says_a_reserved_replay_is_uploading_without_renewing_it(): void
    {
        $this->send()->assertCreated();
        $until = CarbonImmutable::now('UTC')->addMinute();
        DB::table('replay')->update(['reserved_until' => $until->format('Y-m-d H:i:s.u')]);
        $before = [DB::table('replay')->get()->all(), DB::table('replay_sender')->get()->all()];

        $this->ask()->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(self::answer('uploading', ['reserved_until' => $until->getTimestamp()]));

        $this->assertEquals($before, [DB::table('replay')->get()->all(), DB::table('replay_sender')->get()->all()]);
    }

    public function test_status_says_an_unfinished_upload_lapsed_without_reserving_it_again(): void
    {
        $this->send()->assertCreated();
        DB::table('replay')->update(['reserved_until' => '2020-01-01 00:00:00.000000']);

        $this->ask()->assertOk()->assertExactJson(self::answer('lapsed', ['reserved_until' => 1577836800]));

        $this->assertSame('2020-01-01 00:00:00.000000', DB::table('replay')->value('reserved_until'));
    }

    public function test_status_says_a_sender_whose_lapsed_match_was_taken_did_not_send_it(): void
    {
        $this->send()->assertCreated();
        DB::table('replay')->update(['reserved_until' => '2020-01-01 00:00:00.000000']);
        $this->send('bob')->assertCreated();

        $this->ask()->assertNotFound()->assertJsonPath('reason', 'not_sent');
    }

    public function test_status_follows_a_sent_replay_through_its_check(): void
    {
        $this->send()->assertCreated();
        $this->report([self::SHA]);
        $this->send('bob')->assertOk();

        $this->ask()->assertOk()->assertExactJson(self::answer('checking'));
        $this->ask('bob')->assertOk()->assertExactJson(self::answer('checking'));

        DB::table('replay')->update(['state' => 'listed']);

        $this->ask()->assertOk()->assertExactJson(self::answer('listed', ['url' => 'https://hub.test/replays/'.self::GAME_ID]));
    }

    public function test_status_gives_every_sender_of_a_refused_replay_a_readable_reason(): void
    {
        $this->send()->assertCreated();
        $this->report([self::SHA]);
        $this->send('bob')->assertOk();
        ReplayChecks::failed(self::GAME_ID, 'cURL error 7 at https://mirror.test');
        $this->assertDatabaseCount('replay', 0);

        foreach (['alice', 'bob'] as $sender) {
            $response = $this->ask($sender)->assertOk()->assertExactJson(self::answer('refused', ['refusal' => [
                'reason' => 'check_failed',
                'message' => 'The hub could not finish checking the replay. You can send it again.',
                'refused_at' => CarbonImmutable::parse(DB::table('replay_refusal')->value('refused_at'), 'UTC')->getTimestamp(),
            ]]));
            $this->assertStringNotContainsString('cURL', $response->getContent());
        }
        $this->assertDatabaseCount('replay', 0);
    }

    public function test_status_keeps_a_senders_refusal_while_somebody_else_sends_the_match(): void
    {
        $this->send()->assertCreated();
        $this->report([self::SHA]);
        ReplayChecks::failed(self::GAME_ID, 'no answer');
        $this->send('bob')->assertCreated();

        $this->ask()->assertOk()->assertJsonPath('replay.state', 'refused');
        $this->ask('bob')->assertOk()->assertJsonPath('replay.state', 'uploading');

        $this->report([self::SHA]);
        DB::table('replay')->update(['state' => 'listed']);

        $this->ask()->assertOk()->assertJsonPath('replay.state', 'refused');
    }

    public function test_status_follows_a_new_send_after_a_refusal(): void
    {
        $this->send()->assertCreated();
        $this->report([self::SHA]);
        ReplayChecks::failed(self::GAME_ID, 'no answer');
        $this->send()->assertCreated();

        $this->ask()->assertOk()->assertJsonPath('replay.state', 'uploading')->assertJsonPath('replay.refusal', null);
    }

    public function test_status_reads_a_refusal_kept_before_later_senders_were(): void
    {
        DB::table('replay_refusal')->insert([
            'game_id' => self::GAME_ID, 'sha256' => self::SHA, 'uploader' => self::ALICE, 'reason' => 'summary_mismatch',
            'detail' => 'secret detail', 'summary' => '{}', 'refused_at' => '2026-11-26 09:00:00.000000',
        ]);

        $this->ask()->assertOk()
            ->assertJsonPath('replay.refusal.reason', 'summary_mismatch')
            ->assertJsonPath('replay.refusal.message', 'What was sent about the match does not agree with the replay file.');
        $this->ask('bob')->assertNotFound();
    }

    public function test_status_tells_somebody_who_never_sent_a_match_only_whether_it_is_listed(): void
    {
        $this->ask('bob')->assertNotFound()->assertExactJson(['error' => 'You have not sent the hub this match.', 'reason' => 'not_sent']);

        $this->send()->assertCreated();
        $this->ask('bob')->assertNotFound();
        $this->report([self::SHA]);
        $this->ask('bob')->assertNotFound();
        DB::table('replay')->update(['state' => 'removed']);
        $this->ask('bob')->assertNotFound();
        $this->ask()->assertOk()->assertExactJson(self::answer('removed'));

        DB::table('replay')->update(['state' => 'listed']);
        $this->ask('bob')->assertOk()->assertJsonPath('replay.state', 'listed');
        $this->assertSame(1, DB::table('replay_sender')->count());
    }

    public function test_signed_out_senders_cannot_ask_for_a_status(): void
    {
        $this->call('GET', '/api/v1/replays/'.self::GAME_ID.'/uploads/status')->assertStatus(401);
        $this->call('OPTIONS', '/api/v1/replays/'.self::GAME_ID.'/uploads/status')->assertNoContent();
    }
}
