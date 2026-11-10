<?php

namespace Tests\Unit\Assets;

use App\Domain\Assets\AssetResolver;
use App\Domain\Assets\Cdn;
use App\Domain\Assets\Have;
use App\Supabase\Supabase;
use App\Support\Site;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Ported from `lib/assets/resolve.test.ts`. */
class AssetResolverTest extends TestCase
{
    /** @var list<array<string, string>> the query parameters of each request made */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.supabase.url' => 'https://sb.test',
            'hub.supabase.publishable_key' => 'sb_publishable_test',
            'hub.asset_cdn_base' => null,
            'app.url' => 'https://hub.test',
        ]);
    }

    private function buildpic(): array
    {
        return AssetResolver::unit('bar', 'armsolar');
    }

    private function render(string $angle = '270'): array
    {
        return AssetResolver::unit('bar', 'armsolar', 'render:'.$angle);
    }

    private function minimap(): array
    {
        return AssetResolver::map('Comet Catcher Remake 1.8');
    }

    private function unitRow(array $over = []): array
    {
        return [...[
            'game' => 'bar', 'unit_name' => 'armsolar', 'map_name' => null, 'variant' => 'buildpic',
            'tier' => 'static', 'path' => 'units/bar/buildpic/abc.webp', 'width' => 256, 'height' => 256,
            'moderation' => 'approved', 'world_height_min' => null, 'world_height_max' => null, 'bytes_missing_at' => null,
        ], ...$over];
    }

    private function mapRow(array $over = []): array
    {
        return [...[
            'game' => null, 'unit_name' => null, 'map_name' => 'Comet Catcher Remake 1.8', 'variant' => 'minimap',
            'tier' => 'bucket', 'path' => 'maps/minimap/def.webp', 'width' => 512, 'height' => 512,
            'moderation' => 'approved', 'world_height_min' => null, 'world_height_max' => null, 'bytes_missing_at' => null,
        ], ...$over];
    }

    /**
     * Fakes PostgREST, honouring `moderation=eq.` for real so a pending row
     * tests the query. The identity filter is recorded, not applied.
     */
    private function fake(array $rows, bool $failing = false): void
    {
        Http::fake(function (Request $request) use ($rows, $failing) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->queries[] = $query;
            if ($failing) {
                return Http::response(['message' => 'down'], 500);
            }
            $kept = array_values(array_filter($rows, function ($row) use ($query) {
                return ! isset($query['moderation']) || 'eq.'.$row['moderation'] === $query['moderation'];
            }));

            return Http::response($kept);
        });
    }

    private function held(array ...$pairs): array
    {
        $held = [];
        foreach ($pairs as [$identity, $row]) {
            $held[Have::identityKey($identity)] = [...['world_height_min' => null, 'world_height_max' => null], ...$row];
        }

        return $held;
    }

    private function row(string $tier, string $path, string $moderation = 'approved', int $w = 256, int $h = 256): array
    {
        return ['tier' => $tier, 'path' => $path, 'width' => $w, 'height' => $h, 'moderation' => $moderation];
    }

    public function test_tier_urls(): void
    {
        $this->assertSame(Cdn::DEFAULT_BASE.'units/bar/buildpic/abc.webp', AssetResolver::assetTierUrl('static', 'units/bar/buildpic/abc.webp'));
        $this->assertSame(Site::url().'/assets/staged/units/bar/buildpic/abc.webp', AssetResolver::assetTierUrl('bucket', 'units/bar/buildpic/abc.webp'));
        $this->assertSame('https://hub.test/assets/staged/units/bar/buildpic/abc.webp', AssetResolver::assetTierUrl('bucket', '/units/bar/buildpic/abc.webp'));
        $this->assertSame(['static', 'bucket', 'placeholder'], AssetResolver::SOURCES);
    }

    public function test_an_approved_bucket_row_is_served_through_the_route(): void
    {
        $held = $this->held([$this->buildpic(), $this->row('bucket', 'units/bar/buildpic/abc.webp')]);

        $this->assertSame([
            'from' => 'bucket',
            'url' => 'https://hub.test/assets/staged/units/bar/buildpic/abc.webp',
            'served' => $this->buildpic(),
            'substituted' => false,
            'width' => 256,
            'height' => 256,
        ], AssetResolver::resolveAsset($this->buildpic(), $held));
    }

    public function test_a_pending_or_rejected_row_draws_the_placeholder_and_never_names_its_path(): void
    {
        foreach (['pending', 'rejected'] as $moderation) {
            foreach (['bucket', 'static'] as $tier) {
                $held = $this->held([$this->buildpic(), $this->row($tier, 'units/bar/buildpic/abc.webp', $moderation)]);
                $resolved = AssetResolver::resolveAsset($this->buildpic(), $held, ['width' => 4, 'height' => 4]);
                $this->assertSame('placeholder', $resolved['from']);
                $this->assertStringNotContainsString('abc.webp', json_encode($resolved));
                $this->assertNull(AssetResolver::servable($held, $this->buildpic()));
                // Nor does a pending buildpic stand in for a render.
                $this->assertSame('placeholder', AssetResolver::resolveAsset($this->render(), $held)['from']);
            }
        }
    }

    public function test_no_row_draws_a_placeholder(): void
    {
        $this->assertSame(
            ['from' => 'placeholder', 'name' => 'armsolar', 'keyedOn' => 'unit', 'footprint' => ['width' => 4, 'height' => 4]],
            AssetResolver::resolveAsset($this->buildpic(), [], ['width' => 4, 'height' => 4]),
        );
        $this->assertSame(
            ['from' => 'placeholder', 'name' => 'Comet Catcher Remake 1.8', 'keyedOn' => 'map', 'footprint' => null],
            AssetResolver::resolveAsset($this->minimap(), []),
        );
    }

    public function test_somebody_elses_picture_is_not_this_identitys(): void
    {
        $held = $this->held([AssetResolver::unit('xta', 'armsolar'), $this->row('static', 'units/xta/buildpic/abc.webp')]);
        $this->assertSame('placeholder', AssetResolver::resolveAsset($this->buildpic(), $held)['from']);
    }

    public function test_the_rows_tier_says_where_the_bytes_are(): void
    {
        $held = $this->held([$this->buildpic(), $this->row('static', 'units/bar/buildpic/abc.webp')]);
        $this->assertSame([
            'from' => 'static',
            'url' => Cdn::DEFAULT_BASE.'units/bar/buildpic/abc.webp',
            'served' => $this->buildpic(),
            'substituted' => false,
            'width' => 256,
            'height' => 256,
        ], AssetResolver::resolveAsset($this->buildpic(), $held));
    }

    public function test_a_missing_render_is_served_the_buildpic_and_says_so(): void
    {
        $held = $this->held([$this->buildpic(), $this->row('static', 'units/bar/buildpic/abc.webp')]);
        $resolved = AssetResolver::resolveAsset($this->render(), $held);
        $this->assertSame('static', $resolved['from']);
        $this->assertSame($this->buildpic(), $resolved['served']);
        $this->assertTrue($resolved['substituted']);
    }

    public function test_a_render_the_hub_has_is_not_a_substitution(): void
    {
        $held = $this->held([$this->render(), $this->row('static', 'units/bar/render/270/ghi.webp', 'approved', 256, 192)]);
        $resolved = AssetResolver::resolveAsset($this->render(), $held);
        $this->assertSame($this->render(), $resolved['served']);
        $this->assertFalse($resolved['substituted']);
        $this->assertSame(192, $resolved['height']);
    }

    public function test_nothing_stands_in_for_a_maps_variants_or_a_buildpic_itself(): void
    {
        $this->assertNull(AssetResolver::buildpicSubstitute($this->minimap()));
        $this->assertNull(AssetResolver::buildpicSubstitute(AssetResolver::map('x', 'overlay:metal')));
        $this->assertNull(AssetResolver::buildpicSubstitute($this->buildpic()));
        $this->assertSame($this->buildpic(), AssetResolver::buildpicSubstitute($this->render()));
        $this->assertSame($this->buildpic(), AssetResolver::buildpicSubstitute($this->render('0')));
    }

    public function test_a_missing_buildpic_is_served_a_render_angled_first(): void
    {
        $held = $this->held([$this->render('side'), $this->row('static', 'units/bar/render/side.webp', 'approved', 512, 341)]);
        $resolved = AssetResolver::resolveAsset($this->buildpic(), $held);
        $this->assertSame($this->render('side'), $resolved['served']);
        $this->assertTrue($resolved['substituted']);

        $held = $this->held(
            [$this->render('top'), $this->row('static', 'units/bar/render/top.webp')],
            [$this->render('side'), $this->row('static', 'units/bar/render/side.webp')],
            [$this->render('angled'), $this->row('static', 'units/bar/render/angled.webp')],
        );
        $this->assertSame($this->render('angled'), AssetResolver::resolveAsset($this->buildpic(), $held)['served']);

        $held = $this->held(
            [$this->buildpic(), $this->row('static', 'units/bar/buildpic/abc.webp')],
            [$this->render('angled'), $this->row('static', 'units/bar/render/angled.webp')],
        );
        $this->assertSame($this->buildpic(), AssetResolver::resolveAsset($this->buildpic(), $held)['served']);
    }

    public function test_ladder_identities(): void
    {
        $all = [$this->buildpic(), $this->render('angled'), $this->render('front'), $this->render('side'), $this->render('top')];
        $this->assertSame($all, AssetResolver::ladderIdentities([$this->buildpic()]));
        $this->assertSame([$this->render(), $this->buildpic()], AssetResolver::ladderIdentities([$this->render()]));
        $this->assertSame(
            [$this->render(), ...$all, $this->render('90')],
            AssetResolver::ladderIdentities([$this->render(), $this->buildpic(), $this->render('90')]),
        );
        $this->assertSame([$this->minimap()], AssetResolver::ladderIdentities([$this->minimap()]));
        $this->assertSame(
            array_fill(0, 4, $this->buildpic()),
            array_merge(...array_map(AssetResolver::substituteIdentities(...), AssetResolver::substituteIdentities($this->buildpic()))),
        );
    }

    public function test_the_query_asks_for_approved_rows_and_keys_the_answer_by_identity(): void
    {
        $this->fake([$this->unitRow(), $this->mapRow()]);
        $held = AssetResolver::fetchHeldAssets(Supabase::anon(), [$this->buildpic(), $this->minimap()]);

        $this->assertCount(1, $this->queries);
        $this->assertSame('eq.approved', $this->queries[0]['moderation']);
        $this->assertStringStartsWith('(and(game.eq."bar",unit_name.eq."armsolar",variant.eq."buildpic"),', $this->queries[0]['or']);
        $this->assertEquals(
            ['bytes_missing_at', 'game', 'height', 'map_name', 'moderation', 'path', 'tier', 'unit_name', 'variant', 'width', 'world_height_max', 'world_height_min'],
            collect(explode(',', $this->queries[0]['select']))->sort()->values()->all(),
        );
        $this->assertSame([
            'tier' => 'static', 'path' => 'units/bar/buildpic/abc.webp', 'width' => 256, 'height' => 256,
            'moderation' => 'approved', 'world_height_min' => null, 'world_height_max' => null,
        ], $held[Have::identityKey($this->buildpic())]);
        $this->assertSame('bucket', $held[Have::identityKey($this->minimap())]['tier']);
    }

    public function test_a_row_whose_bytes_are_lost_is_never_served(): void
    {
        $lost = '2026-09-14T12:00:00Z';
        $this->fake([
            $this->unitRow(['variant' => 'render:270', 'tier' => 'bucket', 'path' => 'units/bar/render/270/r.webp', 'bytes_missing_at' => $lost]),
            $this->unitRow(),
            $this->mapRow(['bytes_missing_at' => $lost]),
        ]);
        $held = AssetResolver::fetchHeldAssets(Supabase::anon(), [$this->render(), $this->minimap()]);

        $render = AssetResolver::resolveAsset($this->render(), $held);
        $this->assertSame('static', $render['from']);
        $this->assertSame($this->buildpic(), $render['served']);
        $this->assertSame('placeholder', AssetResolver::resolveAsset($this->minimap(), $held)['from']);
    }

    public function test_a_height_overlay_carries_its_world_range_and_a_minimap_does_not(): void
    {
        $overlay = AssetResolver::map('Comet Catcher Remake 1.8', 'overlay:height');
        $this->fake([
            $this->mapRow(['variant' => 'overlay:height', 'path' => 'maps/overlay/height/def.webp', 'world_height_min' => -120.5, 'world_height_max' => 890]),
            $this->mapRow(),
        ]);
        $held = AssetResolver::fetchHeldAssets(Supabase::anon(), [$overlay, $this->minimap()]);

        $this->assertSame(-120.5, AssetResolver::servable($held, $overlay)['world_height_min']);
        $this->assertSame(890, AssetResolver::servable($held, $overlay)['world_height_max']);
        $this->assertNull(AssetResolver::servable($held, $this->minimap())['world_height_min']);
    }

    public function test_a_pending_row_never_comes_back_from_the_query(): void
    {
        $this->fake([$this->unitRow(['moderation' => 'pending'])]);
        $held = AssetResolver::fetchHeldAssets(Supabase::anon(), [$this->buildpic()]);
        $this->assertSame([], $held);
    }

    public function test_asking_for_nothing_costs_no_query(): void
    {
        $this->fake([]);
        $this->assertSame([], AssetResolver::fetchHeldAssets(Supabase::anon(), []));
        $this->assertSame([], $this->queries);
    }

    public function test_a_big_batch_is_split(): void
    {
        $this->fake([]);
        $identities = array_map(fn ($i) => AssetResolver::map('Map '.$i), range(0, 119));
        AssetResolver::fetchHeldAssets(Supabase::anon(), $identities);
        $this->assertCount(3, $this->queries);
    }

    public function test_a_query_that_errors_draws_placeholders(): void
    {
        $this->fake([$this->unitRow()], failing: true);
        $held = AssetResolver::fetchHeldAssets(Supabase::anon(), [$this->buildpic()]);
        $this->assertSame([], $held);
        $resolved = AssetResolver::resolveAsset($this->buildpic(), $held, ['width' => 4, 'height' => 4]);
        $this->assertSame('placeholder', $resolved['from']);
        $this->assertSame('armsolar', $resolved['name']);
    }
}
