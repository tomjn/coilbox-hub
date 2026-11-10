<?php

namespace Tests\Unit\Profile;

use App\Domain\Gallery\GalleryQuery;
use App\Domain\Profile\Contributions;
use App\Supabase\Supabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** Ported from the Next app's `lib/profile/contributions.test.ts`. */
class ContributionsTest extends TestCase
{
    private const ACCOUNT = 'c0000000-0000-0000-0000-000000000001';

    private const BLANKET = [
        'id' => '00000000-0000-0000-0000-000000000001',
        'game' => null,
        'map_name' => null,
        'all_maps' => true,
        'licence' => null,
        'redistribute_extracted' => 'allowed',
        'redistribute_rendered' => 'allowed',
    ];

    private const DENIED = [
        'id' => '00000000-0000-0000-0000-000000000002',
        'game' => null,
        'map_name' => 'Zyx Taken Down',
        'all_maps' => null,
        'licence' => null,
        'redistribute_extracted' => 'denied',
        'redistribute_rendered' => 'denied',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.supabase.url' => 'https://sb.test', 'hub.supabase.publishable_key' => 'pk']);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function held(array $extra = []): array
    {
        return $extra + [
            'profile' => [['user_id' => self::ACCOUNT, 'display_name' => 'Zyx Contributor']],
            'asset_licence' => [self::BLANKET],
        ];
    }

    /**
     * Answers from rows, matching `eq.` filters on a column and `not.in.` on the
     * map name list. Which rows a role may read is the migrations' business, so
     * the fake returns whatever it is given and the tests check what is asked.
     */
    private function fakeTables(array $tables): void
    {
        Http::fake(function (Request $request) use ($tables) {
            $url = parse_url($request->url());
            $table = rawurldecode(basename($url['path']));
            $eqs = [];
            $excluded = [];
            $limit = PHP_INT_MAX;
            foreach (explode('&', $url['query'] ?? '') as $pair) {
                if ($pair === '') {
                    continue;
                }
                [$name, $value] = array_map('rawurldecode', explode('=', $pair, 2));
                if (str_starts_with($value, 'eq.')) {
                    $eqs[$name] = substr($value, 3);
                } elseif ($name === 'map_name' && str_starts_with($value, 'not.in.')) {
                    $excluded[] = $value;
                } elseif ($name === 'limit') {
                    $limit = (int) $value;
                }
            }
            $all = array_values(array_filter($tables[$table] ?? [], function ($row) use ($eqs, $excluded) {
                foreach ($eqs as $column => $value) {
                    if ((string) ($row[$column] ?? '') !== $value) {
                        return false;
                    }
                }
                foreach ($excluded as $list) {
                    if (str_contains($list, (string) ($row['map_name'] ?? "\0"))) {
                        return false;
                    }
                }

                return true;
            }));
            $held = array_slice($all, 0, $limit);
            $range = $held === [] ? '*/'.count($all) : '0-'.(count($held) - 1).'/'.count($all);

            return Http::response($held, 200, ['Content-Range' => $range]);
        });
    }

    private function load(array $tables): ?array
    {
        $this->fakeTables($tables);

        return Contributions::load(Supabase::anon(), Supabase::admin(), self::ACCOUNT);
    }

    /** @return list<Request> */
    private function requestsTo(string $table): array
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), '/rest/v1/'.$table.'?'))
            ->map(fn ($pair) => $pair[0])->values()->all();
    }

    public function test_an_id_that_is_not_a_uuid_is_not_an_account(): void
    {
        $this->assertNull(Contributions::parseAccountId('not-a-uuid'));
        $this->assertNull(Contributions::parseAccountId(''));
        $this->assertNull(Contributions::parseAccountId(self::ACCOUNT.'x'));
        $this->assertNull(Contributions::parseAccountId('c0000000-0000-0000-0000-00000000000g'));
    }

    public function test_a_uuid_is_accepted_and_lower_cased(): void
    {
        $this->assertSame(self::ACCOUNT, Contributions::parseAccountId(strtoupper(self::ACCOUNT)));
    }

    public function test_an_account_with_no_profile_row_has_no_profile_page(): void
    {
        $this->assertNull($this->load(['profile' => []] + $this->held()));
    }

    public function test_an_account_that_has_contributed_nothing_is_a_profile_of_zeros(): void
    {
        $this->assertSame([
            'name' => 'Zyx Contributor',
            'maps' => ['count' => 0, 'recent' => []],
            'pictures' => ['count' => 0],
            'games' => ['count' => 0, 'recent' => []],
            'items' => ['count' => 0, 'recent' => []],
        ], $this->load($this->held()));
    }

    public function test_each_kind_of_contribution_is_counted_for_this_account_and_nobody_else(): void
    {
        $other = 'c0000000-0000-0000-0000-000000000002';
        $profile = $this->load($this->held([
            'map' => [
                ['map_name' => 'Zyx One', 'slug' => 'zyx-one', 'display_name' => 'Zyx One Remake', 'submitted_by' => self::ACCOUNT],
                ['map_name' => 'Zyx Two', 'slug' => 'zyx-two', 'display_name' => null, 'submitted_by' => self::ACCOUNT],
                ['map_name' => 'Other', 'slug' => 'other', 'display_name' => null, 'submitted_by' => $other],
            ],
            'asset' => [
                ['uploaded_by' => self::ACCOUNT],
                ['uploaded_by' => self::ACCOUNT],
                ['uploaded_by' => self::ACCOUNT],
                ['uploaded_by' => $other],
            ],
            'game' => [
                ['shortname' => 'ZYX', 'display_name' => 'Zyx Game', 'submitted_by' => self::ACCOUNT],
                ['shortname' => 'OTHER', 'display_name' => null, 'submitted_by' => $other],
            ],
            'item' => [
                ['id' => 'i1', 'title' => 'Zyx item', 'author_id' => self::ACCOUNT],
                ['id' => 'i2', 'title' => 'Other item', 'author_id' => $other],
            ],
        ]));

        $this->assertSame(2, $profile['maps']['count']);
        $this->assertSame([
            ['slug' => 'zyx-one', 'label' => 'Zyx One Remake'],
            ['slug' => 'zyx-two', 'label' => 'Zyx Two'],
        ], $profile['maps']['recent']);
        $this->assertSame(3, $profile['pictures']['count']);
        $this->assertSame(['count' => 1, 'recent' => [['shortname' => 'ZYX', 'label' => 'Zyx Game']]], $profile['games']);
        $this->assertSame(['count' => 1, 'recent' => [['id' => 'i1', 'title' => 'Zyx item']]], $profile['items']);
    }

    public function test_a_game_with_no_display_name_is_listed_by_its_short_name(): void
    {
        $profile = $this->load($this->held([
            'game' => [['shortname' => 'ZYX', 'display_name' => null, 'submitted_by' => self::ACCOUNT]],
        ]));

        $this->assertSame([['shortname' => 'ZYX', 'label' => 'ZYX']], $profile['games']['recent']);
    }

    public function test_a_map_the_licence_gate_refuses_is_neither_counted_nor_listed(): void
    {
        $profile = $this->load($this->held([
            'asset_licence' => [self::BLANKET, self::DENIED],
            'map' => [
                ['map_name' => 'Zyx Fine', 'slug' => 'zyx-fine', 'display_name' => null, 'submitted_by' => self::ACCOUNT],
                ['map_name' => 'Zyx Taken Down', 'slug' => 'zyx-taken-down', 'display_name' => null, 'submitted_by' => self::ACCOUNT],
            ],
        ]));

        $this->assertSame(1, $profile['maps']['count']);
        $this->assertSame([['slug' => 'zyx-fine', 'label' => 'Zyx Fine']], $profile['maps']['recent']);
    }

    public function test_an_unreadable_licence_gate_fails_the_page_rather_than_listing_every_map(): void
    {
        Http::fake([
            'https://sb.test/rest/v1/asset_licence*' => Http::response(['message' => 'down', 'code' => 'XX000'], 500),
            'https://sb.test/rest/v1/profile*' => Http::response([['user_id' => self::ACCOUNT, 'display_name' => 'Zyx Contributor']], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('licence gate');
        Contributions::load(Supabase::anon(), Supabase::admin(), self::ACCOUNT);
    }

    public function test_recent_lists_are_newest_first_and_stop_at_the_gallery_page_size(): void
    {
        $this->load($this->held());

        foreach (['map', 'game', 'item'] as $table) {
            $own = $this->requestsTo($table);
            $this->assertCount(1, $own, $table);
            parse_str(parse_url($own[0]->url(), PHP_URL_QUERY), $query);
            $this->assertSame('created_at.desc', $query['order'], $table);
            $this->assertSame((string) GalleryQuery::PAGE_SIZE, $query['limit'], $table);
        }
    }

    public function test_pictures_are_counted_without_fetching_rows(): void
    {
        $this->load($this->held());

        $own = $this->requestsTo('asset');
        $this->assertCount(1, $own);
        $this->assertStringContainsString('count=exact', $own[0]->header('Prefer')[0] ?? '');
        $this->assertSame('HEAD', $own[0]->method());
    }
}
