<?php

namespace Tests\Unit\Gallery;

use App\Domain\Gallery\ApiItems;
use App\Domain\Gallery\GalleryQuery;
use App\Domain\Gallery\SearchParams;
use PHPUnit\Framework\TestCase;

/** Ported from `lib/api/items.test.ts`. */
class ApiItemsTest extends TestCase
{
    private const ADA = '0f8fad5b-0449-4000-8000-0000000000aa';

    private const BOB = '0f8fad5b-0449-4000-8000-0000000000bb';

    private const SUMMARY = [
        'id' => '11111111-1111-1111-1111-111111111111',
        'kind' => 'preset',
        'mode' => null,
        'title' => 'apitest-example',
        'description' => '',
        'game_name' => 'Beyond All Reason',
        'game_key' => 'Beyond All Reason',
        'map_name' => null,
        'tags' => ['eco'],
        'author_name' => 'Someone',
        'author_id' => '22222222-2222-2222-2222-222222222222',
        'created_at' => '2026-01-01T00:00:00Z',
        'featured_at' => null,
    ];

    private function parse(string $query): array
    {
        return ApiItems::parseFilters(SearchParams::parse($query));
    }

    private function assertRejected(string $error, string $query): void
    {
        $result = $this->parse($query);
        $this->assertFalse($result['ok']);
        $this->assertSame($error, $result['error']);
    }

    public function test_a_list_body_carries_the_format_marker_version_and_paging(): void
    {
        $body = ApiItems::listBody([self::SUMMARY], 2, 30);

        $this->assertSame(ApiItems::ITEMS_FORMAT, $body['format']);
        $this->assertSame(ApiItems::ITEMS_VERSION, $body['version']);
        $this->assertSame(1, $body['version']);
        $this->assertSame(2, $body['page']);
        $this->assertSame(GalleryQuery::PAGE_SIZE, $body['page_size']);
        $this->assertSame(30, $body['total']);
        $this->assertSame([self::SUMMARY], $body['items']);
    }

    public function test_an_item_body_carries_the_format_marker_version_and_a_container_url_rather_than_the_container(): void
    {
        $url = 'https://coilbox-hub.example/i/11111111-1111-1111-1111-111111111111';
        $body = ApiItems::itemBody(self::SUMMARY, $url);

        $this->assertSame(ApiItems::ITEM_FORMAT, $body['format']);
        $this->assertSame(ApiItems::ITEM_VERSION, $body['version']);
        $this->assertSame(1, $body['version']);
        $this->assertSame($url, $body['item']['container_url']);
        $this->assertSame(self::SUMMARY['id'], $body['item']['id']);
        $this->assertArrayNotHasKey('container', $body['item']);
    }

    public function test_recognised_filters_parse_the_same_way_the_websites_forgiving_parser_does(): void
    {
        $result = $this->parse('kind=preset&game=BAR&page=2');

        $this->assertTrue($result['ok']);
        $this->assertSame(['preset'], $result['filters']->kind);
        $this->assertSame('BAR', $result['filters']->game);
        $this->assertSame(2, $result['filters']->page);
    }

    public function test_an_unknown_query_parameter_is_rejected_rather_than_silently_dropped(): void
    {
        $this->assertRejected('Unknown query parameter: orderBy', 'kind=preset&orderBy=title');
    }

    public function test_an_unrecognised_kind_is_rejected_rather_than_falling_back_to_unfiltered(): void
    {
        $this->assertRejected('Unknown kind: campaign', 'kind=campaign');
    }

    public function test_no_filters_at_all_is_fine(): void
    {
        $result = $this->parse('');

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['filters']->kind);
        $this->assertSame(1, $result['filters']->page);
    }

    public function test_a_repeated_kind_is_a_union_not_the_first_value_winning(): void
    {
        $result = $this->parse('kind=blueprint&kind=preset');

        $this->assertTrue($result['ok']);
        $this->assertSame(['blueprint', 'preset'], $result['filters']->kind);
    }

    public function test_a_comma_separated_kind_list_is_rejected_with_a_hint_towards_the_repeated_form(): void
    {
        $this->assertRejected(
            'Unknown kind: preset,blueprint. Repeat kind for more than one, e.g. kind=preset&kind=blueprint.',
            'kind=preset,blueprint',
        );
    }

    public function test_repeated_author_or_tag_is_also_a_union(): void
    {
        $result = $this->parse('author=Alice&author=Bob&tag=eco&tag=pvp');

        $this->assertTrue($result['ok']);
        $this->assertSame(['Alice', 'Bob'], $result['filters']->author);
        $this->assertSame(['eco', 'pvp'], $result['filters']->tag);
    }

    public function test_a_repeated_single_value_parameter_is_rejected_rather_than_silently_taking_the_first(): void
    {
        $this->assertRejected('game takes one value, not several. Send it once.', 'game=BAR&game=BA');
    }

    public function test_sort_accepts_newest_and_title(): void
    {
        $newest = $this->parse('sort=newest');
        $title = $this->parse('sort=title');

        $this->assertTrue($newest['ok']);
        $this->assertSame('newest', $newest['filters']->sort);
        $this->assertTrue($title['ok']);
        $this->assertSame('title', $title['filters']->sort);
    }

    public function test_an_unrecognised_sort_is_rejected_rather_than_falling_back_to_newest(): void
    {
        $this->assertRejected('Unknown sort: oldest', 'sort=oldest');
    }

    public function test_the_largest_valid_page_is_accepted(): void
    {
        $last = GalleryQuery::maxPage(GalleryQuery::PAGE_SIZE);
        $result = $this->parse('page='.$last);

        $this->assertTrue($result['ok']);
        $this->assertSame($last, $result['filters']->page);
    }

    public function test_a_page_too_large_to_be_a_real_page_is_a_400_not_page_one_under_another_number(): void
    {
        $last = GalleryQuery::maxPage(GalleryQuery::PAGE_SIZE);
        foreach ([(string) ($last + 1), '99999999999999999999', str_repeat('9', 400)] as $page) {
            $this->assertRejected('page is too large. The highest page is '.$last.'.', 'page='.$page);
        }
    }

    public function test_a_page_that_is_not_a_positive_number_still_falls_back_to_the_first(): void
    {
        foreach (['0', '-1', '1.5', '1e3', 'abc', ''] as $page) {
            $result = $this->parse('page='.$page);

            $this->assertTrue($result['ok'], 'page='.$page);
            $this->assertSame(1, $result['filters']->page, 'page='.$page);
        }
    }

    public function test_a_repeated_page_is_rejected(): void
    {
        $this->assertFalse($this->parse('page=2&page=1')['ok']);
    }

    public function test_account_takes_account_ids_and_repeating_it_is_a_union(): void
    {
        $result = $this->parse('account='.self::ADA.'&account='.self::BOB);

        $this->assertTrue($result['ok']);
        $this->assertSame([self::ADA, self::BOB], $result['filters']->account);
    }

    public function test_an_account_that_is_not_a_uuid_is_rejected_not_silently_dropped(): void
    {
        $this->assertRejected('Unknown account: Alice. An account is a UUID.', 'account=Alice');
    }

    public function test_author_still_works_alongside_account(): void
    {
        $result = $this->parse('author=Alice&account='.self::ADA);

        $this->assertTrue($result['ok']);
        $this->assertSame(['Alice'], $result['filters']->author);
        $this->assertSame([self::ADA], $result['filters']->account);
    }
}
