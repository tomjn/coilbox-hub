<?php

namespace Tests\Unit\Assets;

use App\Domain\Assets\AssetResolver;
use App\Domain\Assets\Placeholder;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** Ported from `lib/assets/placeholder.test.ts`, plus the Blade component. */
class PlaceholderTest extends TestCase
{
    private function unit(): array
    {
        return AssetResolver::unit('bar', 'armsolar');
    }

    private function map(): array
    {
        return AssetResolver::map('Comet Catcher Remake 1.8');
    }

    public function test_a_placeholder_is_named_for_what_it_is_of(): void
    {
        $this->assertSame(
            ['name' => 'armsolar', 'keyedOn' => 'unit', 'footprint' => ['width' => 4, 'height' => 4]],
            Placeholder::missingPicture($this->unit(), ['width' => 4, 'height' => 4]),
        );
        $this->assertSame(
            ['name' => 'Comet Catcher Remake 1.8', 'keyedOn' => 'map', 'footprint' => null],
            Placeholder::missingPicture($this->map(), null),
        );
        $this->assertSame('armada', Placeholder::missingPicture(AssetResolver::faction('bar', 'armada'), null)['name']);
    }

    public function test_a_footprint_becomes_a_box_whose_longer_side_is_100(): void
    {
        $this->assertEquals(['width' => 100, 'height' => 50], Placeholder::box(['width' => 4, 'height' => 2]));
        $this->assertEquals(['width' => 50, 'height' => 100], Placeholder::box(['width' => 2, 'height' => 4]));
        $this->assertEquals(['width' => 100, 'height' => 100], Placeholder::box(['width' => 3, 'height' => 3]));
        $this->assertEquals(Placeholder::box(['width' => 4, 'height' => 4]), Placeholder::box(['width' => 24, 'height' => 24]));
        $this->assertEquals(['width' => 100, 'height' => 100], Placeholder::box(null));
    }

    public function test_an_unusable_footprint_falls_back_to_a_square(): void
    {
        foreach ([[0, 4], [4, -1], [NAN, 4], [4, INF]] as [$w, $h]) {
            $this->assertEquals(['width' => 100, 'height' => 100], Placeholder::box(['width' => $w, 'height' => $h]));
        }
    }

    public function test_an_absurd_proportion_is_held_to_something_visible(): void
    {
        $this->assertEquals(['width' => 100, 'height' => 12.5], Placeholder::box(['width' => 1000, 'height' => 1]));
        $this->assertEquals(['width' => 12.5, 'height' => 100], Placeholder::box(['width' => 1, 'height' => 1000]));
    }

    public function test_the_measure(): void
    {
        $this->assertSame('4 by 4 build squares', Placeholder::measure(Placeholder::missingPicture($this->unit(), ['width' => 4, 'height' => 4])));
        $this->assertSame('12 by 12', Placeholder::measure(Placeholder::missingPicture($this->map(), ['width' => 12, 'height' => 12])));
        $this->assertNull(Placeholder::measure(Placeholder::missingPicture($this->unit(), null)));
        $this->assertNull(Placeholder::measure(Placeholder::missingPicture($this->unit(), ['width' => 0, 'height' => 4])));
        $this->assertSame('4 by 3 build squares', Placeholder::measure(Placeholder::missingPicture($this->unit(), ['width' => 4.04, 'height' => 2.96])));
        $this->assertSame('4.5 by 3 build squares', Placeholder::measure(Placeholder::missingPicture($this->unit(), ['width' => 4.5, 'height' => 3.0])));
    }

    public function test_the_label(): void
    {
        $this->assertSame(
            'No picture of armsolar yet, which stands on 4 by 4 build squares',
            Placeholder::label(Placeholder::missingPicture($this->unit(), ['width' => 4, 'height' => 4])),
        );
        $this->assertSame(
            'No picture of Comet Catcher Remake 1.8 yet, a 12 by 12 map',
            Placeholder::label(Placeholder::missingPicture($this->map(), ['width' => 12, 'height' => 12])),
        );
        $this->assertSame('No picture of Comet Catcher Remake 1.8 yet', Placeholder::label(Placeholder::missingPicture($this->map(), null)));
    }

    public function test_numbers_print_the_way_javascript_prints_them(): void
    {
        $this->assertSame('50', Placeholder::jsNumber(50.0));
        $this->assertSame('12.5', Placeholder::jsNumber(12.5));
        $this->assertSame('33.333333333333336', Placeholder::jsNumber(100 / 3));
    }

    public function test_the_component_draws_the_same_markup_as_the_next_app(): void
    {
        $of = Placeholder::missingPicture($this->unit(), ['width' => 4, 'height' => 2]);
        $html = Blade::render('<x-assets.placeholder :of="$of" class="h-40" />', ['of' => $of]);

        $this->assertStringContainsString('class="flex flex-col items-center justify-center gap-3 rounded-md border border-neutral-800 bg-black p-4 h-40"', $html);
        $this->assertStringContainsString('viewBox="0 0 100 50"', $html);
        $this->assertStringContainsString('style="aspect-ratio:100 / 50"', $html);
        $this->assertStringContainsString('width="96" height="46"', $html);
        $this->assertStringContainsString('aria-label="No picture of armsolar yet, which stands on 4 by 2 build squares"', $html);
        $this->assertStringContainsString('4 by 2 build squares, no picture yet', $html);

        $quiet = Blade::render('<x-assets.placeholder :of="$of" quiet />', ['of' => Placeholder::missingPicture($this->map(), null)]);
        $this->assertStringNotContainsString('No picture yet', $quiet);
        $this->assertStringContainsString('p-4"', $quiet);

        $loud = Blade::render('<x-assets.placeholder :of="$of" />', ['of' => Placeholder::missingPicture($this->map(), null)]);
        $this->assertStringContainsString('No picture yet', $loud);
    }
}
