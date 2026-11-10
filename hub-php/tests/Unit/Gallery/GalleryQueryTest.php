<?php

namespace Tests\Unit\Gallery;

use App\Domain\Container\Container;
use App\Domain\Gallery\ApiItems;
use App\Domain\Gallery\Filters;
use App\Domain\Gallery\GalleryQuery;
use App\Domain\Gallery\SearchParams;
use App\Supabase\Query;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Ported from `lib/gallery/query.test.ts`. */
class GalleryQueryTest extends TestCase
{
    private const ADA = '11111111-1111-1111-1111-111111111111';

    private const BOB = '22222222-2222-2222-2222-222222222222';

    private const MAX_SAFE_INTEGER = 9007199254740991;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk']);
    }

    /**
     * Build a real query, let the callback add to it, send it, and read back
     * the query string PostgREST would have received.
     *
     * @param  callable(Query): mixed  $build
     */
    private function sentParams(callable $build): SearchParams
    {
        Http::fake(['*' => Http::response([], 200)]);
        $build(Supabase::anon()->from('item'))->get();

        $recorded = Http::recorded();
        $this->assertCount(1, $recorded);

        return SearchParams::parse((string) parse_url((string) $recorded[0][0]->url(), PHP_URL_QUERY));
    }

    private function tablePage(array $rows, int $from, int $to): array
    {
        return ['rows' => array_slice($rows, $from, $to - $from + 1), 'count' => count($rows)];
    }

    public function test_filters_come_out_of_the_query_string(): void
    {
        $filters = GalleryQuery::parseFilters([
            'kind' => 'preset',
            'game' => 'Beyond All Reason',
            'tag' => 'ECO',
            'page' => '3',
        ]);

        $this->assertSame(['preset'], $filters->kind);
        $this->assertSame('Beyond All Reason', $filters->game);
        $this->assertSame(['eco'], $filters->tag);
        $this->assertSame(3, $filters->page);
    }

    public function test_an_unrecognised_kind_is_dropped_rather_than_passed_to_the_database(): void
    {
        $this->assertSame([], GalleryQuery::parseFilters(['kind' => 'campaign'])->kind);
        $this->assertSame([], GalleryQuery::parseFilters(['kind' => "'; drop table item; --"])->kind);
    }

    public function test_more_than_one_kind_is_a_union_not_a_replacement(): void
    {
        $filters = GalleryQuery::parseFilters(['kind' => ['preset', 'blueprint']]);
        $this->assertSame(['preset', 'blueprint'], $filters->kind);
    }

    public function test_a_repeated_kind_is_deduplicated(): void
    {
        $this->assertSame(['preset'], GalleryQuery::parseFilters(['kind' => ['preset', 'preset']])->kind);
    }

    public function test_more_than_one_tag_or_author_is_also_a_union(): void
    {
        $filters = GalleryQuery::parseFilters(['tag' => ['Eco', 'pvp'], 'author' => ['Alice', 'Bob']]);
        $this->assertSame(['eco', 'pvp'], $filters->tag);
        $this->assertSame(['Alice', 'Bob'], $filters->author);
    }

    public function test_sort_defaults_to_newest_and_an_unrecognised_value_falls_back_to_it(): void
    {
        $this->assertSame('newest', GalleryQuery::parseFilters([])->sort);
        $this->assertSame('newest', GalleryQuery::parseFilters(['sort' => 'oldest'])->sort);
        $this->assertSame('title', GalleryQuery::parseFilters(['sort' => 'title'])->sort);
    }

    public function test_a_nonsense_page_falls_back_to_the_first(): void
    {
        $this->assertSame(1, GalleryQuery::parseFilters(['page' => '0'])->page);
        $this->assertSame(1, GalleryQuery::parseFilters(['page' => '-4'])->page);
        $this->assertSame(1, GalleryQuery::parseFilters(['page' => 'banana'])->page);
        $this->assertSame(1, GalleryQuery::parseFilters(['page' => ''])->page);
        $this->assertSame(1, GalleryQuery::parseFilters(['page' => '1.5'])->page);
        $this->assertSame(1, GalleryQuery::parseFilters(['page' => '1e3'])->page);
        $this->assertSame(2, GalleryQuery::parseFilters(['page' => ['2', '5']])->page);
    }

    public function test_the_largest_page_is_the_one_whose_last_row_still_has_a_safe_index(): void
    {
        $last = GalleryQuery::maxPage(GalleryQuery::PAGE_SIZE);
        $this->assertLessThanOrEqual(self::MAX_SAFE_INTEGER, $last * GalleryQuery::PAGE_SIZE);
        $this->assertGreaterThan(self::MAX_SAFE_INTEGER, ($last + 1) * GalleryQuery::PAGE_SIZE);
        $this->assertSame($last, GalleryQuery::parseFilters(['page' => (string) $last])->page);
    }

    public function test_a_page_too_large_to_be_a_real_page_is_not_served_as_any_other_page_number(): void
    {
        $past = (string) (GalleryQuery::maxPage(GalleryQuery::PAGE_SIZE) + 1);
        $this->assertNull(GalleryQuery::parsePage($past, GalleryQuery::PAGE_SIZE));
        $this->assertNull(GalleryQuery::parsePage('99999999999999999999', GalleryQuery::PAGE_SIZE));
        $this->assertNull(GalleryQuery::parsePage(str_repeat('9', 400), GalleryQuery::PAGE_SIZE));
        $this->assertSame(1, GalleryQuery::parseFilters(['page' => $past])->page);
        $this->assertSame(1, GalleryQuery::parseFilters(['page' => '99999999999999999999'])->page);
        $this->assertSame(1, GalleryQuery::parseFilters(['page' => str_repeat('9', 400)])->page);
    }

    public function test_empty_values_are_treated_as_absent(): void
    {
        $filters = GalleryQuery::parseFilters(['game' => '   ', 'map' => '']);
        $this->assertNull($filters->game);
        $this->assertNull($filters->map);
    }

    public function test_a_repeated_parameter_takes_the_first_not_an_array(): void
    {
        $this->assertSame('One', GalleryQuery::parseFilters(['game' => ['One', 'Two']])->game);
    }

    public function test_the_game_filter_matches_game_key_not_game_name(): void
    {
        // Issue #50. game_name can hold a version carrying archive name unique to one row.
        $params = $this->sentParams(fn (Query $q) => GalleryQuery::applyFilters($q, GalleryQuery::parseFilters(['game' => 'BA'])));

        $this->assertSame(['eq.BA'], $params->getAll('game_key'));
        $this->assertNotContains('game_name', $params->keys());
    }

    public function test_kind_and_author_filter_with_in_a_union_even_for_one_value(): void
    {
        $params = $this->sentParams(fn (Query $q) => GalleryQuery::applyFilters(
            $q,
            GalleryQuery::parseFilters(['kind' => ['preset', 'blueprint'], 'author' => 'Alice']),
        ));

        $this->assertSame(['in.(preset,blueprint)'], $params->getAll('kind'));
        $this->assertSame(['in.(Alice)'], $params->getAll('author_name'));
    }

    public function test_tag_filters_with_overlaps_matching_any_of_the_given_tags(): void
    {
        $params = $this->sentParams(fn (Query $q) => GalleryQuery::applyFilters($q, GalleryQuery::parseFilters(['tag' => ['eco', 'pvp']])));

        $this->assertSame(['ov.{eco,pvp}'], $params->getAll('tags'));
    }

    public function test_featured_items_are_ordered_above_the_readers_own_sort(): void
    {
        $params = $this->sentParams(fn (Query $q) => GalleryQuery::applyOrder($q, 'newest'));

        $calls = explode(',', $params->get('order'));
        $this->assertStringStartsWith('featured_at.', $calls[0]);
        $this->assertSame('created_at.desc', $calls[1]);
    }

    public function test_items_nobody_has_featured_sort_last_not_first(): void
    {
        // Postgres sorts nulls first on a descending column, so the default
        // would put every unfeatured item above the featured ones.
        $params = $this->sentParams(fn (Query $q) => GalleryQuery::applyOrder($q, 'title'));

        $this->assertSame(['featured_at.desc.nullslast,title.asc'], $params->getAll('order'));
    }

    public function test_changing_a_filter_keeps_the_others_and_drops_the_page(): void
    {
        $current = GalleryQuery::parseFilters(['kind' => 'preset', 'game' => 'BAR', 'page' => '5']);

        $this->assertSame('/gallery?kind=preset&game=BAR&map=Comet', GalleryQuery::filterHref($current, ['map' => 'Comet']));
    }

    public function test_paging_keeps_every_filter(): void
    {
        $current = GalleryQuery::parseFilters(['kind' => 'scenario', 'tag' => 'hard']);

        $this->assertSame('/gallery?kind=scenario&tag=hard&page=2', GalleryQuery::filterHref($current, ['page' => 2]));
    }

    public function test_clearing_everything_is_a_bare_path(): void
    {
        $current = GalleryQuery::parseFilters(['kind' => 'preset', 'game' => 'BAR']);

        $this->assertSame('/gallery', GalleryQuery::filterHref($current, ['kind' => [], 'game' => null]));
    }

    public function test_account_takes_account_ids_a_union_when_repeated_and_drops_anything_else(): void
    {
        $this->assertSame([self::ADA], GalleryQuery::parseFilters(['account' => self::ADA])->account);
        $this->assertSame([self::ADA, self::BOB], GalleryQuery::parseFilters(['account' => [self::ADA, self::BOB, self::ADA]])->account);
        // A value the database cannot read as a uuid would fail the whole query.
        $this->assertSame([self::ADA], GalleryQuery::parseFilters(['account' => ['Alice', '', strtoupper(self::ADA)]])->account);
        $this->assertSame([], GalleryQuery::parseFilters([])->account);
    }

    public function test_account_filters_on_author_id_not_on_the_author_name_snapshot(): void
    {
        $params = $this->sentParams(fn (Query $q) => GalleryQuery::applyFilters($q, GalleryQuery::parseFilters(['account' => self::ADA])));

        $this->assertSame(['in.('.self::ADA.')'], $params->getAll('author_id'));
        $this->assertNotContains('author_name', $params->keys());
    }

    public function test_the_older_author_name_filter_still_matches_author_name(): void
    {
        $params = $this->sentParams(fn (Query $q) => GalleryQuery::applyFilters($q, GalleryQuery::parseFilters(['author' => 'Alice'])));

        $this->assertSame(['in.(Alice)'], $params->getAll('author_name'));
        $this->assertNotContains('author_id', $params->keys());
    }

    public function test_filter_href_keeps_and_clears_the_account_filter(): void
    {
        $current = GalleryQuery::parseFilters(['account' => self::ADA, 'kind' => 'preset']);

        $this->assertSame('/gallery?kind=preset&account='.self::ADA.'&page=2', GalleryQuery::filterHref($current, ['page' => 2]));
        $this->assertSame('/gallery?kind=preset', GalleryQuery::filterHref($current, ['account' => []]));
    }

    public function test_a_result_set_smaller_than_one_page_comes_back_whole(): void
    {
        $rows = [1, 2, 3];
        $result = GalleryQuery::fetchAllPages(fn (int $from, int $to) => $this->tablePage($rows, $from, $to), 24);

        $this->assertNull($result['error']);
        $this->assertSame($rows, $result['data']);
    }

    public function test_a_result_set_larger_than_one_page_comes_back_whole(): void
    {
        $rows = range(0, 44);
        $result = GalleryQuery::fetchAllPages(fn (int $from, int $to) => $this->tablePage($rows, $from, $to), 24);

        $this->assertNull($result['error']);
        $this->assertSame($rows, $result['data']);
    }

    public function test_a_cap_that_shrinks_a_page_below_what_was_asked_for_still_ends_up_with_everything(): void
    {
        // A stand in for max_rows: whatever range is asked for, at most 10 rows
        // come back. Fewer than asked for used to be read as the last page.
        $rows = range(0, 32);
        $cap = 10;
        $result = GalleryQuery::fetchAllPages(
            fn (int $from, int $to) => $this->tablePage($rows, $from, min($to, $from + $cap - 1)),
            24,
        );

        $this->assertNull($result['error']);
        $this->assertSame($rows, $result['data']);
    }

    public function test_an_empty_table_comes_back_as_an_empty_array_not_an_extra_request(): void
    {
        $calls = 0;
        $result = GalleryQuery::fetchAllPages(function (int $from, int $to) use (&$calls) {
            $calls++;

            return $this->tablePage([], $from, $to);
        }, 24);

        $this->assertNull($result['error']);
        $this->assertSame([], $result['data']);
        $this->assertSame(1, $calls);
    }

    public function test_an_error_partway_through_is_surfaced_rather_than_swallowed(): void
    {
        $rows = range(0, 44);
        $calls = 0;

        $result = GalleryQuery::fetchAllPages(function (int $from, int $to) use (&$calls, $rows) {
            $calls++;
            if ($calls === 2) {
                throw new SupabaseException('boom', 500);
            }

            return $this->tablePage($rows, $from, $to);
        }, 24);

        $this->assertSame('boom', $result['error']);
    }

    public function test_a_page_inside_the_table_comes_back_whole_no_count_query_needed(): void
    {
        $countCalls = 0;
        $result = GalleryQuery::fetchPage(
            fn () => ['rows' => [1, 2, 3], 'count' => 3],
            function () use (&$countCalls) {
                $countCalls++;

                return ['rows' => [], 'count' => 3];
            },
        );

        $this->assertNull($result['error']);
        $this->assertSame([1, 2, 3], $result['data']);
        $this->assertSame(3, $result['count']);
        $this->assertSame(0, $countCalls);
    }

    public function test_an_offset_past_the_last_row_comes_back_as_an_empty_page_against_the_real_total_not_an_error(): void
    {
        $result = GalleryQuery::fetchPage(
            fn () => throw new SupabaseException('Requested range not satisfiable', 416, 'PGRST103'),
            fn () => ['rows' => [], 'count' => 4],
        );

        $this->assertNull($result['error']);
        $this->assertSame([], $result['data']);
        $this->assertSame(4, $result['count']);
    }

    public function test_a_failed_follow_up_count_still_surfaces_an_error_rather_than_a_false_empty_page(): void
    {
        $result = GalleryQuery::fetchPage(
            fn () => throw new SupabaseException('Requested range not satisfiable', 416, 'PGRST103'),
            fn () => throw new SupabaseException('boom', 500),
        );

        $this->assertSame('boom', $result['error']);
        $this->assertSame([], $result['data']);
    }

    public function test_an_error_that_is_not_the_offset_past_end_code_is_passed_straight_through(): void
    {
        $countCalls = 0;
        $result = GalleryQuery::fetchPage(
            fn () => throw new SupabaseException('connection refused', 503, 'PGRST000'),
            function () use (&$countCalls) {
                $countCalls++;

                return ['rows' => [], 'count' => 0];
            },
        );

        $this->assertSame('connection refused', $result['error']);
        $this->assertSame([], $result['data']);
        $this->assertSame(0, $countCalls);
    }

    public function test_a_short_listing_offers_every_page_and_no_gap(): void
    {
        $this->assertSame([1], GalleryQuery::pageNumbers(1, 1));
        $this->assertSame([1, 2, 3, 4, 5, 6], GalleryQuery::pageNumbers(3, 6));
    }

    public function test_the_first_and_the_last_page_are_always_offered(): void
    {
        $steps = GalleryQuery::pageNumbers(40, 90);

        $this->assertSame(1, $steps[0]);
        $this->assertSame(90, $steps[count($steps) - 1]);
        $this->assertContains(GalleryQuery::PAGE_GAP, $steps);
    }

    public function test_the_run_around_the_current_page_is_five_wide(): void
    {
        $this->assertSame(
            [1, GalleryQuery::PAGE_GAP, 38, 39, 40, 41, 42, GalleryQuery::PAGE_GAP, 90],
            GalleryQuery::pageNumbers(40, 90),
        );
    }

    public function test_a_gap_is_only_drawn_where_a_page_is_actually_missing(): void
    {
        $this->assertSame([1, 2, 3, 4, 5, GalleryQuery::PAGE_GAP, 90], GalleryQuery::pageNumbers(3, 90));
        $this->assertSame([1, GalleryQuery::PAGE_GAP, 86, 87, 88, 89, 90], GalleryQuery::pageNumbers(88, 90));
    }

    public function test_a_page_outside_the_listing_still_gets_a_usable_pager(): void
    {
        $this->assertSame([1, 2, 3], GalleryQuery::pageNumbers(40, 3));
        $this->assertSame([1, 2, 3, GalleryQuery::PAGE_GAP, 5], GalleryQuery::pageNumbers(0, 5));
        $this->assertSame([1], GalleryQuery::pageNumbers(-2, 1));
    }

    public function test_the_summary_columns_include_author_id(): void
    {
        $this->assertContains('author_id', explode(',', GalleryQuery::ITEM_SUMMARY_COLUMNS));
    }

    public function test_every_key_a_gallery_link_can_carry_is_one_the_api_accepts(): void
    {
        $href = GalleryQuery::filterHref(
            new Filters(
                kind: [Container::GALLERY_KINDS[0]],
                game: 'BA',
                map: 'Comet',
                tag: ['water'],
                author: ['Ada'],
                account: ['abc'],
                q: 'rush',
                sort: 'title',
                page: 3,
            ),
            ['page' => 3],
        );
        $keys = SearchParams::parse(explode('?', $href)[1])->keys();

        $this->assertCount(9, $keys);
        foreach ($keys as $key) {
            $this->assertContains($key, ApiItems::FILTER_KEYS);
        }
    }

    public function test_search_params_read_repeated_keys_and_form_encoding(): void
    {
        $params = SearchParams::parse('kind=a&kind=b&q=x+y&q2=%20');

        $this->assertSame(['a', 'b'], $params->getAll('kind'));
        $this->assertSame('x y', $params->get('q'));
        $this->assertSame(' ', $params->get('q2'));
        $this->assertSame(['kind', 'q', 'q2'], $params->keys());
    }
}
