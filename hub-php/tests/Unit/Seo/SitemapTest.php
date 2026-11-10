<?php

namespace Tests\Unit\Seo;

use App\Domain\Seo\Sitemap;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Ported from `lib/sitemap/entries.test.ts`. */
class SitemapTest extends TestCase
{
    private const ORIGIN = 'https://hub.example';

    private const EMPTY = ['items' => [], 'maps' => [], 'games' => []];

    /** @return list<string> */
    private function urls(array $source): array
    {
        return array_column(Sitemap::entries(self::ORIGIN, $source), 'url');
    }

    public function test_the_fixed_pages_are_always_listed(): void
    {
        $this->assertSame([
            'https://hub.example/',
            'https://hub.example/gallery',
            'https://hub.example/maps',
            'https://hub.example/games',
            'https://hub.example/developers',
        ], $this->urls(self::EMPTY));
    }

    public function test_each_item_map_game_and_unit_gets_a_page(): void
    {
        $urls = $this->urls([
            'items' => [['id' => '11111111-1111-4111-8111-111111111111', 'updated_at' => '2026-10-03T09:00:00.5+00:00']],
            'maps' => [['slug' => 'seed-map']],
            'games' => [['shortname' => 'tabtest', 'units' => ['armcom', 'armmex'], 'releases' => 2]],
        ]);

        foreach ([
            'https://hub.example/item/11111111-1111-4111-8111-111111111111',
            'https://hub.example/map/seed-map',
            'https://hub.example/games/tabtest',
            'https://hub.example/games/tabtest/units',
            'https://hub.example/games/tabtest/changes',
            'https://hub.example/games/tabtest/units/armcom',
            'https://hub.example/games/tabtest/units/armmex',
        ] as $url) {
            $this->assertContains($url, $urls);
        }
    }

    public function test_the_changes_page_is_listed_only_for_a_game_with_two_releases(): void
    {
        $urls = fn (int $releases) => $this->urls([...self::EMPTY, 'games' => [['shortname' => 'g', 'units' => [], 'releases' => $releases]]]);
        $this->assertNotContains('https://hub.example/games/g/changes', $urls(1));
        $this->assertContains('https://hub.example/games/g/changes', $urls(2));
        $this->assertSame([], array_filter($urls(2), fn ($url) => str_contains($url, '?')));
    }

    public function test_an_item_says_when_it_last_changed_in_a_form_a_crawler_reads(): void
    {
        $entries = Sitemap::entries(self::ORIGIN, [...self::EMPTY, 'items' => [['id' => 'a', 'updated_at' => '2026-10-03T09:00:00.5+00:00']]]);
        $this->assertSame('2026-10-03T09:00:00.500Z', end($entries)['lastModified']);
    }

    public function test_a_name_with_characters_that_need_escaping_in_a_path_is_escaped(): void
    {
        $urls = $this->urls([...self::EMPTY, 'games' => [['shortname' => 'a b', 'units' => ['x/y', 'q?z'], 'releases' => 0]]]);
        $this->assertContains('https://hub.example/games/a%20b/units/x%2Fy', $urls);
        $this->assertContains('https://hub.example/games/a%20b/units/q%3Fz', $urls);
    }

    public function test_a_sitemap_at_the_protocol_limit_is_accepted(): void
    {
        $items = array_map(fn ($i) => ['id' => (string) $i, 'updated_at' => '2026-10-03T00:00:00Z'], range(1, Sitemap::LIMIT - 5));
        $this->assertCount(Sitemap::LIMIT, Sitemap::entries(self::ORIGIN, [...self::EMPTY, 'items' => $items]));
    }

    public function test_a_sitemap_past_the_protocol_limit_fails_rather_than_being_cut_short(): void
    {
        $items = array_map(fn ($i) => ['id' => (string) $i, 'updated_at' => '2026-10-03T00:00:00Z'], range(1, Sitemap::LIMIT - 4));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('50000');
        Sitemap::entries(self::ORIGIN, [...self::EMPTY, 'items' => $items]);
    }

    public function test_the_xml_matches_what_next_writes(): void
    {
        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            ."<url>\n<loc>https://hub.example/</loc>\n</url>\n"
            ."<url>\n<loc>https://hub.example/item/a</loc>\n<lastmod>2026-10-03T00:00:00.000Z</lastmod>\n</url>\n</urlset>\n",
            Sitemap::xml([['url' => 'https://hub.example/'], ['url' => 'https://hub.example/item/a', 'lastModified' => '2026-10-03T00:00:00.000Z']]),
        );
    }
}
