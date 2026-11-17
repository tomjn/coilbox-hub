<?php

namespace Tests\Feature\Moderation;

use App\Domain\Mirrors\Removal;
use App\Support\HubCache;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** `/account/uploads` and `/moderation/archives`, uploads to hub mirrors and their removal. */
class ArchivesTest extends ModerationTestCase
{
    private const ARCHIVE = '22222222-0000-4000-8000-000000000001';

    private static function archive(array $overrides = []): array
    {
        return $overrides + [
            'id' => self::ARCHIVE,
            'sha256' => str_repeat('ab', 32),
            'filename' => 'Comet Catcher.sd7',
            'kind' => 'map',
            'internal_name' => 'Comet Catcher',
            'version' => '1.0',
            'shortname' => null,
            'author' => 'Someone',
            'size' => 10,
            'listed_at' => '2026-11-17T12:00:00+00:00',
            'copies' => ['https://jj.darkstars.co.uk/maps/Comet%20Catcher.sd7'],
        ];
    }

    /** Whether a value cached under the maps and games tags before $act is still served after it. */
    private function cachedPagesSurvive(callable $act): bool
    {
        HubCache::remember('maps:test', [HubCache::MAPS], 60, fn () => 'before');
        HubCache::remember('games:test', [HubCache::GAMES], 60, fn () => 'before');
        $act();

        return HubCache::remember('maps:test', [HubCache::MAPS], 60, fn () => 'after') === 'before'
            && HubCache::remember('games:test', [HubCache::GAMES], 60, fn () => 'after') === 'before';
    }

    // ## Your uploads

    public function test_a_signed_out_visitor_is_sent_to_sign_in(): void
    {
        $this->supabase();

        $this->get('/account/uploads')->assertRedirect('/publish');
        $this->post('/account/uploads/withdraw', ['archive_id' => self::ARCHIVE])->assertRedirect('/publish');
        $this->assertSame([], $this->sentWrites());
    }

    public function test_the_uploads_page_lists_each_upload_and_offers_withdraw_on_listed_ones(): void
    {
        $this->supabase(['POST /rpc/my_archives' => self::reply([
            ['id' => self::ARCHIVE, 'filename' => 'Comet Catcher.sd7', 'kind' => 'map', 'state' => 'listed', 'internal_name' => 'Comet Catcher', 'version' => '1.0', 'created_at' => '2026-11-17T12:00:00+00:00', 'listed_at' => '2026-11-17T12:00:00+00:00', 'removed_at' => null, 'removal_reason' => null, 'mine' => true, 'via_game' => false],
            ['id' => '22222222-0000-4000-8000-000000000002', 'filename' => 'old.sdz', 'kind' => 'game', 'state' => 'removed', 'internal_name' => 'Old Game 1', 'version' => '1', 'created_at' => '2026-11-16T12:00:00+00:00', 'listed_at' => null, 'removed_at' => '2026-11-17T12:00:00+00:00', 'removal_reason' => 'rights', 'mine' => false, 'via_game' => true],
        ])]);

        $this->signedIn()->get('/account/uploads')
            ->assertOk()
            ->assertSee('Withdraw Comet Catcher.sd7')
            ->assertSee('Map: Comet Catcher, version 1.0.')
            ->assertSee(Removal::LABELS['rights'])
            ->assertSee('Somebody else uploaded this to a game you own.')
            ->assertDontSee('Withdraw old.sdz');
    }

    public function test_withdrawing_sends_withdrawn_says_so_and_drops_cached_pages(): void
    {
        $this->supabase(['POST /rpc/remove_archive' => self::reply('removed')]);

        $this->assertFalse($this->cachedPagesSurvive(function () {
            $this->signedIn()->post('/account/uploads/withdraw', ['archive_id' => self::ARCHIVE])
                ->assertRedirect('/account/uploads')
                ->assertSessionHas('uploadsState', ['ok' => true, 'message' => 'Removed. The hub stopped linking it, and mirrors delete it on their next sync.']);
        }));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rpc/remove_archive')
            && $r->data() === ['p_id' => self::ARCHIVE, 'p_reason' => 'withdrawn', 'p_note' => null]
            && $r->hasHeader('Authorization', 'Bearer access'));
    }

    public function test_a_refused_withdrawal_says_why_and_keeps_cached_pages(): void
    {
        $this->supabase(['POST /rpc/remove_archive' => self::reply('not_allowed')]);

        $this->assertTrue($this->cachedPagesSurvive(function () {
            $this->signedIn()->post('/account/uploads/withdraw', ['archive_id' => self::ARCHIVE])
                ->assertSessionHas('uploadsState', ['ok' => false, 'message' => 'You cannot remove this archive.']);
        }));
    }

    // ## Moderation

    public function test_a_stranger_gets_404_on_the_moderation_page(): void
    {
        $this->supabase(moderator: false);

        $this->signedIn()->get('/moderation/archives')->assertNotFound();
    }

    public function test_the_moderation_page_shows_reports_beside_the_hub_facts_and_recent_uploads(): void
    {
        $this->supabase(['POST /rpc/moderation_archives' => self::reply([
            'reported' => [self::archive() + ['reports' => [['found' => ['internal_name' => 'Not Comet'], 'created_at' => '2026-11-17T13:00:00+00:00']]]],
            'recent' => [self::archive(['id' => '22222222-0000-4000-8000-000000000009', 'filename' => 'Newer.sd7', 'copies' => []])],
        ])]);

        $this->signedIn()->get('/moderation/archives?q=comet')
            ->assertOk()
            ->assertSee('Not Comet')
            ->assertSee('Download from jj.darkstars.co.uk')
            ->assertSee('Dismiss the reports')
            ->assertSee('Remove Newer.sd7')
            ->assertSee('No healthy mirror holds a copy right now.')
            ->assertSee(Removal::MODERATOR_REASONS['other']);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rpc/moderation_archives') && $r->data() === ['p_query' => 'comet']);
    }

    public function test_a_moderator_removes_with_a_reason_and_a_note(): void
    {
        $this->supabase(['POST /rpc/remove_archive' => self::reply('removed')]);

        $this->signedIn()->from('/moderation/archives')
            ->post('/moderation/archives/remove', ['archive_id' => self::ARCHIVE, 'reason' => 'other', 'note' => '  Asked by the author  '])
            ->assertRedirect('/moderation/archives')
            ->assertSessionHas('archivesState.ok', true);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rpc/remove_archive')
            && $r->data() === ['p_id' => self::ARCHIVE, 'p_reason' => 'other', 'p_note' => 'Asked by the author']);
    }

    public function test_other_without_a_note_is_answered_with_the_database_message(): void
    {
        $this->supabase(['POST /rpc/remove_archive' => self::reply('note_required')]);

        $this->signedIn()->from('/moderation/archives')
            ->post('/moderation/archives/remove', ['archive_id' => self::ARCHIVE, 'reason' => 'other', 'note' => ''])
            ->assertSessionHas('archivesState', ['ok' => false, 'message' => 'Say why in the note.']);
    }

    public function test_dismissing_counts_the_reports(): void
    {
        $this->supabase(['POST /rpc/dismiss_archive_mismatches' => self::reply(2)]);

        $this->signedIn()->from('/moderation/archives')
            ->post('/moderation/archives/dismiss', ['archive_id' => self::ARCHIVE])
            ->assertSessionHas('archivesState', ['ok' => true, 'message' => 'Dismissed 2 reports.']);
    }

    public function test_a_stranger_posting_to_moderation_writes_nothing(): void
    {
        $this->supabase(moderator: false);

        $this->signedIn()->post('/moderation/archives/remove', ['archive_id' => self::ARCHIVE, 'reason' => 'broken']);
        $this->signedIn()->post('/moderation/archives/dismiss', ['archive_id' => self::ARCHIVE]);

        $this->assertSame([], $this->sentWrites());
    }
}
