<?php

namespace Tests\Unit\Games;

use App\Domain\Games\Page;
use App\Supabase\Supabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ported from `lib/games/page.test.ts`: what one game's page reads and
 * assembles (#226), against a faked Supabase. The rules behind the tables are
 * proved in pgTAP. Under test here is the shape the page receives and the two
 * decisions the module makes on its way out: which release counts as current,
 * and what a malformed links blob contributes.
 */
class PageTest extends TestCase
{
    /** @return array<string, mixed> */
    private function gameRow(array $over = []): array
    {
        return [
            'shortname' => 'BA',
            'display_name' => 'Balanced Annihilation',
            'description' => null,
            'links' => [
                ['label' => 'Forum', 'url' => 'https://example.test'],
                ['label' => '', 'url' => 'https://example.test/empty'],
                'junk',
            ],
            'conquest_factions' => [
                ['name' => 'Arm', 'color' => '#2f7dff', 'side' => 'ARM'],
                ['color' => '#ff3524'],
                'junk',
            ],
            'game_download_source' => [
                ['kind' => 'github', 'value' => 'springraaar/metal_factions', 'asset' => 'metal_factions', 'filename' => null],
                ['kind' => 'rapid', 'value' => 'metalfactions:stable', 'asset' => null, 'filename' => null],
                ['kind' => 'torrent', 'value' => 'whatever', 'asset' => null, 'filename' => null],
            ],
            'game_faction' => [
                ['key' => 'armada', 'name' => 'Armada', 'logo_path' => null],
                ['key' => 'cortex', 'name' => 'Cortex', 'logo_path' => 'factions/cortex.webp'],
                ['key' => 'random', 'name' => 'Random', 'logo_path' => null],
            ],
            'game_version' => [['version' => '2.0.0', 'last_seen_at' => '2026-08-21T00:00:00Z']],
            ...$over,
        ];
    }

    /**
     * `$row` answers the game read, `$counts` the `game_browse` aggregates (null
     * for no row), `$base` the lookup of the game's own base game and
     * `$mutators` the read of games built on this one.
     *
     * @param  array<string, mixed>|null  $row
     * @param  array<string, mixed>|null  $counts
     * @param  array<string, mixed>|null  $base
     * @param  list<array<string, mixed>>  $mutators
     */
    private function fake(?array $row, ?array $counts, ?array $base = null, array $mutators = []): void
    {
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk']);

        Http::fake(function (Request $request) use ($row, $counts, $base, $mutators) {
            $url = urldecode($request->url());
            if (str_contains($url, '/rest/v1/game_browse')) {
                if (str_contains($url, 'base_game=eq.')) {
                    return Http::response($mutators);
                }
                if (str_contains($url, 'select=shortname,display_name')) {
                    return Http::response($base === null ? [] : [$base]);
                }

                return Http::response($counts === null ? [] : [$counts]);
            }

            return Http::response($row === null ? [] : [$row]);
        });
    }

    private const COUNTS = ['faction_count' => 0, 'unit_count' => 0, 'item_count' => 0];

    public function test_a_page_carries_the_rows_facts_with_the_junk_and_the_random_side_dropped(): void
    {
        $this->fake($this->gameRow(), ['faction_count' => 2, 'unit_count' => 340, 'item_count' => 5]);

        $page = Page::loadGamePage(Supabase::anon(), 'BA');

        $this->assertNotNull($page);
        $this->assertSame([['label' => 'Forum', 'url' => 'https://example.test']], $page['links']);
        $this->assertSame([['name' => 'Arm', 'color' => '#2f7dff', 'side' => 'ARM']], $page['conquest_factions']);
        $this->assertSame(['Armada', 'Cortex'], array_column($page['factions'], 'name'));
        // In the order the query asked for, with the kind nothing knows what to
        // do with left out.
        $this->assertSame([
            ['kind' => 'github', 'value' => 'springraaar/metal_factions', 'asset' => 'metal_factions'],
            ['kind' => 'rapid', 'value' => 'metalfactions:stable'],
        ], $page['downloads']);
        $this->assertSame('2.0.0', $page['release']);
        $this->assertSame(340, $page['unit_count']);
        $this->assertSame(5, $page['item_count']);
    }

    public function test_the_query_asks_for_the_embeds_in_the_order_the_page_relies_on(): void
    {
        $this->fake($this->gameRow(), self::COUNTS);

        Page::loadGamePage(Supabase::anon(), 'BA');

        Http::assertSent(function (Request $request) {
            $url = urldecode($request->url());

            return str_contains($url, '/rest/v1/game?')
                && str_contains($url, 'game_download_source(kind,value,asset,filename,sort_order),game_faction(key,name,logo_path),game_version(version,last_seen_at)')
                && str_contains($url, 'game_download_source.order=sort_order.asc,id.asc')
                && str_contains($url, 'game_faction.order=name.asc')
                && str_contains($url, 'game_version.order=last_seen_at.desc')
                && str_contains($url, 'game_version.limit=1');
        });
    }

    public function test_a_shortname_nobody_holds_is_not_found(): void
    {
        $this->fake(null, null);

        $this->assertNull(Page::loadGamePage(Supabase::anon(), 'XX'));
    }

    public function test_a_game_whose_counts_cannot_be_read_is_treated_as_absent_not_as_empty(): void
    {
        $this->fake($this->gameRow(), null);

        $this->assertNull(Page::loadGamePage(Supabase::anon(), 'BA'));
    }

    public function test_a_failed_read_is_not_found(): void
    {
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk']);
        Http::fake(['*/rest/v1/*' => Http::response(['message' => 'boom'], 500)]);

        $this->assertNull(Page::loadGamePage(Supabase::anon(), 'BA'));
    }

    public function test_no_release_reported_yet_means_no_claim_about_freshness(): void
    {
        $this->fake($this->gameRow(['game_version' => []]), self::COUNTS);

        $this->assertNull(Page::loadGamePage(Supabase::anon(), 'BA')['release']);
    }

    public function test_a_game_that_is_not_a_mutator_has_no_base_game_and_no_mutators(): void
    {
        $this->fake($this->gameRow(['base_game' => null]), self::COUNTS);

        $page = Page::loadGamePage(Supabase::anon(), 'BA');

        $this->assertNull($page['base_game']);
        $this->assertNull($page['base']);
        $this->assertSame([], $page['mutators']);
    }

    public function test_a_base_game_lists_its_mutators_by_the_title_a_reader_sees(): void
    {
        $this->fake($this->gameRow(), self::COUNTS, null, [
            ['shortname' => 'zz-rush', 'display_name' => null],
            ['shortname' => 'ba-wrapper', 'display_name' => 'Wrapper'],
            ['shortname' => 'ba-arena', 'display_name' => 'Arena'],
        ]);

        $page = Page::loadGamePage(Supabase::anon(), 'BA');

        $this->assertSame([
            ['shortname' => 'ba-arena', 'title' => 'Arena'],
            ['shortname' => 'ba-wrapper', 'title' => 'Wrapper'],
            ['shortname' => 'zz-rush', 'title' => 'zz-rush'],
        ], $page['mutators']);
    }

    public function test_a_mutator_links_to_its_base_game_when_the_hub_holds_it(): void
    {
        $this->fake(
            $this->gameRow(['shortname' => 'BA-Rush', 'base_game' => 'BA']),
            self::COUNTS,
            ['shortname' => 'BA', 'display_name' => 'Balanced Annihilation'],
        );

        $page = Page::loadGamePage(Supabase::anon(), 'BA-Rush');

        $this->assertSame('BA', $page['base_game']);
        $this->assertSame(['shortname' => 'BA', 'title' => 'Balanced Annihilation'], $page['base']);
    }

    public function test_a_mutator_whose_base_game_is_not_on_the_hub_keeps_the_name_and_has_no_link_target(): void
    {
        $this->fake($this->gameRow(['shortname' => 'BA-Rush', 'base_game' => 'Elsewhere']), self::COUNTS);

        $page = Page::loadGamePage(Supabase::anon(), 'BA-Rush');

        $this->assertSame('Elsewhere', $page['base_game']);
        $this->assertNull($page['base']);
    }
}
