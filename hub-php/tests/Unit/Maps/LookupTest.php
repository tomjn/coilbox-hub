<?php

namespace Tests\Unit\Maps;

use App\Domain\Maps\Have;
use App\Domain\Maps\Lookup;
use App\Domain\Maps\Packs;
use App\Supabase\Supabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Ported from `lib/maps/lookup.test.ts`, `lib/maps/have.test.ts` and `lib/maps/packs.test.ts`. */
class LookupTest extends TestCase
{
    private const COMET = 'Comet Catcher Remake 1.8';

    private const TAKEN_DOWN = 'Taken Down 1.0';

    /** @var list<string> The decoded query strings Supabase was sent. */
    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'sb_publishable_x', 'hub.supabase.service_role_key' => 'sb_secret_x']);
        Http::preventStrayRequests();
        $this->installFake();
    }

    private static function licence(array $overrides = []): array
    {
        return $overrides + ['map_name' => null, 'all_maps' => null, 'redistribute_extracted' => 'denied', 'redistribute_rendered' => 'denied'];
    }

    private static function blanket(): array
    {
        return self::licence(['all_maps' => true, 'redistribute_extracted' => 'allowed', 'redistribute_rendered' => 'allowed']);
    }

    private static function facts(string $slug): array
    {
        return ['slug' => $slug, 'tags' => ['large'], 'points' => ['start' => [], 'metal' => [], 'geo' => []], 'appearance' => []];
    }

    /** @var array<string, list<array<string, mixed>>|null> */
    private array $answers = [];

    private ?int $count = null;

    /**
     * Answers each table or function with its rows, or a 503 when given null.
     * `Http::fake()` keeps its first stub, so one stub reads these properties.
     *
     * @param  array<string, list<array<string, mixed>>|null>  $answers
     */
    private function fake(array $answers, ?int $count = null): void
    {
        $this->answers = $answers;
        $this->count = $count;
    }

    private function installFake(): void
    {
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $this->seen[] = urldecode((string) parse_url($request->url(), PHP_URL_QUERY));
            $name = preg_replace('#^/rest/v1/(rpc/)?#', '', $path);
            $rows = array_key_exists($name, $this->answers) ? $this->answers[$name] : [];
            if ($rows === null) {
                return Http::response(['message' => 'down'], 503);
            }

            return Http::response($rows, 200, ['Content-Range' => '0-'.max(0, count($rows) - 1).'/'.($this->count ?? count($rows))]);
        });
    }

    public function test_a_map_the_hub_holds_comes_back_keyed_on_its_name(): void
    {
        $this->fake(['map_facts' => [['map_name' => self::COMET, 'facts' => self::facts('comet')]], 'asset_licence' => [self::blanket()]]);

        $facts = Lookup::facts(Supabase::admin(), [self::COMET, 'Never 1.0']);

        $this->assertSame([self::COMET], array_keys($facts));
        $this->assertSame('comet', $facts[self::COMET]['slug']);
        // An empty appearance object stays an object on the way back out.
        $this->assertSame('{}', json_encode($facts[self::COMET]['appearance']));
    }

    public function test_a_map_taken_down_is_withheld_and_one_still_rendered_keeps_its_facts(): void
    {
        $this->fake([
            'map_facts' => [
                ['map_name' => self::COMET, 'facts' => self::facts('comet')],
                ['map_name' => self::TAKEN_DOWN, 'facts' => self::facts('down')],
                ['map_name' => 'Rendered 1.0', 'facts' => self::facts('rendered')],
            ],
            'asset_licence' => [self::blanket(), self::licence(['map_name' => self::TAKEN_DOWN]), self::licence(['map_name' => 'Rendered 1.0', 'redistribute_rendered' => 'allowed'])],
        ]);

        $this->assertSame([self::COMET, 'Rendered 1.0'], array_keys(Lookup::facts(Supabase::admin(), [self::COMET, self::TAKEN_DOWN, 'Rendered 1.0'])));
    }

    public function test_with_no_licence_row_at_all_nothing_is_published(): void
    {
        $this->fake(['map_facts' => [['map_name' => self::COMET, 'facts' => self::facts('comet')]], 'asset_licence' => []]);

        $this->assertSame([], Lookup::facts(Supabase::admin(), [self::COMET]));
        $this->assertSame([], Lookup::publishedNames(Supabase::admin(), [self::COMET]));
    }

    public function test_a_read_that_fails_says_so(): void
    {
        $this->fake(['map_facts' => null, 'asset_licence' => [self::blanket()]]);
        $this->assertNull(Lookup::facts(Supabase::admin(), [self::COMET]));

        $this->fake(['map_facts' => [], 'asset_licence' => null]);
        $this->assertNull(Lookup::facts(Supabase::admin(), [self::COMET]));
        $this->assertNull(Lookup::publishedNames(Supabase::admin(), [self::COMET]));
    }

    public function test_the_licences_are_one_request_per_hundred_names(): void
    {
        $this->fake(['asset_licence' => [self::blanket()]]);
        $names = array_map(fn (int $i) => 'Map '.$i, range(0, 249));

        Lookup::facts(Supabase::admin(), $names);

        $filters = array_values(array_filter($this->seen, fn ($q) => str_starts_with($q, 'select=*&or=')));
        $this->assertCount(3, $filters);
        $this->assertStringStartsWith('select=*&or=(all_maps.is.true,', $filters[0]);
        $this->assertStringContainsString('map_name.eq."Map 0"', $filters[0]);
        $this->assertStringContainsString('map_name.eq."Map 249"', $filters[2]);
    }

    public function test_the_sitemap_names_leave_out_a_map_taken_down(): void
    {
        $this->fake(['asset_licence' => [self::blanket(), self::licence(['map_name' => self::TAKEN_DOWN])]]);

        $this->assertSame([self::COMET], Lookup::publishedNames(Supabase::admin(), [self::COMET, self::TAKEN_DOWN]));
    }

    public function test_the_listing_rule(): void
    {
        $this->fake(['asset_licence' => [self::blanket(), self::licence(['map_name' => self::TAKEN_DOWN])]]);
        $this->assertSame(['kind' => 'except', 'names' => [self::TAKEN_DOWN]], Lookup::listingRule(Supabase::admin()));
        $this->assertStringContainsString('or=(all_maps.is.true,map_name.not.is.null)', end($this->seen));

        $this->fake(['asset_licence' => [self::blanket(), self::licence(['map_name' => self::COMET, 'redistribute_rendered' => 'allowed'])]]);
        $this->assertSame(['kind' => 'except', 'names' => []], Lookup::listingRule(Supabase::admin()));

        $this->fake(['asset_licence' => [self::licence(['all_maps' => true]), self::licence(['map_name' => self::COMET, 'redistribute_extracted' => 'allowed']), self::licence(['map_name' => self::TAKEN_DOWN])]]);
        $this->assertSame(['kind' => 'only', 'names' => [self::COMET]], Lookup::listingRule(Supabase::admin()));

        $this->fake(['asset_licence' => []]);
        $this->assertSame(['kind' => 'only', 'names' => []], Lookup::listingRule(Supabase::admin()));
    }

    public function test_a_licence_read_cut_short_or_failed_is_not_a_rule(): void
    {
        $this->fake(['asset_licence' => [self::blanket()]], 2);
        $this->assertNull(Lookup::listingRule(Supabase::admin()));

        $this->fake(['asset_licence' => null]);
        $this->assertNull(Lookup::listingRule(Supabase::admin()));
    }

    private function filterFor(array $rule): string
    {
        $this->fake(['map_listing' => []]);
        Lookup::applyListingRule(Supabase::anon()->from('map_listing')->select('slug'), $rule)->get();

        return end($this->seen);
    }

    public function test_the_listing_rule_becomes_a_filter_with_every_name_quoted(): void
    {
        $this->assertSame('select=slug&map_name=not.in.("Taken Down 1.0","A, \"B\" 2.0")', $this->filterFor(['kind' => 'except', 'names' => [self::TAKEN_DOWN, 'A, "B" 2.0']]));
        $this->assertSame('select=slug', $this->filterFor(['kind' => 'except', 'names' => []]));
        $this->assertSame('select=slug&map_name=in.("Comet Catcher Remake 1.8")', $this->filterFor(['kind' => 'only', 'names' => [self::COMET]]));
        $this->assertSame('select=slug&map_name=in.()', $this->filterFor(['kind' => 'only', 'names' => []]));
    }

    public function test_rule_lists_map(): void
    {
        $this->assertTrue(Lookup::ruleListsMap(['kind' => 'except', 'names' => [self::TAKEN_DOWN]], self::COMET));
        $this->assertFalse(Lookup::ruleListsMap(['kind' => 'except', 'names' => [self::TAKEN_DOWN]], self::TAKEN_DOWN));
        $this->assertTrue(Lookup::ruleListsMap(['kind' => 'only', 'names' => [self::COMET]], self::COMET));
        $this->assertFalse(Lookup::ruleListsMap(['kind' => 'only', 'names' => [self::COMET]], self::TAKEN_DOWN));
    }

    public function test_a_name_filter_is_an_equality_on_the_quoted_name(): void
    {
        $this->assertSame('map_name.eq."Comet Catcher Remake 1.8"', Have::nameFilter(self::COMET));
        $this->assertSame('map_name.eq."a,b.c(d):e\"f\\\\g"', Have::nameFilter('a,b.c(d):e"f\\g'));
        $names = array_map(fn (int $i) => 'A Long Enough Map Name To Be Realistic '.$i, range(0, 99));
        $this->assertLessThan(8000, strlen(rawurlencode(implode(',', array_map(Have::nameFilter(...), $names)))));
    }

    public function test_have_reads_the_two_columns_that_decide_a_status(): void
    {
        $this->fake(['map' => [['map_name' => self::COMET, 'source_hash' => 'src-a', 'catalog_version' => 3]]]);
        $this->assertSame([self::COMET => ['sourceHash' => 'src-a', 'catalogVersion' => 3]], Have::fetch(Supabase::admin(), [self::COMET, 'Other']));

        $this->fake(['map' => null]);
        $this->assertNull(Have::fetch(Supabase::admin(), [self::COMET]));
    }

    private const PACK_LICENCES = [
        ['all_maps' => true, 'map_name' => null, 'redistribute_extracted' => 'allowed', 'redistribute_rendered' => 'allowed'],
        ['all_maps' => null, 'map_name' => 'Gone 1', 'redistribute_extracted' => 'denied', 'redistribute_rendered' => 'denied'],
    ];

    public function test_packs_come_back_featured_first_then_by_title_each_with_its_maps(): void
    {
        $this->fake([
            'asset_licence' => self::PACK_LICENCES,
            'map_pack' => [
                ['id' => 'b', 'title' => 'Beta', 'blurb' => null, 'featured_at' => null],
                ['id' => 'old', 'title' => 'Old', 'blurb' => null, 'featured_at' => '2026-01-01T00:00:00Z'],
                ['id' => 'a', 'title' => 'Alpha', 'blurb' => null, 'featured_at' => null],
                ['id' => 'new', 'title' => 'New', 'blurb' => 'x', 'featured_at' => '2026-09-01T00:00:00Z'],
            ],
            'map_pack_entry' => [
                ['pack_id' => 'new', 'map_name' => 'Isis 1.3', 'slug' => 'isis-1-3', 'display_name' => null, 'archive_filename' => 'isis_1.3.sd7'],
                ['pack_id' => 'new', 'map_name' => 'Gone 1', 'slug' => null, 'display_name' => null, 'archive_filename' => null],
            ],
        ]);

        $result = Packs::fetch(Supabase::anon(), Supabase::admin());

        $this->assertNull($result['error']);
        $this->assertSame(['new', 'old', 'a', 'b'], array_column($result['packs'], 'id'));
        $this->assertSame([
            ['mapName' => 'Isis 1.3', 'slug' => 'isis-1-3', 'displayName' => null, 'archiveFilename' => 'isis_1.3.sd7', 'takenDown' => false],
            ['mapName' => 'Gone 1', 'slug' => null, 'displayName' => null, 'archiveFilename' => null, 'takenDown' => true],
        ], $result['packs'][0]['maps']);
        $this->assertSame([], $result['packs'][1]['maps']);
    }

    public function test_a_failed_pack_read_is_an_error_not_an_empty_list(): void
    {
        $this->fake(['asset_licence' => self::PACK_LICENCES, 'map_pack' => [], 'map_pack_entry' => null]);
        $result = Packs::fetch(Supabase::anon(), Supabase::admin());
        $this->assertSame('down', $result['error']);
        $this->assertSame([], $result['packs']);

        $this->fake(['asset_licence' => null, 'map_pack' => [['id' => 'a', 'title' => 'Alpha', 'blurb' => null, 'featured_at' => null]]]);
        $result = Packs::fetch(Supabase::anon(), Supabase::admin());
        $this->assertNotNull($result['error']);
        $this->assertSame([], $result['packs']);
    }
}
