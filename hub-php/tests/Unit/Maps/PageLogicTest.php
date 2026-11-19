<?php

namespace Tests\Unit\Maps;

use App\Domain\Assets\Asset;
use App\Domain\Assets\AssetResolver;
use App\Domain\Assets\Have as AssetHave;
use App\Domain\Gallery\GalleryQuery;
use App\Domain\Maps\Labels;
use App\Domain\Maps\Listing;
use App\Domain\Maps\MapPage;
use App\Domain\Maps\Report;
use App\Supabase\Supabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Ported from `lib/maps/labels.test.ts`, `query.test.ts`, `mirrors.test.ts`, `preview.test.ts` and `page.test.ts`. */
class PageLogicTest extends TestCase
{
    private const COMET = 'Comet Catcher Remake 1.8';

    public function test_labels(): void
    {
        $this->assertSame('12 x 20', Labels::size(6144, 10240));
        $this->assertSame('20 x 12', Labels::size(10240, 6144));
        $this->assertSame('12.5 x 12', Labels::size(6400, 6144));
        $this->assertEquals(['width' => 12, 'height' => 20], Labels::footprint(6144, 10240));
        $this->assertSame('8 players', Labels::players(8));
        $this->assertSame('1 player', Labels::players(1));
        $this->assertNull(Labels::players(0));
        $this->assertSame('5 to 25', Labels::wind(5, 25));
        $this->assertSame('12', Labels::wind(12, 12));
        $this->assertNull(Labels::wind(null, 25));
        $this->assertNull(Labels::wind(5, null));
        $this->assertSame('Comet Catcher Remake', Labels::title('Comet Catcher Remake', self::COMET));
        $this->assertSame(self::COMET, Labels::title(null, self::COMET));
        $this->assertSame(self::COMET, Labels::title('  ', self::COMET));
    }

    public function test_the_title_ends_with_the_maps_version_unless_the_name_already_does(): void
    {
        $this->assertSame('Persepolis v1.5', Labels::title('Persepolis', 'Persepolis v1.5', 'v1.5'));
        $this->assertSame('Aethermoor Creek 1.0', Labels::title('Aethermoor Creek', 'Aethermoor Creek 1.0', '1.0'));
        $this->assertSame('All That Glitters v2.2.3', Labels::title('All That Glitters v2.2.3', 'All That Glitters v2.2.3', '2.2.3'));
        $this->assertSame('Asteroid_Mines_V3', Labels::title('Asteroid_Mines_V3', 'Asteroid_Mines_V3', '3'));
        $this->assertSame('Angel Crossing 1.5.1', Labels::title('Angel Crossing 1.5.1', 'Angel Crossing 1.5.1', '1.5.1'));
        $this->assertSame('Comet Catcher Remake 1.8', Labels::title(null, 'Comet Catcher Remake 1.8', '1.8'));
        // A version that only matches the end of a longer number is still added.
        $this->assertSame('Grid 11 1', Labels::title('Grid 11', 'Grid 11', '1'));
        $this->assertSame('Persepolis', Labels::title('Persepolis', 'Persepolis', null));
        $this->assertSame('Persepolis', Labels::title('Persepolis', 'Persepolis', '  '));
    }

    public function test_filters_come_out_of_the_query_string(): void
    {
        $this->assertSame([
            'q' => 'comet', 'tag' => ['water map'], 'author' => '[BAR]Beherith', 'size' => 'medium', 'players' => 8, 'play' => [], 'featured' => false, 'sort' => 'players', 'page' => 3,
        ], Listing::parseFilters(['q' => 'comet', 'tag' => 'Water Map', 'author' => '[BAR]Beherith', 'size' => 'medium', 'players' => '8', 'sort' => 'players', 'page' => '3']));
    }

    public function test_unknown_and_nonsense_values_fall_back(): void
    {
        $this->assertNull(Listing::parseFilters(['size' => 'enormous'])['size']);
        $this->assertNull(Listing::parseFilters(['size' => "'; drop table map; --"])['size']);
        $small = Listing::parseFilters(['tag' => 'Small']);
        $this->assertSame([[], 'small'], [$small['tag'], $small['size']]);
        $both = Listing::parseFilters(['tag' => 'small', 'size' => 'large']);
        $this->assertSame([['small'], 'large'], [$both['tag'], $both['size']]);
        $this->assertSame(['1v1'], Listing::parseFilters(['play' => ['1v1', 'ffa', '1v1']])['play']);
        $this->assertFalse(Listing::parseFilters(['featured' => '0'])['featured']);
        $this->assertTrue(Listing::parseFilters(['featured' => '1'])['featured']);
        $several = Listing::parseFilters(['tag' => ['Water Map', 'water map', 'small']]);
        $this->assertSame([['water map'], 'small'], [$several['tag'], $several['size']]);
        $this->assertSame([], Listing::parseFilters(['tag' => ' '])['tag']);
        $this->assertSame('uploaded', Listing::parseFilters(['sort' => 'uploaded'])['sort']);
        $this->assertSame('name', Listing::parseFilters(['sort' => 'cheapest'])['sort']);
        $this->assertSame('name', Listing::parseFilters([])['sort']);
        foreach (['0', 'banana', '99999999999999999999', (string) (GalleryQuery::maxPage(Listing::PAGE_SIZE) + 1)] as $page) {
            $this->assertSame(1, Listing::parseFilters(['page' => $page])['page'], $page);
        }
        $this->assertSame(GalleryQuery::maxPage(Listing::PAGE_SIZE), Listing::parseFilters(['page' => (string) GalleryQuery::maxPage(Listing::PAGE_SIZE)])['page']);
        foreach (['0', '-4', 'lots'] as $players) {
            $this->assertNull(Listing::parseFilters(['players' => $players])['players']);
        }
        $blank = Listing::parseFilters(['q' => '   ', 'tag' => '', 'author' => '  ']);
        $this->assertSame([null, [], null], [$blank['q'], $blank['tag'], $blank['author']]);
        $this->assertSame('One', Listing::parseFilters(['author' => ['One', 'Two']])['author']);
    }

    public function test_links(): void
    {
        $this->assertSame('/maps?tag=metal', Listing::filterHref(Listing::parseFilters(['tag' => 'metal', 'mode' => 'wild']), []));
        $this->assertSame('/maps?tag=asymmetric&author=beherith&size=small', Listing::filterHref(Listing::parseFilters(['size' => 'small', 'author' => 'beherith', 'page' => '5']), ['tag' => 'asymmetric']));
        $this->assertSame('/maps?q=comet&players=8&sort=added&page=2', Listing::filterHref(Listing::parseFilters(['q' => 'comet', 'players' => '8', 'sort' => 'added']), ['page' => 2]));
        $this->assertSame('/maps', Listing::filterHref(Listing::parseFilters(['tag' => 'metal', 'sort' => 'name']), ['tag' => null]));
        $this->assertSame('/maps?author=%5BBAR%5DBeherith', Listing::filterHref(Listing::parseFilters(['author' => '[BAR]Beherith']), []));
        $this->assertFalse(Listing::isFiltered(Listing::parseFilters(['sort' => 'added', 'page' => '3'])));
        $this->assertTrue(Listing::isFiltered(Listing::parseFilters(['players' => '2'])));
        $this->assertTrue(Listing::isFiltered(Listing::parseFilters(['q' => 'comet'])));

        $href = Listing::filterHref(['q' => 'dsd', 'tag' => ['water'], 'author' => 'beherith', 'size' => 'small', 'players' => 8, 'play' => ['team'], 'featured' => true, 'sort' => 'added', 'page' => 3], ['page' => 3]);
        parse_str(parse_url($href, PHP_URL_QUERY), $keys);
        $this->assertSame(Listing::FILTER_KEYS, array_keys($keys));

        $this->assertSame('/maps?tag=0', Listing::filterHref(Listing::parseFilters(['tag' => '0']), []));
        $this->assertSame('/maps?tag=tidal&tag=windy', Listing::filterHref(Listing::parseFilters(['tag' => ['tidal', 'windy']]), []));
        $this->assertSame('/maps?tag=metal&size=small&sort=added&page=2', Listing::filterHref(Listing::parseFilters(['tag' => 'metal', 'size' => 'small', 'sort' => 'added', 'page' => '2']), ['page' => 2]));
        $this->assertTrue(Listing::isFiltered(Listing::parseFilters(['featured' => '1'])));
        $this->assertTrue(Listing::isFiltered(Listing::parseFilters(['play' => '1v1'])));
        $this->assertTrue(Listing::isFiltered(Listing::parseFilters(['sort' => 'uploaded'])));
        $this->assertTrue(Listing::isLanding(Listing::parseFilters([])));
        $this->assertFalse(Listing::isLanding(Listing::parseFilters(['page' => '2'])));
        $this->assertFalse(Listing::isLanding(Listing::parseFilters(['sort' => 'size'])));
        $this->assertFalse(Listing::isLanding(Listing::parseFilters(['featured' => '1'])));
    }

    public function test_chips(): void
    {
        $filters = Listing::parseFilters(['play' => '1v1', 'tag' => 'tidal', 'page' => '3']);
        $chips = Listing::chips($filters);
        $this->assertSame(['Featured', 'New uploads', '1v1', 'Team', 'Water', 'Tidal', 'Windy'], array_column($chips, 'label'));
        $this->assertSame([false, false, true, false, false, true, false], array_column($chips, 'active'));
        $hrefs = array_column($chips, 'href', 'label');
        $this->assertSame('/maps?tag=tidal', $hrefs['1v1']);
        $this->assertSame('/maps?tag=tidal&play=1v1&play=team', $hrefs['Team']);
        $this->assertSame('/maps?tag=tidal&tag=water+map&play=1v1', $hrefs['Water']);
        $this->assertSame('/maps?tag=tidal&play=1v1&sort=uploaded', $hrefs['New uploads']);
        $this->assertSame('/maps?tag=tidal&play=1v1&featured=1', $hrefs['Featured']);
        foreach ($hrefs as $href) {
            $this->assertStringNotContainsString('page=', $href);
        }

        $uploaded = array_column(Listing::chips(Listing::parseFilters(['sort' => 'uploaded'])), 'href', 'label');
        $this->assertSame('/maps', $uploaded['New uploads']);
        $this->assertSame('/maps?featured=1&sort=uploaded', $uploaded['Featured']);
    }

    public function test_with_tag_adds_a_tag_once(): void
    {
        $filters = Listing::parseFilters(['tag' => 'tidal']);
        $this->assertSame(['tag' => ['tidal', 'windy']], Listing::withTag($filters, 'windy'));
        $this->assertSame(['tag' => ['tidal']], Listing::withTag($filters, 'tidal'));
    }

    /** What each filter and sort asks PostgREST for, read off the request the query sends. */
    public function test_filters_and_orders_become_one_query(): void
    {
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'sb_publishable_x']);
        $sent = [];
        Http::fake(function (Request $request) use (&$sent) {
            $sent[] = urldecode((string) parse_url($request->url(), PHP_URL_QUERY));

            return Http::response([]);
        });

        $filters = Listing::parseFilters(['q' => 'comet "remake"', 'tag' => 'Water Map', 'size' => 'small', 'players' => '8', 'sort' => 'size']);
        Listing::applyOrder(Listing::applyFilters(Supabase::anon()->from('map_browse')->select('slug'), $filters, 'beherith'), $filters['sort'])->get();
        $this->assertSame('select=slug&tags=cs.{"water map"}&tags=cs.{small}&author_keys=cs.{beherith}&start_positions=gte.8&search=wfts.comet "remake"&order=longer_edge_elmos.desc,map_name.asc', $sent[0]);

        Listing::applyOrder(Supabase::anon()->from('map_browse')->select('slug'), 'name')->get();
        $this->assertSame('select=slug&order=map_name.asc', $sent[1]);

        $uploaded = Listing::parseFilters(['featured' => '1', 'play' => ['1v1', 'team'], 'sort' => 'uploaded']);
        Listing::applyOrder(Listing::applyFilters(Supabase::anon()->from('map_browse')->select('slug'), $uploaded, null), $uploaded['sort'])->get();
        $this->assertSame('select=slug&featured_at=not.is.null&play_types=ov.{1v1,team}&uploaded_at=not.is.null&order=uploaded_at.desc,map_name.asc', $sent[2]);
    }

    public function test_an_author_key_is_folded_here_then_follows_a_merge(): void
    {
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'sb_publishable_x']);
        Http::fake(['sb.test/rest/v1/author_alias*' => Http::response([['to_key' => 'beherith-merged']])]);

        $this->assertNull(Listing::resolveAuthorKey(Supabase::anon(), null));
        $this->assertSame('beherith-merged', Listing::resolveAuthorKey(Supabase::anon(), '[BAR]Beherith'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/author_alias?select=to_key&from_key=eq.beherith'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/rpc/'));
    }

    public function test_an_author_without_a_merge_keeps_the_folded_key(): void
    {
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'sb_publishable_x']);
        Http::fake(['sb.test/rest/v1/author_alias*' => Http::response([])]);

        $this->assertSame('beherith', Listing::resolveAuthorKey(Supabase::anon(), ' (BAR) Beherith! '));
        $this->assertSame('', Listing::resolveAuthorKey(Supabase::anon(), '[BAR]'));
    }

    private const HOST = ['name' => 'hakora', 'url_template' => 'http://hakora.xyz/files/springrts/maps/{filename}', 'enabled' => true];

    public function test_mirror_links(): void
    {
        $file = 'comet_catcher_remake_1.8.sd7';
        $this->assertSame([], MapPage::mirrorLinks([['enabled' => false] + self::HOST], self::COMET, $file));
        $this->assertSame([
            ['name' => 'hakora', 'url' => 'http://hakora.xyz/files/springrts/maps/'.$file],
            ['name' => 'elsewhere', 'url' => 'http://hakora.xyz/files/springrts/maps/'.$file],
        ], MapPage::mirrorLinks([self::HOST, ['name' => 'elsewhere'] + self::HOST], self::COMET, $file));
        $this->assertSame('https://example.test/search?q=Comet%20Catcher%20Remake%201.8', MapPage::mirrorUrl('https://example.test/search?q={springname}', self::COMET, $file));
        $this->assertSame('https://example.test/comet%20catcher%20%5Bv2%5D.sd7', MapPage::mirrorUrl('https://example.test/{filename}', self::COMET, 'comet catcher [v2].sd7'));
        $this->assertSame("https://example.test/it's(1)!", MapPage::mirrorUrl('https://example.test/{springname}', "it's(1)!", null));
        $this->assertNull(MapPage::mirrorUrl(self::HOST['url_template'], self::COMET, null));
        $this->assertSame([], MapPage::mirrorLinks([self::HOST], self::COMET, '  '));
        $this->assertSame(
            [['name' => 'springfiles', 'url' => 'https://example.test/?q=Comet%20Catcher%20Remake%201.8']],
            MapPage::mirrorLinks([['name' => 'springfiles', 'url_template' => 'https://example.test/?q={springname}'] + self::HOST], self::COMET, null),
        );
    }

    public function test_marker_positions(): void
    {
        $this->assertEquals(['left' => 50, 'top' => 90], MapPage::markerPosition(['x' => 3072, 'z' => 9216], 6144, 10240));
        $this->assertEquals(['left' => 25, 'top' => 75], MapPage::markerPosition(['x' => 1024, 'z' => 3072], 4096, 4096));
    }

    private static function facts(array $overrides = []): array
    {
        return $overrides + [
            'width_elmos' => 6144, 'height_elmos' => 10240, 'world_height_min' => -400, 'world_height_max' => 1200,
            'void_water' => null, 'appearance' => [],
            'points' => ['start' => [['x' => 512, 'z' => 512, 'y' => null, 'meta' => null]], 'metal' => [['x' => 1, 'z' => 2, 'y' => 3, 'meta' => ['amount' => 2]]], 'geo' => []],
        ];
    }

    private static function held(array $overlay = []): array
    {
        return [AssetHave::identityKey(AssetResolver::map(self::COMET, Asset::MAP_HEIGHT_OVERLAY_VARIANT)) => $overlay + [
            'tier' => 'static', 'path' => 'maps/overlay/height/ghi.webp', 'width' => 512, 'height' => 512,
            'moderation' => 'approved', 'world_height_min' => -120.5, 'world_height_max' => 890,
        ]];
    }

    private const SERVED = ['from' => 'static', 'url' => 'https://cdn.test/maps/minimap/def.webp', 'width' => 512, 'height' => 512];

    public function test_a_preview_needs_both_pictures_and_the_overlays_own_range(): void
    {
        $preview = MapPage::preview(self::COMET, self::facts(), self::held(), self::SERVED);

        $this->assertSame(AssetResolver::assetTierUrl('static', 'maps/overlay/height/ghi.webp'), $preview['heightUrl']);
        $this->assertSame(['min' => -120.5, 'max' => 890], $preview['range']);
        $this->assertSame(self::SERVED['url'], $preview['textureUrl']);
        $this->assertFalse($preview['voidWater']);
        $this->assertSame(['start' => [['x' => 512, 'z' => 512]], 'metal' => [['x' => 1, 'z' => 2]], 'geo' => []], $preview['points']);
        $this->assertTrue(MapPage::preview(self::COMET, self::facts(['void_water' => true]), self::held(), self::SERVED)['voidWater']);

        $this->assertNull(MapPage::preview(self::COMET, self::facts(), [], self::SERVED));
        $this->assertNull(MapPage::preview(self::COMET, self::facts(), self::held(['moderation' => 'pending']), self::SERVED));
        $this->assertNull(MapPage::preview(self::COMET, self::facts(), self::held(['moderation' => 'rejected']), self::SERVED));
        $this->assertNull(MapPage::preview(self::COMET, self::facts(), self::held(), ['from' => 'placeholder']));
        $this->assertNull(MapPage::preview(self::COMET, self::facts(), self::held(['world_height_max' => null]), self::SERVED));
    }

    public function test_appearance(): void
    {
        $nothing = ['water' => null, 'waterAlpha' => null, 'sunDirection' => null, 'sunColour' => null];
        $this->assertSame($nothing, MapPage::readAppearance([]));
        $this->assertSame($nothing, MapPage::readAppearance(new \stdClass));
        $this->assertSame(
            ['water' => [0.1, 0.35, 0.5], 'waterAlpha' => 0.4, 'sunDirection' => [0.4, 0.8, -0.2], 'sunColour' => [0.9, 0.85, 0.7]],
            MapPage::readAppearance(['waterColor' => [0.1, 0.35, 0.5], 'waterAlpha' => 0.4, 'sunColor' => [0.9, 0.85, 0.7], 'sunDir' => [0.4, 0.8, -0.2], 'skyColor' => [0, 0, 0.05]]),
        );
        foreach ([null, 'blue', 7, [1, 2, 3]] as $blob) {
            $this->assertNull(MapPage::readAppearance($blob)['water']);
        }
        $this->assertNull(MapPage::readAppearance(['waterColor' => [0.1, 0.2]])['water']);
        $this->assertNull(MapPage::readAppearance(['waterColor' => [0.1, 0.2, '0.3']])['water']);
        $this->assertNull(MapPage::readAppearance(['waterColor' => 'rgb(0,0,255)'])['water']);
        $this->assertEquals([0, 0.5, 1], MapPage::readAppearance(['waterColor' => [-1, 0.5, 255]])['water']);
        $this->assertEquals(1, MapPage::readAppearance(['waterAlpha' => 4])['waterAlpha']);
        $this->assertSame([-0.6, 2.4, 0.3], MapPage::readAppearance(['sunDir' => [-0.6, 2.4, 0.3]])['sunDirection']);
    }

    public function test_a_report_is_checked_then_filed(): void
    {
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'sb_publishable_x']);
        Http::fake(['sb.test/rest/v1/report*' => Http::sequence()->push(null, 201)->push(['message' => 'down'], 503)]);
        $anon = Supabase::anon();

        $this->assertSame(['ok' => false, 'message' => Report::MESSAGES['notSent']], Report::file($anon, null, '', 'other', 'x'));
        $this->assertSame(['ok' => false, 'message' => Report::MESSAGES['noKind']], Report::file($anon, null, 'm', 'boring', 'x'));
        $this->assertSame(['ok' => false, 'message' => Report::MESSAGES['noReason']], Report::file($anon, null, 'm', 'other', '   '));
        Http::assertNothingSent();

        $this->assertSame(['ok' => true, 'message' => 'Reported. Thank you.'], Report::file($anon, 'user-1', 'm', 'wrong_map', ' '.str_repeat('a', 1200).' '));
        Http::assertSent(fn (Request $request) => $request['map_id'] === 'm' && $request['kind'] === 'wrong_map' && strlen($request['reason']) === 1000 && $request['reporter_id'] === 'user-1');
        $this->assertSame(['ok' => false, 'message' => Report::MESSAGES['notSaved']], Report::file($anon, null, 'm', 'other', 'x'));
    }

    public function test_listing_cache_keys_change_with_the_columns_read(): void
    {
        // Pages cached before a deploy added mirror_urls kept serving cards without it.
        $this->assertSame('maps:'.substr(md5(Listing::SUMMARY_COLUMNS), 0, 8).':page:x', Listing::cacheKey('page:x'));
        $this->assertStringNotContainsString(substr(md5(str_replace(',mirror_urls', '', Listing::SUMMARY_COLUMNS)), 0, 8), Listing::cacheKey('page:x'));
    }
}
