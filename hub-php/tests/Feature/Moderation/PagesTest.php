<?php

namespace Tests\Feature\Moderation;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The reports, trail, users and allowances pages rendered against faked
 * data, the report action, and the bar every page wears
 * (`components/ModerationNav.test.tsx`).
 */
class PagesTest extends ModerationTestCase
{
    private const ITEM = '11111111-1111-4111-8111-111111111111';

    private const ACCOUNT = '22222222-2222-4222-8222-222222222222';

    public function test_the_report_queue_shows_item_and_map_reports_and_what_is_featured(): void
    {
        $this->supabase([
            'GET /rest/v1/report' => self::reply([
                ['id' => 1, 'item_id' => self::ITEM, 'map_id' => null, 'kind' => null, 'reason' => "Spam\nand more", 'created_at' => '2026-10-01T00:00:00Z',
                    'item' => ['id' => self::ITEM, 'title' => 'Turtle preset', 'kind' => 'preset', 'deleted_at' => null], 'map' => null],
                ['id' => 2, 'item_id' => null, 'map_id' => 'm1', 'kind' => 'crashes_engine', 'reason' => 'Closes on load', 'created_at' => '2026-10-02T00:00:00Z',
                    'item' => null, 'map' => ['slug' => 'comet', 'map_name' => 'Comet Catcher 1.8', 'display_name' => null]],
                ['id' => 3, 'item_id' => self::ITEM, 'map_id' => null, 'kind' => null, 'reason' => 'Old', 'created_at' => '2026-10-03T00:00:00Z',
                    'item' => ['id' => self::ITEM, 'title' => 'Gone preset', 'kind' => 'preset', 'deleted_at' => '2026-10-04T00:00:00Z'], 'map' => null],
            ]),
            'GET /rest/v1/item' => self::reply([['id' => self::ITEM, 'title' => 'Featured preset', 'featured_at' => '2026-10-01T00:00:00Z']]),
        ]);

        $html = $this->signedIn()->get('/moderation')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Reports - Moderation - Coilbox Hub</title>', $html);
        $this->assertStringContainsString('Map: Crashes the engine', $html);
        $this->assertStringContainsString('href="/map/comet"', $html);
        $this->assertSame(1, substr_count($html, 'Withdraw it'), 'a withdrawn item cannot be withdrawn again');
        $this->assertStringContainsString('href="/item/'.self::ITEM.'/withdrawn"', $html);
        $this->assertStringContainsString('action="/item/'.self::ITEM.'/feature"', $html);
        $this->assertStringContainsString('>Featured preset</a>', $html);
    }

    public function test_an_empty_queue_says_nothing_is_waiting(): void
    {
        $this->supabase(['GET /rest/v1/report' => self::reply([]), 'GET /rest/v1/item' => self::reply([])]);

        $html = $this->signedIn()->get('/moderation')->assertOk()->getContent();

        $this->assertStringContainsString('Nothing waiting.', $html);
        $this->assertStringContainsString('Nothing is featured right now.', $html);
    }

    public function test_withdrawing_from_a_report_marks_it_handled_as_the_moderator(): void
    {
        $this->supabase(['PATCH /rest/v1/item' => self::reply([]), 'PATCH /rest/v1/report' => self::reply([])]);

        $this->signedIn()->post('/moderation/reports', ['report_id' => '1', 'item_id' => self::ITEM, 'withdraw' => 'true'])->assertRedirect('/moderation');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), '/rest/v1/item?id=eq.'.self::ITEM) && isset($r->data()['deleted_at']));
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), '/rest/v1/report?id=eq.1')
            && $r->data()['handled_by'] === self::MODERATOR && $r->header('Authorization')[0] === 'Bearer access');
    }

    public function test_leaving_a_report_withdraws_nothing(): void
    {
        $this->supabase(['PATCH /rest/v1/report' => self::reply([])]);

        $this->signedIn()->post('/moderation/reports', ['report_id' => '1', 'item_id' => self::ITEM]);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/rest/v1/item'));
    }

    public function test_the_trail_names_both_rejection_kinds_and_offers_a_return_only_for_an_editorial_one(): void
    {
        $asset = fn (string $id, string $moderation, ?string $kind) => ['id' => $id, 'game' => 'bar', 'unit_name' => 'armsolar', 'map_name' => null, 'faction_key' => null,
            'variant' => 'buildpic', 'moderation' => $moderation, 'rejection_kind' => $kind, 'approval_source' => null, 'origin' => 'uploaded', 'created_at' => '2026-10-01T09:30:00Z'];
        $this->supabase([
            'GET /rest/v1/asset?' => self::reply([$asset(self::ITEM, 'rejected', 'editorial'), $asset(self::ACCOUNT, 'rejected', 'safety')], 200, ['Content-Range' => '0-1/5']),
            'GET /rest/v1/asset_event' => self::reply([[
                'id' => 9, 'action' => 'rejected', 'rejection_kind' => 'safety', 'actor' => self::MODERATOR, 'uploader' => self::ACCOUNT,
                'uploader_display_name' => 'Zyx', 'uploader_provider_name' => 'zyx_handle', 'at' => '2026-10-02T10:00:00Z',
                'asset' => ['id' => self::ACCOUNT, 'game' => 'bar', 'unit_name' => 'armwin', 'map_name' => null, 'faction_key' => null, 'variant' => 'buildpic'],
            ]]),
            'GET /rest/v1/profile' => self::reply([]),
        ]);

        $html = $this->signedIn()->get('/moderation/trail?account='.self::ACCOUNT)->assertOk()->getContent();

        $this->assertStringContainsString('The 2 newest of 5 pictures this account uploaded.', $html);
        $this->assertSame(1, substr_count($html, 'Return to the queue'));
        $this->assertStringContainsString('>Final</span>', $html);
        $this->assertStringContainsString('Rejected on safety grounds, which is final', $html);
        $this->assertStringContainsString('Uploaded by Zyx, signed in as zyx_handle. Name kept after the account closed.', $html);
        $this->assertStringContainsString('2026-10-02 10:00', $html);
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'or=(actor.eq.'.self::ACCOUNT.',uploader.eq.'.self::ACCOUNT.')'));
    }

    public function test_an_account_that_is_not_a_uuid_shows_everything_instead(): void
    {
        $this->supabase(['GET /rest/v1/asset_event' => self::reply([])]);

        $html = $this->signedIn()->get('/moderation/trail?account=actor.eq.x')->assertOk()->getContent();

        $this->assertStringContainsString('Nothing has been decided yet.', $html);
        Http::assertNotSent(fn (Request $r) => str_contains(urldecode($r->url()), 'or=('));
    }

    public function test_the_directory_lists_accounts_and_pages_forward_on_a_full_page(): void
    {
        $accounts = array_map(fn ($i) => ['id' => sprintf('%08d-0000-4000-8000-000000000000', $i), 'display_name' => $i === 0 ? '' : "User {$i}",
            'created_at' => '2026-10-01T00:00:00Z', 'capabilities' => $i === 1 ? ['can_moderate', 'can_mystery'] : []], range(0, 49));
        $this->supabase(['POST /rpc/moderator_accounts' => self::reply($accounts)]);

        $html = $this->signedIn()->get('/moderation/users?q=+user+&page=2')->assertOk()->getContent();

        $this->assertStringContainsString('Unknown', $html);
        $this->assertStringContainsString('moderator, can_mystery', $html);
        $this->assertStringContainsString('href="/moderation/users?q=user"', $html);
        $this->assertStringContainsString('href="/moderation/users?q=user&amp;page=3"', $html);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rpc/moderator_accounts') && $r->data() === ['p_search' => 'user', 'p_offset' => 50]);
    }

    public function test_a_directory_that_failed_says_so_rather_than_reading_as_empty(): void
    {
        $this->supabase(['POST /rpc/moderator_accounts' => self::reply(['message' => 'not allowed'], 403)]);

        $this->signedIn()->get('/moderation/users')->assertOk()->assertSee('The directory could not be read: not allowed');
    }

    public function test_the_allowances_page_shows_counted_meters_and_no_figure_for_the_dashboards(): void
    {
        $this->supabase([
            'POST /rpc/asset_storage_usage' => self::reply([
                ['tier' => 'static', 'variant' => 'render:0', 'objects' => 2, 'bytes' => 2048],
                ['tier' => 'static', 'variant' => 'minimap', 'objects' => 1, 'bytes' => 1024],
                ['tier' => 'bucket', 'variant' => 'buildpic', 'objects' => 4, 'bytes' => 4000],
            ]),
            'POST /rpc/staged_pictures_bucket_bytes' => self::reply(805306368),
            'POST /rpc/unclaimed_staged_objects' => self::reply([['object_path' => 'a', 'bytes' => 1536, 'created_at' => '2026-09-16T08:00:00Z']]),
            'GET /rest/v1/staged_object_deletion' => self::reply([['path' => 'x']]),
            'GET /rest/v1/asset?select=updated_at' => self::reply([['updated_at' => '2026-01-01T00:00:00Z']]),
            'GET /rest/v1/asset?select=promoted_at' => self::reply([]),
            'HEAD /rest/v1/asset' => Http::response('', 200, ['Content-Range' => '*/3']),
        ]);

        $html = $this->signedIn()->get('/ops')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Allowances - Ops - Coilbox Hub</title>', $html);
        $this->assertStringContainsString('768.0 MiB', $html);
        $this->assertStringContainsString('(75%)', $html);
        $this->assertStringContainsString('counted from the database', $html);
        $this->assertStringContainsString('Supabase egress this month', $html);
        $this->assertStringContainsString('10.0 GiB', $html);
        $this->assertStringContainsString('which is longer than one daily run', $html);
        $this->assertStringContainsString('Reserved for deletion more than 60 minutes ago', $html);
        $this->assertStringContainsString('1.5 KiB', $html);
        $this->assertStringContainsString('16 Sept 2026', $html);
        $this->assertStringContainsString('>render</td>', $html);
        $this->assertStringContainsString('>never</dd>', $html);
    }

    /** @return array<string, array{string, string}> */
    public static function sections(): array
    {
        return ['reports' => ['/moderation', '/moderation'], 'users' => ['/moderation/users', '/moderation/users']];
    }

    /** Every page offers every section, and only its own is marked as current. */
    #[DataProvider('sections')]
    public function test_the_bar_offers_every_section_and_marks_one(string $path, string $current): void
    {
        $this->supabase([
            'GET /rest/v1/report' => self::reply([]),
            'GET /rest/v1/item' => self::reply([]),
            'POST /rpc/moderator_accounts' => self::reply([]),
        ]);

        $html = $this->signedIn()->get($path)->getContent();
        preg_match('#<nav aria-label="Moderation".*?</nav>#s', $html, $nav);

        foreach (['/moderation', '/moderation/assets', '/moderation/maps', '/moderation/map-packs', '/moderation/games', '/moderation/authors', '/moderation/users', '/ops'] as $href) {
            $this->assertStringContainsString('href="'.$href.'"', $nav[0]);
        }
        $this->assertSame(1, substr_count($nav[0], 'aria-current'));
        $this->assertMatchesRegularExpression('#href="'.preg_quote($current, '#').'"\s+aria-current="page"#', $nav[0]);
        preg_match_all('#class="[^"]*border-b-2[^"]*"#', $nav[0], $links);
        $this->assertCount(8, $links[0]);
        foreach ($links[0] as $link) {
            $this->assertSame(1, preg_match_all('/border-(transparent|neutral-500)/', $link));
        }
    }
}
