<?php

namespace Tests\Feature\Moderation;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The contact sheet, its writes and the moderator picture route. Ported from
 * `app/moderation/assets/[id]/route.test.ts`, with the actions in
 * `app/moderation/assets/actions.ts` and `app/moderation/trail/actions.ts`.
 */
class PicturesTest extends ModerationTestCase
{
    private const ID = '0f8fad5b-d9cb-469f-a165-70867728950e';

    private const OTHER = '0f8fad5b-d9cb-469f-a165-70867728950f';

    /** @param array<string, mixed>|null $row */
    private function row(?array $row, mixed $download = null, bool $moderator = true): void
    {
        $this->supabase([
            'GET /rest/v1/asset?select=path,tier,mime' => self::reply($row === null ? [] : [$row]),
            'GET /storage/v1/object/staged-pictures/' => $download ?? Http::response('hello', 200, ['Content-Type' => 'image/webp']),
            'tomjn.github.io' => Http::response('durable bytes', 200),
        ], moderator: $moderator);
    }

    public function test_a_moderator_asking_for_a_row_that_does_not_exist_gets_404(): void
    {
        $this->row(null);

        $this->signedIn()->get('/moderation/assets/'.self::ID)->assertNotFound();
    }

    public function test_somebody_who_is_not_a_moderator_gets_404_without_the_row_being_looked_up(): void
    {
        $this->row(['path' => 'units/bar/buildpic/abc.webp', 'tier' => 'bucket', 'mime' => 'image/webp'], moderator: false);

        $this->signedIn()->get('/moderation/assets/'.self::ID)->assertNotFound();
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/rest/v1/asset'));
    }

    public function test_a_bucket_row_is_served_to_a_moderator_with_the_security_headers(): void
    {
        $this->row(['path' => 'units/bar/buildpic/abc.webp', 'tier' => 'bucket', 'mime' => 'image/webp']);

        $response = $this->signedIn()->get('/moderation/assets/'.self::ID);

        $response->assertOk();
        $this->assertSame('hello', $response->getContent());
        $response->assertHeader('Content-Type', 'image/webp');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=300', $response->headers->get('Cache-Control'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/storage/v1/object/staged-pictures/units/bar/buildpic/abc.webp'));
    }

    public function test_a_static_row_is_fetched_from_its_durable_tier_url_with_the_same_headers(): void
    {
        $this->row(['path' => 'units/bar/abc.webp', 'tier' => 'static', 'mime' => 'image/webp']);

        $response = $this->signedIn()->get('/moderation/assets/'.self::ID);

        $response->assertOk();
        $this->assertSame('durable bytes', $response->getContent());
        $response->assertHeader('Content-Type', 'image/webp');
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        Http::assertSent(fn (Request $r) => $r->url() === 'https://tomjn.github.io/coilbox-assets/units/bar/abc.webp');
    }

    public function test_a_row_whose_declared_mime_the_hub_does_not_store_falls_back_to_octet_stream(): void
    {
        $this->row(['path' => 'x.webp', 'tier' => 'bucket', 'mime' => 'text/html']);

        $this->signedIn()->get('/moderation/assets/'.self::ID)->assertHeader('Content-Type', 'application/octet-stream');
    }

    public function test_a_bucket_row_with_no_object_is_a_missing_row(): void
    {
        $this->row(['path' => 'gone.webp', 'tier' => 'bucket', 'mime' => 'image/webp'], self::reply(['statusCode' => '404', 'error' => 'not_found'], 400));

        $this->signedIn()->get('/moderation/assets/'.self::ID)->assertNotFound();
    }

    public function test_a_storage_failure_that_is_not_a_missing_object_is_a_clean_502(): void
    {
        $this->row(['path' => 'x.webp', 'tier' => 'bucket', 'mime' => 'image/webp'], self::reply(['statusCode' => '503', 'error' => 'down'], 503));

        $response = $this->signedIn()->get('/moderation/assets/'.self::ID);
        $response->assertStatus(502);
        $this->assertSame('', $response->getContent());
    }

    public function test_approving_sends_the_ticked_ids_as_the_moderator_and_drops_anything_else(): void
    {
        $this->supabase(['POST /rpc/approve_assets' => self::reply(2)]);

        $this->signedIn()->post('/moderation/assets/approve', ['asset' => [self::ID, self::OTHER, self::ID, 'id.neq.null']])
            ->assertRedirect('/moderation/assets');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rpc/approve_assets')
            && $r->data() === ['ids' => [self::ID, self::OTHER]]
            && $r->header('Authorization')[0] === 'Bearer access');
    }

    public function test_a_rejection_says_which_kind(): void
    {
        $this->supabase(['POST /rpc/reject_asset' => self::reply(true)]);

        $this->signedIn()->post('/moderation/assets/reject', ['asset' => self::ID, 'kind' => 'safety']);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rpc/reject_asset')
            && $r->data() === ['asset_id' => self::ID, 'kind' => 'safety']);
    }

    public function test_a_rejection_of_a_kind_nobody_knows_is_dropped_rather_than_guessed(): void
    {
        $this->supabase();

        $this->signedIn()->post('/moderation/assets/reject', ['asset' => self::ID, 'kind' => 'unrecorded']);

        $this->assertSame([], $this->sentWrites());
    }

    public function test_looked_at_passes_the_entry_id(): void
    {
        $this->supabase(['POST /rpc/acknowledge_source_conflict' => self::reply(true)]);

        $this->signedIn()->post('/moderation/assets/acknowledge', ['conflict' => '7']);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rpc/acknowledge_source_conflict') && $r->data() === ['p_conflict_id' => 7]);
    }

    public function test_returning_to_the_queue_calls_return_asset_as_the_moderator(): void
    {
        $this->supabase(['POST /rpc/return_asset' => self::reply(true)]);

        $this->signedIn()->post('/moderation/trail/return', ['asset' => self::ID])->assertRedirect('/moderation/trail');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rpc/return_asset')
            && $r->data() === ['asset_id' => self::ID]
            && $r->header('Authorization')[0] === 'Bearer access');
    }

    public function test_the_contact_sheet_draws_each_tile_and_leaves_a_flagged_one_unticked(): void
    {
        $tile = fn (string $id, string $unit) => ['id' => $id, 'game' => 'bar', 'unit_name' => $unit, 'map_name' => null, 'faction_key' => null, 'variant' => 'buildpic',
            'width' => 128, 'height' => 128, 'bytes' => 20480, 'origin' => 'uploaded', 'source_archive' => 'bar_1.2.sdz', 'seen_at' => '2026-10-01T00:00:00Z'];
        $this->supabase([
            'GET /rest/v1/asset_source_conflict' => self::reply([['asset_id' => self::OTHER]]),
            'GET /rest/v1/asset?' => self::reply([$tile(self::ID, 'armsolar'), $tile(self::OTHER, 'armwin')], 200, ['Content-Range' => '0-1/300']),
            'POST /rpc/approved_source_conflicts' => self::reply([]),
        ]);

        $html = $this->signedIn()->get('/moderation/assets')->assertOk()->getContent();

        $this->assertStringContainsString('The 2 oldest of 300 waiting.', $html);
        $this->assertStringContainsString('src="/moderation/assets/'.self::ID.'"', $html);
        $this->assertMatchesRegularExpression('/value="'.self::ID.'"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="'.self::OTHER.'"\s+checked/', $html);
        $this->assertStringContainsString('Same archive, different bytes', $html);
        $this->assertStringContainsString('uploaded, 20 kB', $html);
        $this->assertStringNotContainsString('path', strtolower(implode(' ', array_map(fn (Request $r) => urldecode($r->url()), array_filter($this->sent, fn (Request $r) => str_contains($r->url(), 'rest/v1/asset?')))))
            , 'the queue read must never select path');
    }

    public function test_a_disagreement_list_that_failed_says_so(): void
    {
        $this->supabase([
            'GET /rest/v1/asset?' => self::reply([], 200, ['Content-Range' => '*/0']),
            'POST /rpc/approved_source_conflicts' => self::reply(['message' => 'the list is for moderators'], 403),
        ]);

        $html = $this->signedIn()->get('/moderation/assets')->assertOk()->getContent();

        $this->assertStringContainsString('Nothing waiting.', $html);
        $this->assertStringContainsString('The list did not load: the list is for moderators', $html);
    }
}
