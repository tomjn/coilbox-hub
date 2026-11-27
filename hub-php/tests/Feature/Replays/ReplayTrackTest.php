<?php

namespace Tests\Feature\Replays;

use App\Domain\Mirrors\B64;
use App\Domain\Mirrors\Signing;
use App\Domain\Replays\ReplayListing;
use App\Domain\Replays\TrackChecks;
use App\Jobs\CheckReplay;
use App\Jobs\CheckReplayAnalysis;
use App\Jobs\CheckReplayTrack;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Moderation\ModerationTestCase;
use Tests\Unit\Mirrors\MirrorFixtures;

/**
 * Replay tracks from Coilbox (#665). `sf-tumult.cbtrack` is the track Coilbox
 * recorded from `sf-tumult.sdfz` (recorder version 5).
 * `tumult-scratch.cbtrack` is its recording of another match on the same map,
 * the short scratch match Coilbox makes to test its player.
 */
class ReplayTrackTest extends ModerationTestCase
{
    use MirrorFixtures;
    use RefreshDatabase;

    private const ALICE = '11111111-1111-1111-1111-111111111111';

    private const BOB = '22222222-2222-2222-2222-222222222222';

    private const SF = '9cf5d76a4468b019403831ac99faf0f7';

    /** What the mirror gives for the track. */
    private string $served;

    private int $status = 200;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.games_store' => 'mariadb', 'hub.maps_store' => 'mariadb', 'hub.pictures_store' => 'mariadb', 'hub.mirrors_store' => 'mariadb',
            'hub.mirrors.secret_key' => self::fixtureKeys()['hub_secret_key'], 'hub.mirrors.next_secret_key' => '',
            'hub.mirrors.reservation' => 86400, 'app.url' => 'https://hub.test',
        ]);
        Queue::fake();
        $replay = self::replayFile('sf-tumult.sdfz');
        $this->served = self::replayFile('sf-tumult.cbtrack');
        DB::table('mirror')->insert([
            'id' => 'jj-darkstars', 'name' => 'jj.darkstars.co.uk', 'base_url' => 'https://mirror.test',
            'public_key' => self::fixtureKeys()['mirror_public_key'], 'enabled' => true, 'healthy' => true,
            'takes_replays' => true, 'takes_tracks' => true, 'created_at' => '2026-11-17 12:00:00.000000',
        ]);
        DB::table('replay')->insert([
            'game_id' => self::SF, 'state' => 'checking', 'uploader' => self::ALICE, 'sha256' => hash('sha256', $replay),
            'size' => strlen($replay), 'upload_mirror_id' => 'jj-darkstars', 'upload_id' => '33333333-3333-4333-8333-333333333333',
            'summary' => self::replayFile('sf-tumult.summary.json'), 'reserved_until' => null,
            'received_at' => '2026-11-26 09:00:00.000000', 'created_at' => '2026-11-26 09:00:00.000000', 'updated_at' => '2026-11-26 09:00:00.000000',
        ]);
        DB::table('replay_sender')->insert(['game_id' => self::SF, 'user_id' => self::ALICE, 'name' => 'Player', 'sent_at' => '2026-11-26 09:00:00.000000']);
        // Each bearer token is one account: `alice` and `bob`.
        $this->supabase([
            '/auth/v1/user' => fn (Request $r) => self::reply(['id' => $r->header('Authorization')[0] === 'Bearer bob' ? self::BOB : self::ALICE]),
            'mirror.test/replays/' => Http::response($replay),
            'mirror.test/tracks/' => fn () => Http::response($this->served, $this->status),
            'mirror.test/' => Http::response(''),
        ]);
        (new CheckReplay(self::SF))->handle();
        $this->assertSame('listed', DB::table('replay')->where('game_id', self::SF)->value('state'));
    }

    private static function replayFile(string $name): string
    {
        return file_get_contents(base_path('tests/Fixtures/replays/'.$name));
    }

    /** A copy of a track with header bytes written over at `$offset`. */
    private static function patched(string $track, int $offset, string $bytes): string
    {
        return substr_replace($track, $bytes, $offset, strlen($bytes));
    }

    private function analysed(): void
    {
        $id = $this->call('POST', '/api/v1/replays/'.self::SF.'/analyses', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer alice', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['logger_version' => 6, 'events' => self::replayFile('sf-tumult.events.jsonl')]))->assertStatus(202)->json('analysis.id');
        (new CheckReplayAnalysis($id))->handle();
        $this->assertDatabaseHas('replay_analysis', ['game_id' => self::SF]);
    }

    /** @param array<string, mixed> $over */
    private function send(string $token = 'alice', array $over = [], string $gameId = self::SF): TestResponse
    {
        return $this->call('POST', '/api/v1/replays/'.$gameId.'/tracks/uploads', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'],
            json_encode($over + ['format_version' => 1, 'sha256' => hash('sha256', $this->served), 'size' => strlen($this->served)]));
    }

    /**
     * @param  list<string>  $held
     * @param  list<string>  $features
     */
    private function report(array $held, array $kinds = ['map', 'game', 'engine', 'replay', 'track'], array $features = ['replace_track']): TestResponse
    {
        $payload = json_encode([
            'v' => 1, 'mirror' => 'jj-darkstars', 'event' => 'sync', 'upload' => null, 'sent_at' => 1794787260,
            'catalogue_seq' => null, 'catalogue_generated_at' => null, 'held' => $held, 'unknown' => [], 'kinds' => $kinds, 'features' => $features,
        ]);
        $sig = Signing::sign(Signing::CTX_REPORT, $payload, B64::decode(self::fixtureKeys()['mirror_secret_key']));

        return $this->call('POST', '/api/v1/mirrors/report', [], [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['payload' => B64::encode($payload), 'sig' => B64::encode($sig)]));
    }

    /** Sends the served file, has the mirror report it and runs its check. */
    private function checked(string $token = 'alice'): ?string
    {
        $this->send($token)->assertCreated();
        $this->report([hash('sha256', $this->served)])->assertOk();

        return TrackChecks::check(self::SF, 1);
    }

    private function catalogue(): array
    {
        return json_decode($this->get('/api/v1/catalogue')->assertOk()->getContent(), true);
    }

    public function test_the_replay_check_keeps_each_team_leaders_name(): void
    {
        $this->assertSame(['Player', 'Player'], DB::table('replay_team')->orderBy('team')->pluck('leader_name')->all());
    }

    public function test_the_track_recorded_from_a_replay_is_uploaded_checked_and_listed(): void
    {
        $this->analysed();
        $sha = hash('sha256', $this->served);

        $upload = $this->send('bob')->assertCreated()
            ->assertJsonPath('upload.format_version', 1)
            ->assertJsonPath('upload.filename', self::SF.'.cbtrack')
            ->assertJsonPath('upload.upload_url', 'https://mirror.test/upload.php')
            ->json('upload');
        $token = json_decode(B64::decode(explode('.', $upload['token'])[0]), true);
        $this->assertSame(['track', self::SF.'.cbtrack', $sha, $upload['id']], [$token['kind'], $token['filename'], $token['sha256'], $token['upload']]);
        $this->assertArrayNotHasKey('tracks', $this->catalogue());

        $this->report([$sha])->assertOk();
        Queue::assertPushed(CheckReplayTrack::class, fn ($job) => $job->gameId === self::SF && $job->formatVersion === 1);
        (new CheckReplayTrack(self::SF, 1))->handle();

        $url = 'https://mirror.test/tracks/'.self::SF.'.cbtrack';
        $this->get('/api/v1/replays/'.self::SF.'/tracks')->assertOk()->assertExactJson(['tracks' => [[
            'game_id' => self::SF, 'format_version' => 1, 'state' => 'listed', 'recorder_version' => 5,
            'size' => strlen($this->served), 'sha256' => $sha, 'url' => $url,
        ]]]);
        $this->assertSame([['filename' => self::SF.'.cbtrack', 'sha256' => $sha, 'size' => strlen($this->served)]], $this->catalogue()['tracks']);
        $this->assertDatabaseCount('replay_track_refusal', 0);
    }

    public function test_a_second_sender_uploads_nothing(): void
    {
        $this->analysed();
        $this->send('alice')->assertCreated();

        $this->report([hash('sha256', $this->served)])->assertOk();
        $this->send('bob')->assertOk()->assertJsonPath('track.state', 'checking')->assertJsonPath('track.url', null)->assertJsonMissingPath('upload');

        TrackChecks::check(self::SF, 1);
        $this->send('bob', ['sha256' => str_repeat('a', 64)])->assertOk()
            ->assertJsonPath('track.state', 'listed')
            ->assertJsonPath('track.url', 'https://mirror.test/tracks/'.self::SF.'.cbtrack')
            ->assertJsonMissingPath('upload');
        $this->assertSame([hash('sha256', $this->served)], DB::table('replay_track')->pluck('sha256')->all());
    }

    public function test_a_track_from_another_match_is_refused_removed_and_can_be_sent_again(): void
    {
        $this->analysed();
        $real = $this->served;
        $this->served = self::replayFile('tumult-scratch.cbtrack');

        $this->assertSame('wrong_game_id', $this->checked('alice'));

        $refusal = DB::table('replay_track_refusal')->first();
        $this->assertSame([self::SF, self::ALICE, 'The track was recorded from game 6c1fe96a543c9df9ce3146e8418dff27.'], [$refusal->game_id, $refusal->uploader, $refusal->detail]);
        $this->assertDatabaseCount('replay_track', 0);
        $catalogue = $this->catalogue();
        $this->assertArrayNotHasKey('tracks', $catalogue);
        $this->assertSame([[self::SF.'.cbtrack.removed', hash('sha256', $this->served), 'refused']],
            array_map(fn ($r) => [$r['filename'], $r['sha256'], $r['reason']], $catalogue['removed']));

        $this->served = $real;
        $this->assertSame('listed', $this->checked('bob'));
    }

    public function test_the_other_match_is_refused_on_its_length_even_under_this_game_id(): void
    {
        $this->analysed();
        $this->served = self::patched(self::replayFile('tumult-scratch.cbtrack'), 64, hex2bin(self::SF));

        $this->assertSame('length_mismatch', $this->checked());
        $this->assertStringContainsString('1100 frames', DB::table('replay_track_refusal')->value('detail'));
    }

    /** The replay lasted 501 seconds. @return array<string, array{int, string}> */
    public static function lastFrames(): array
    {
        return [
            'a frame short of 501 s' => [15029, 'length_mismatch'],
            'the first frame of 501 s' => [15030, 'listed'],
            'one block past the last frame of 501 s' => [15060, 'listed'],
            'two frames past it' => [15061, 'length_mismatch'],
        ];
    }

    #[DataProvider('lastFrames')]
    public function test_the_tracks_frames_must_come_to_the_replays_length(int $last, string $expected): void
    {
        $this->analysed();
        $this->served = self::patched($this->served, 16, pack('V', $last));

        $this->assertSame($expected, $this->checked());
    }

    /** @return array<string, array{array<string, mixed>, int, string}> */
    public static function otherTeams(): array
    {
        return [
            'another leader' => [['leader_name' => 'Somebody'], 1, 'Team 1\'s leader is "Player" in the track and "Somebody" in the replay.'],
            'another ally team' => [['ally_team' => 0], 1, 'Team 1 is on ally team 1 in the track and 0 in the replay.'],
            'a team the track lacks' => [['team' => 7], 1, 'The track has no team 7.'],
        ];
    }

    #[DataProvider('otherTeams')]
    public function test_the_tracks_teams_must_be_the_replays(array $change, int $team, string $detail): void
    {
        $this->analysed();
        DB::table('replay_team')->where('team', $team)->update($change);

        $this->assertSame('team_mismatch', $this->checked());
        $this->assertSame($detail, DB::table('replay_track_refusal')->value('detail'));
    }

    public function test_a_team_the_replay_lacks_is_refused(): void
    {
        $this->analysed();
        DB::table('replay_team')->where('team', 0)->delete();

        $this->assertSame('team_mismatch', $this->checked());
        $this->assertSame('The track has a team 0 that the replay does not.', DB::table('replay_track_refusal')->value('detail'));
    }

    public function test_a_replay_checked_before_leader_names_were_kept_is_matched_on_its_teams(): void
    {
        $this->analysed();
        DB::table('replay_team')->update(['leader_name' => null]);

        $this->assertSame('listed', $this->checked());
    }

    /** @return array<string, array{string, string}> */
    public static function badFiles(): array
    {
        $track = file_get_contents(__DIR__.'/../../Fixtures/replays/sf-tumult.cbtrack');

        return [
            'not a track' => [str_repeat('x', 200), 'unreadable'],
            'cut inside the header' => [substr($track, 0, 50), 'unreadable'],
            'cut before the info chunk' => [substr($track, 0, 200), 'unreadable'],
            'a damaged info chunk' => [self::patched($track, 6 * 64, 'nonsense'), 'unreadable'],
            'an info chunk too long' => [self::patched($track, 40, pack('V', 16777216)), 'unreadable'],
            'another format version' => [self::patched($track, 4, pack('v', 2)), 'unknown_format'],
        ];
    }

    #[DataProvider('badFiles')]
    public function test_a_file_the_hub_cannot_read_is_refused(string $file, string $reason): void
    {
        $this->analysed();
        $this->served = $file;

        $this->assertSame($reason, $this->checked());
    }

    public function test_a_mirror_giving_another_file_gets_the_track_refused(): void
    {
        $this->analysed();
        $this->send()->assertCreated();
        $this->report([hash('sha256', $this->served)])->assertOk();
        $this->served = self::replayFile('tumult-scratch.cbtrack');

        $this->assertSame('wrong_file', TrackChecks::check(self::SF, 1));
    }

    public function test_a_mirror_that_cannot_give_the_file_is_tried_again_then_the_track_is_refused(): void
    {
        $this->analysed();
        $this->send()->assertCreated();
        $this->report([hash('sha256', $this->served)])->assertOk();
        $this->status = 500;
        $job = new CheckReplayTrack(self::SF, 1);

        try {
            $job->handle();
            $this->fail('The check should throw, so the queue tries again.');
        } catch (RuntimeException $e) {
            $job->failed($e);
        }

        $this->assertSame('check_failed', DB::table('replay_track_refusal')->value('reason'));
        $this->assertDatabaseCount('replay_track', 0);
        $this->assertSame([80, 3, [60, 540]], [$job->timeout, $job->tries, $job->backoff()]);
    }

    public function test_a_track_waits_for_a_passing_analysis(): void
    {
        $this->send()->assertStatus(409)->assertJsonPath('reason', 'no_analysis');
        $this->assertDatabaseCount('replay_track', 0);
    }

    public function test_a_track_needs_a_listed_replay(): void
    {
        $this->send(gameId: str_repeat('0', 32))->assertNotFound()->assertJsonPath('reason', 'not_listed');
        $this->get('/api/v1/replays/'.str_repeat('0', 32).'/tracks')->assertNotFound()->assertJsonPath('reason', 'not_listed');
        $this->get('/api/v1/replays/'.self::SF.'/tracks')->assertOk()->assertExactJson(['tracks' => []]);
    }

    public function test_bad_requests_and_signed_out_senders_are_turned_away(): void
    {
        $this->analysed();

        $this->postJson('/api/v1/replays/'.self::SF.'/tracks/uploads', [])->assertUnauthorized();
        foreach ([['format_version' => '1'], ['sha256' => 'abc'], ['size' => 0], ['recorder_version' => 0], ['recorder_version' => '6']] as $over) {
            $this->send(over: $over)->assertStatus(400)->assertJsonPath('reason', 'bad_request');
        }
        $this->send(over: ['format_version' => 2])->assertStatus(422)->assertJsonPath('reason', 'unknown_format');
        $this->assertDatabaseCount('replay_track', 0);
    }

    public function test_only_a_mirror_whose_report_takes_tracks_gets_them(): void
    {
        $this->analysed();
        $this->report([], ['map', 'game', 'engine', 'replay'])->assertOk();
        $this->send()->assertStatus(503)->assertJsonPath('reason', 'no_track_mirror');

        $this->report([])->assertOk();
        $this->send()->assertCreated();
    }

    public function test_a_reservation_holds_until_it_lapses(): void
    {
        $this->analysed();
        $first = $this->send('alice')->assertCreated()->json('upload');

        $this->send('bob')->assertStatus(409)->assertJsonPath('reason', 'uploading')->assertJsonPath('reserved_until', $first['reserved_until']);
        $this->assertSame($first['id'], $this->send('alice')->assertOk()->json('upload.id'));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(86401));
        $this->assertNotSame($first['id'], $this->send('bob')->assertCreated()->json('upload.id'));
        $this->assertSame([self::BOB], DB::table('replay_track')->pluck('uploader')->all());
    }

    public function test_removing_a_replay_removes_its_track_from_the_mirrors(): void
    {
        $this->analysed();
        $this->checked();
        $track = hash('sha256', $this->served);

        ReplayListing::remove(self::SF, 'rights');

        $catalogue = $this->catalogue();
        $this->assertArrayNotHasKey('tracks', $catalogue);
        $this->assertContains(self::SF.'.cbtrack.removed', array_column($catalogue['removed'], 'filename'));
        $this->assertContains($track, array_column($catalogue['removed'], 'sha256'));
        $this->assertSame('removed', DB::table('replay_track')->value('state'));
    }

    public function test_there_is_no_download_link_while_the_tracks_mirror_is_unhealthy(): void
    {
        $this->analysed();
        $this->checked();
        DB::table('mirror')->update(['healthy' => false]);

        $this->get('/api/v1/replays/'.self::SF.'/tracks')->assertJsonPath('tracks.0.url', null);
    }

    /** Lists the recorder version 5 track, then serves the same track as if another recorder version made it. */
    private function listedThenRecordedBy(int $recorder): string
    {
        $this->analysed();
        $this->assertSame('listed', $this->checked());
        $old = hash('sha256', $this->served);
        $this->served = self::patched($this->served, 8, pack('v', $recorder));

        return $old;
    }

    /** @param array<string, mixed> $over */
    private function sendServed(string $token, array $over = []): TestResponse
    {
        return $this->send($token, $over + ['sha256' => hash('sha256', $this->served), 'size' => strlen($this->served)]);
    }

    public function test_a_track_from_a_newer_recorder_replaces_the_listed_one(): void
    {
        $old = $this->listedThenRecordedBy(6);
        $new = hash('sha256', $this->served);
        $url = 'https://mirror.test/tracks/'.self::SF.'.cbtrack';

        $upload = $this->sendServed('bob', ['recorder_version' => 6])->assertCreated()->assertJsonPath('upload.filename', self::SF.'.cbtrack')->json('upload');
        $token = json_decode(B64::decode(explode('.', $upload['token'])[0]), true);
        $this->assertSame([$new, $old], [$token['sha256'], $token['replaces']]);
        $this->get('/api/v1/replays/'.self::SF.'/tracks')->assertJsonPath('tracks.0.sha256', $old)->assertJsonPath('tracks.0.url', $url);

        // The mirror now serves the new file under the listed one's name.
        $this->report([$new])->assertOk();
        $this->get('/api/v1/replays/'.self::SF.'/tracks')->assertJsonPath('tracks.0.sha256', $old)->assertJsonPath('tracks.0.url', null);
        $this->sendServed('alice', ['recorder_version' => 6])->assertOk()->assertJsonPath('track.state', 'checking');

        $this->assertSame('listed', TrackChecks::check(self::SF, 1));
        $this->get('/api/v1/replays/'.self::SF.'/tracks')->assertOk()->assertExactJson(['tracks' => [[
            'game_id' => self::SF, 'format_version' => 1, 'state' => 'listed', 'recorder_version' => 6,
            'size' => strlen($this->served), 'sha256' => $new, 'url' => $url,
        ]]]);
        $catalogue = $this->catalogue();
        $this->assertSame([['filename' => self::SF.'.cbtrack', 'sha256' => $new, 'size' => strlen($this->served)]], $catalogue['tracks']);
        $this->assertSame([[self::SF.'.cbtrack.removed', $old, 'replaced']], array_map(fn ($r) => [$r['filename'], $r['sha256'], $r['reason']], $catalogue['removed']));
        $this->assertSame([self::BOB], DB::table('replay_track')->pluck('uploader')->all());
    }

    /** @return array<string, array{?int}> */
    public static function notNewer(): array
    {
        return ['the same recorder' => [5], 'an older recorder' => [4], 'no recorder version sent' => [null]];
    }

    #[DataProvider('notNewer')]
    public function test_a_track_from_the_same_or_an_older_recorder_uploads_nothing(?int $recorder): void
    {
        $old = $this->listedThenRecordedBy(6);

        $this->sendServed('bob', ['recorder_version' => $recorder])->assertOk()
            ->assertJsonPath('track.sha256', $old)->assertJsonPath('track.recorder_version', 5)->assertJsonMissingPath('upload');
        $this->assertDatabaseCount('replay_track', 1);
        $this->assertSame([], $this->catalogue()['removed']);
    }

    public function test_a_newer_track_uploads_nothing_until_a_mirror_can_replace_one(): void
    {
        $old = $this->listedThenRecordedBy(6);
        $this->report([$old], features: [])->assertOk();

        $this->sendServed('bob', ['recorder_version' => 6])->assertOk()->assertJsonPath('track.sha256', $old)->assertJsonMissingPath('upload');
        $this->assertDatabaseCount('replay_track', 1);

        $this->report([$old])->assertOk();
        $this->sendServed('bob', ['recorder_version' => 6])->assertCreated();
    }

    public function test_a_replacement_that_is_not_newer_is_refused_and_the_listed_track_kept(): void
    {
        $old = $this->listedThenRecordedBy(4);
        $refused = hash('sha256', $this->served);

        // Coilbox said 6, but the file says 4.
        $this->sendServed('bob', ['recorder_version' => 6])->assertCreated();
        $this->report([$refused])->assertOk();
        $this->assertSame('not_newer', TrackChecks::check(self::SF, 1));

        $this->assertSame('The track is from recorder version 4, and the listed one is from 5.', DB::table('replay_track_refusal')->value('detail'));
        $this->get('/api/v1/replays/'.self::SF.'/tracks')->assertJsonPath('tracks.0.sha256', $old)->assertJsonPath('tracks.0.recorder_version', 5);
        $catalogue = $this->catalogue();
        $this->assertSame($old, $catalogue['tracks'][0]['sha256']);
        $this->assertSame([[$refused, 'refused']], array_map(fn ($r) => [$r['sha256'], $r['reason']], $catalogue['removed']));
    }

    public function test_removing_a_replay_while_its_track_is_replaced_removes_both_files(): void
    {
        $old = $this->listedThenRecordedBy(6);
        $new = hash('sha256', $this->served);
        $this->sendServed('bob', ['recorder_version' => 6])->assertCreated();
        $this->report([$new])->assertOk();

        ReplayListing::remove(self::SF, 'rights');

        $tracks = array_filter($this->catalogue()['removed'], fn ($r) => $r['filename'] === self::SF.'.cbtrack.removed');
        $this->assertEqualsCanonicalizing([$old, $new], array_column($tracks, 'sha256'));
        $this->assertSame(['removed', 'removed'], DB::table('replay_track')->pluck('state')->all());
        $this->assertNull(TrackChecks::check(self::SF, 1));
    }
}
