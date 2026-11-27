<?php

namespace Tests\Feature\Replays;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ChecksReplays;
use Tests\Feature\Moderation\ModerationTestCase;

/**
 * Reporting a shared replay, taking it down and hiding its chat (#671), over
 * a real Beyond All Reason replay put through the real check.
 */
class ReplayModerationTest extends ModerationTestCase
{
    use ChecksReplays;
    use RefreshDatabase;

    private const OWNER = 'aaaaaaaa-0000-4000-8000-00000000000a';

    private const TRACK_SHA = 'feedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedface';

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
        Queue::fake();
        $this->uploaded(self::BAR, 'bar-tangerine', self::summary('bar-tangerine'), 'Charlson');
        $this->check(self::BAR);
        $this->assertSame('listed', DB::table('replay')->where('game_id', self::BAR)->value('state'));

        foreach ([self::OWNER => 'Owner', self::MODERATOR => 'Moddy'] as $id => $name) {
            DB::table('profile')->insert(['user_id' => $id, 'display_name' => $name, 'created_at' => '2026-10-01 10:00:00.000000', 'updated_at' => '2026-10-01 10:00:00.000000']);
        }
        DB::table('user_capability')->insert(['user_id' => self::MODERATOR, 'capability' => 'can_moderate', 'granted_at' => '2026-10-01 10:00:00.000000']);
        // Supabase only says who each token belongs to. Everything else is in MariaDB.
        $this->supabase(['/auth/v1/user' => fn (Request $r) => self::reply([
            'id' => match ($r->header('Authorization')[0]) {
                'Bearer mod' => self::MODERATOR, 'Bearer owner' => self::OWNER, default => self::USER,
            },
            'factors' => [], 'email' => 'x@example.test', 'user_metadata' => [],
        ])]);
    }

    private function as(string $userId, string $token): static
    {
        return $this->withSession(['supabase' => [
            'access_token' => $token, 'refresh_token' => 'refresh', 'expires_at' => time() + 3600, 'user_id' => $userId, 'aal' => 'aal1',
        ]]);
    }

    private function owner(): static
    {
        return $this->as(self::OWNER, 'owner');
    }

    private function moderator(): static
    {
        return $this->as(self::MODERATOR, 'mod');
    }

    /** A listed track and a kept analysis, as their checks leave them. */
    private function trackAndAnalysis(): void
    {
        $now = '2026-11-26 10:00:00.000000';
        DB::table('replay_track')->insert([
            'game_id' => self::BAR, 'format_version' => 1, 'state' => 'listed', 'uploader' => self::USER, 'sha256' => self::TRACK_SHA,
            'size' => 100, 'upload_mirror_id' => 'jj-darkstars', 'upload_id' => '44444444-4444-4444-8444-444444444444',
            'recorder_version' => 1, 'created_at' => $now, 'updated_at' => $now, 'checked_at' => $now,
        ]);
        $submission = DB::table('replay_analysis_submission')->insertGetId([
            'game_id' => self::BAR, 'user_id' => self::USER, 'logger_version' => 1, 'sha256' => str_repeat('b', 64), 'size' => 2,
            'state' => 'kept', 'sent_at' => $now, 'checked_at' => $now,
        ]);
        DB::table('replay_analysis')->insert([
            'game_id' => self::BAR, 'logger_version' => 1, 'submission_id' => $submission, 'sha256' => str_repeat('b', 64),
            'events' => '{}', 'counts' => '{}', 'kept_at' => $now,
        ]);
    }

    public function test_a_signed_in_account_reports_a_replay_once_and_a_signed_out_visitor_is_asked_to_sign_in(): void
    {
        $this->get('/replays/'.self::BAR)->assertOk()->assertSee('to report this replay')->assertDontSee('Send report');
        $this->post('/replays/'.self::BAR.'/report', ['reason' => 'My name is in it'])
            ->assertRedirect('/replays/'.self::BAR.'#replay-report')->assertSessionHas('replayReport', ['ok' => false, 'message' => 'Sign in to report a replay.']);
        $this->assertSame(0, DB::table('report')->count());

        $this->owner()->get('/replays/'.self::BAR)->assertOk()->assertSee('Report this replay')->assertDontSee('Remove the replay');
        $this->owner()->post('/replays/'.self::BAR.'/report', ['reason' => '  '])
            ->assertSessionHas('replayReport', ['ok' => false, 'message' => 'Say what is wrong, in a sentence or two.']);
        $this->owner()->post('/replays/'.self::BAR.'/report', ['reason' => 'I played in this and want it taken down'])
            ->assertSessionHas('replayReport', ['ok' => true, 'message' => 'Reported. Thank you. A moderator will look at it.']);
        // An open report holds the replay, so a second is dropped.
        $this->owner()->post('/replays/'.self::BAR.'/report', ['reason' => 'Again'])->assertSessionHas('replayReport.ok', true);
        $this->assertSame([[self::BAR, self::OWNER, 'I played in this and want it taken down']],
            DB::table('report')->get()->map(fn ($r) => [$r->replay_id, $r->reporter_id, $r->reason])->all());

        $this->owner()->post('/replays/'.str_repeat('0', 32).'/report', ['reason' => 'Gone'])
            ->assertSessionHas('replayReport', ['ok' => false, 'message' => 'This replay is no longer on the hub.']);

        $this->moderator()->get('/moderation')->assertOk()
            ->assertSee('Replay on Tangerine_Remake 1.0')->assertSee('I played in this and want it taken down')->assertSee('Remove the replay');
    }

    public function test_a_moderator_removes_a_reported_replay_and_its_files_are_listed_as_removed(): void
    {
        $this->trackAndAnalysis();
        $sha = DB::table('replay')->where('game_id', self::BAR)->value('sha256');
        $this->assertGreaterThan(0, DB::table('replay_chat')->where('game_id', self::BAR)->count());
        $this->assertSame(1, DB::table('replay_map_share')->where('game_id', self::BAR)->count());
        $this->assertSame(1, DB::table('replay_game_share')->where('game_id', self::BAR)->count());
        $this->owner()->post('/replays/'.self::BAR.'/report', ['reason' => 'Take it down please']);
        $report = DB::table('report')->value('id');

        // Somebody who is not a moderator cannot.
        $this->owner()->post('/moderation/replays/remove', ['game_id' => self::BAR, 'reason' => 'privacy']);
        $this->assertSame('listed', DB::table('replay')->where('game_id', self::BAR)->value('state'));
        // A reason is needed.
        $this->moderator()->post('/moderation/replays/remove', ['game_id' => self::BAR, 'reason' => 'nope']);
        $this->assertSame('listed', DB::table('replay')->where('game_id', self::BAR)->value('state'));

        $this->moderator()->post('/moderation/replays/remove', ['game_id' => self::BAR, 'reason' => 'privacy'])
            ->assertRedirect('/moderation')->assertSessionHas('replayModeration.ok', true);

        $replay = DB::table('replay')->where('game_id', self::BAR)->first();
        $this->assertSame('removed', $replay->state);
        $this->assertSame(self::MODERATOR, $replay->removed_by);
        $this->assertSame('{}', $replay->summary);
        $this->assertNull($replay->events);
        foreach (['replay_player', 'replay_team', 'replay_chat', 'replay_analysis', 'replay_analysis_submission', 'replay_map_share', 'replay_game_share'] as $table) {
            $this->assertSame(0, DB::table($table)->where('game_id', self::BAR)->count(), $table);
        }
        // The senders stay, so each can still ask what became of it.
        $this->assertSame(1, DB::table('replay_sender')->where('game_id', self::BAR)->count());
        $this->assertSame('removed', DB::table('replay_track')->where('game_id', self::BAR)->value('state'));

        $handled = DB::table('report')->where('id', $report)->first();
        $this->assertSame(['upheld', self::MODERATOR], [$handled->outcome, $handled->handled_by]);
        $this->assertNotNull($handled->handled_at);

        $removed = $this->get('/api/v1/catalogue')->assertOk()->json('removed');
        $this->assertEqualsCanonicalizing(
            [[self::BAR.'.sdfz.removed', $sha, 'privacy'], [self::BAR.'.cbtrack.removed', self::TRACK_SHA, 'privacy']],
            array_map(fn ($r) => [$r['filename'], $r['sha256'], $r['reason']], $removed),
        );
        $catalogue = $this->get('/api/v1/catalogue')->json();
        $this->assertSame([], $catalogue['replays'] ?? []);
        $this->assertSame([], $catalogue['tracks'] ?? []);

        $this->get('/replays/'.self::BAR)->assertNotFound();
        $this->get('/replays')->assertOk()->assertDontSee('/replays/'.self::BAR);
        $this->moderator()->get('/moderation')->assertOk()->assertDontSee('Take it down please');

        // Removing again finds nothing to remove.
        $this->moderator()->post('/moderation/replays/remove', ['game_id' => self::BAR, 'reason' => 'privacy'])
            ->assertSessionHas('replayModeration', ['ok' => false, 'message' => 'That replay is not on the hub.']);
    }

    public function test_a_removed_match_and_its_file_cannot_be_sent_again(): void
    {
        $sha = DB::table('replay')->where('game_id', self::BAR)->value('sha256');
        $size = (int) DB::table('replay')->where('game_id', self::BAR)->value('size');
        $this->moderator()->post('/moderation/replays/remove', ['game_id' => self::BAR, 'reason' => 'harmful']);

        $send = fn (string $gameId) => $this->call('POST', '/api/v1/replays/uploads', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer sender', 'CONTENT_TYPE' => 'application/json'], json_encode([
            'game_id' => $gameId, 'sha256' => $sha, 'size' => $size, 'name' => 'Charlson', 'summary' => self::summary('bar-tangerine'),
        ]));
        $refused = ['error' => 'A moderator took this match down from the hub, so it cannot be sent again.', 'reason' => 'removed'];
        $send(self::BAR)->assertStatus(410)->assertExactJson($refused);
        $send(str_repeat('a', 32))->assertStatus(410)->assertExactJson($refused);
        $this->assertSame(1, DB::table('replay')->count());

        // Its sender still learns what became of it.
        $this->call('GET', '/api/v1/replays/'.self::BAR.'/uploads/status', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer sender'])
            ->assertOk()->assertJsonPath('replay.state', 'removed')->assertJsonPath('replay.url', null);
    }

    public function test_a_moderator_hides_one_chat_message_or_all_of_it(): void
    {
        $chat = DB::table('replay_chat')->where('game_id', self::BAR)->orderBy('seq')->get();
        $this->assertGreaterThan(1, $chat->count());
        $first = $chat->first();
        $events = '/replays/'.self::BAR.'?tab=events&show=chat';

        $this->moderator()->get($events)->assertOk()->assertSee($first->text)->assertSee('Hide all');
        // Somebody who is not a moderator sees no hide buttons and cannot hide.
        $this->owner()->get($events)->assertOk()->assertDontSee('this message')->assertDontSee('Hide all');
        $this->owner()->post('/moderation/replays/chat', ['game_id' => self::BAR, 'seq' => (string) $first->seq]);
        $this->assertSame(0, DB::table('replay_chat')->whereNotNull('hidden_at')->count());

        $this->moderator()->post('/moderation/replays/chat', ['game_id' => self::BAR, 'seq' => (string) $first->seq])->assertRedirect($events);
        $hidden = DB::table('replay_chat')->whereNotNull('hidden_at')->get();
        $this->assertSame([[(int) $first->seq, self::MODERATOR]], $hidden->map(fn ($m) => [(int) $m->seq, $m->hidden_by])->all());
        $this->get($events)->assertOk()->assertDontSee($first->name.': '.$first->text, false)->assertSee($chat[1]->text);

        $this->moderator()->post('/moderation/replays/chat', ['game_id' => self::BAR]);
        $this->assertSame(0, DB::table('replay_chat')->whereNull('hidden_at')->count());
        $this->get($events)->assertOk()->assertSee('0 of');
        // The replay itself stays up.
        $this->assertSame('listed', DB::table('replay')->where('game_id', self::BAR)->value('state'));
    }
}
