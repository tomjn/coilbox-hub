<?php

namespace Tests\Unit\Moderation;

use App\Domain\Assets\Asset;
use App\Domain\Moderation\ApprovedConflicts;
use App\Domain\Moderation\Queue;
use App\Domain\Moderation\Trail;
use App\Supabase\Supabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ported from `lib/assets/queue.test.ts`, `lib/assets/trail.test.ts` and
 * `lib/assets/approvedConflicts.test.ts`.
 */
class QueueAndTrailTest extends TestCase
{
    private const ID = '0f8fad5b-d9cb-469f-a165-70867728950e';

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'sb_publishable_test']);
    }

    public function test_a_unit_caption_names_the_game_since_unit_names_repeat_across_games(): void
    {
        $this->assertSame(['name' => 'armsolar', 'detail' => 'bar buildpic'], Queue::caption(['game' => 'bar', 'unit_name' => 'armsolar', 'map_name' => null, 'variant' => 'buildpic']));
    }

    public function test_a_map_caption_names_no_game(): void
    {
        $this->assertSame(['name' => 'Tangerine 1.1', 'detail' => 'minimap'], Queue::caption(['game' => null, 'unit_name' => null, 'map_name' => 'Tangerine 1.1', 'variant' => 'minimap']));
    }

    public function test_a_faction_logo_is_captioned_by_its_faction_and_names_the_game(): void
    {
        $this->assertSame(['name' => 'armada', 'detail' => 'bar logo'], Queue::caption(['game' => 'bar', 'unit_name' => null, 'map_name' => null, 'faction_key' => 'armada', 'variant' => 'logo']));
    }

    public function test_an_id_that_is_not_a_uuid_never_reaches_a_filter(): void
    {
        $this->assertSame([self::ID], Queue::pictureIds([self::ID, '', '*', 'id.neq.null', "'; update asset set moderation"]));
    }

    public function test_a_repeated_id_collapses_to_one(): void
    {
        $this->assertSame([self::ID], Queue::pictureIds([self::ID, self::ID, self::ID]));
    }

    public function test_a_submission_cannot_act_on_more_rows_than_a_page_holds(): void
    {
        $many = array_map(fn ($i) => '0f8fad5b-d9cb-469f-a165-'.str_pad((string) $i, 12, '0', STR_PAD_LEFT), range(0, Queue::PAGE_SIZE + 49));

        $this->assertCount(Queue::PAGE_SIZE, Queue::pictureIds($many));
    }

    /** @param array<string, mixed> $over */
    private static function event(array $over): array
    {
        return $over + [
            'id' => 1, 'action' => 'approved', 'rejectionKind' => null, 'actor' => null, 'uploader' => null, 'uploaderName' => null,
            'at' => '2026-08-14T09:00:00Z', 'assetId' => self::ID, 'name' => 'armsolar', 'detail' => 'bar buildpic',
        ];
    }

    public function test_the_two_rejections_do_not_read_alike(): void
    {
        $safety = Trail::eventLine(self::event(['action' => 'rejected', 'rejectionKind' => 'safety']));
        $editorial = Trail::eventLine(self::event(['action' => 'rejected', 'rejectionKind' => 'editorial']));

        $this->assertStringContainsString('safety', $safety);
        $this->assertStringContainsString('final', $safety);
        $this->assertStringContainsString('editorial', $editorial);
        $this->assertStringNotContainsString('safety', $editorial);
    }

    public function test_a_rejection_from_before_kinds_says_so_rather_than_guessing(): void
    {
        $this->assertSame('Rejected as unrecorded', Trail::eventLine(self::event(['action' => 'rejected', 'rejectionKind' => 'unrecorded'])));
    }

    public function test_seeding_and_waiving_the_queue_are_different_lines(): void
    {
        $this->assertNotSame(Trail::eventLine(self::event(['action' => 'seeded'])), Trail::eventLine(self::event(['action' => 'bypassed'])));
    }

    public function test_a_takeover_says_it_was_another_accounts_picture_replaced_on_a_capability(): void
    {
        $line = Trail::eventLine(self::event(['action' => 'replaced']));

        $this->assertStringContainsString('another account', $line);
        $this->assertStringContainsString('capability', $line);
        $this->assertNotSame(Trail::eventLine(self::event(['action' => 'returned'])), $line);
    }

    public function test_every_action_the_database_can_record_has_something_to_say(): void
    {
        foreach (Asset::EVENT_ACTIONS as $action) {
            $line = Trail::eventLine(self::event(['action' => $action, 'rejectionKind' => $action === 'rejected' ? 'editorial' : null]));
            $this->assertNotSame('', $line);
            $this->assertNotSame($action, $line, "{$action} has no sentence of its own");
        }
    }

    public function test_a_safety_rejection_names_the_uploader_by_both_names(): void
    {
        $line = Trail::uploaderNameLine(self::event(['action' => 'rejected', 'rejectionKind' => 'safety',
            'uploaderName' => ['displayName' => 'Zyx Hub Name', 'providerName' => 'zyx_handle', 'accountClosed' => false]]));

        $this->assertStringContainsString('Zyx Hub Name', $line);
        $this->assertStringContainsString('zyx_handle', $line);
        $this->assertStringNotContainsString('closed', $line);
    }

    public function test_the_names_say_they_were_kept_once_the_account_has_closed(): void
    {
        $line = Trail::uploaderNameLine(self::event(['uploaderName' => ['displayName' => 'Zyx Hub Name', 'providerName' => null, 'accountClosed' => true]]));

        $this->assertStringContainsString('Zyx Hub Name', $line);
        $this->assertStringContainsString('kept after the account closed', $line);
    }

    public function test_an_event_with_no_names_kept_says_nothing_about_them(): void
    {
        $this->assertNull(Trail::uploaderNameLine(self::event(['action' => 'rejected', 'rejectionKind' => 'editorial'])));
        $this->assertNull(Trail::uploaderNameLine(self::event(['uploaderName' => ['displayName' => null, 'providerName' => null, 'accountClosed' => true]])));
    }

    public function test_the_disagreement_list_asks_for_a_queue_page_and_captions_each_row(): void
    {
        Http::fake(['*' => Http::response([[
            'conflict_id' => 7, 'asset_id' => '0f8fad5b-0000-4000-8000-0000000000a1', 'game' => 'bar', 'unit_name' => 'armsolar', 'map_name' => null,
            'faction_key' => null, 'variant' => 'buildpic', 'source_archive' => 'bar_1.2.sdz', 'held_source_hash' => 'src-one',
            'reported_source_hash' => 'src-other', 'reported_by' => '22222222-2222-2222-2222-222222222222',
            'uploaded_by' => '11111111-1111-1111-1111-111111111111', 'at' => '2026-10-17T09:00:00Z', 'total' => 3,
        ]])]);

        $this->assertSame([
            'conflicts' => [[
                'conflictId' => 7, 'assetId' => '0f8fad5b-0000-4000-8000-0000000000a1', 'name' => 'armsolar', 'detail' => 'bar buildpic',
                'sourceArchive' => 'bar_1.2.sdz', 'heldSourceHash' => 'src-one', 'reportedSourceHash' => 'src-other',
                'reportedBy' => '22222222-2222-2222-2222-222222222222', 'uploadedBy' => '11111111-1111-1111-1111-111111111111', 'at' => '2026-10-17T09:00:00Z',
            ]],
            'total' => 3,
            'error' => null,
        ], ApprovedConflicts::fetch(Supabase::forToken('t')));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rpc/approved_source_conflicts') && $r->data() === ['p_limit' => Queue::PAGE_SIZE]);
    }

    public function test_a_failed_disagreement_read_is_an_error_and_not_an_empty_list(): void
    {
        Http::fake(['*' => Http::response(['message' => 'the list is for moderators'], 403)]);

        $this->assertSame(['conflicts' => [], 'total' => 0, 'error' => 'the list is for moderators'], ApprovedConflicts::fetch(Supabase::forToken('t')));
    }

    public function test_an_empty_disagreement_list_has_a_total_of_nothing(): void
    {
        Http::fake(['*' => Http::response([])]);

        $this->assertSame(['conflicts' => [], 'total' => 0, 'error' => null], ApprovedConflicts::fetch(Supabase::forToken('t')));
    }

    public function test_acknowledging_reports_whether_anything_was_cleared(): void
    {
        Http::fakeSequence()->push("true")->push("false")->push(['message' => 'nope'], 400);
        $client = Supabase::forToken('t');

        $this->assertTrue(ApprovedConflicts::acknowledge($client, 7));
        $this->assertFalse(ApprovedConflicts::acknowledge($client, 7));
        $this->assertFalse(ApprovedConflicts::acknowledge($client, 7));
    }

    public function test_only_a_plain_positive_whole_number_is_an_entry_id(): void
    {
        $this->assertSame(7, ApprovedConflicts::conflictId('7'));
        foreach (['0', '-1', '1.5', '7; drop table', '', '99999999999999999999'] as $bad) {
            $this->assertNull(ApprovedConflicts::conflictId($bad), $bad);
        }
    }
}
