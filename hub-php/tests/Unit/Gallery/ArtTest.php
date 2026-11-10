<?php

namespace Tests\Unit\Gallery;

use App\Domain\Gallery\Art\CoilArt;
use App\Domain\Gallery\Art\Drawings;
use App\Domain\Gallery\Art\ItemArt;
use App\Domain\Gallery\Art\Palette;
use Tests\TestCase;

/** Ported from `components/art/drawings.test.ts`, plus `lib/gallery/itemArt.ts`. */
class ArtTest extends TestCase
{
    /** Every tag in the markup with its attributes, read back out of the string. */
    private function marks(string $markup): array
    {
        preg_match_all('/<(\w+)\s+([^>]*?)\/?>/', $markup, $tags, PREG_SET_ORDER);

        return array_map(function ($tag) {
            preg_match_all('/([\w-]+)="([^"]*)"/', $tag[2], $attrs, PREG_SET_ORDER);

            return ['tag' => $tag[1], 'attrs' => array_column($attrs, 2, 1)];
        }, $tags);
    }

    private function squares(string $markup): array
    {
        $rects = array_filter($this->marks($markup), fn ($m) => $m['tag'] === 'rect');

        return array_values(array_map(fn ($m) => [
            'x' => (float) $m['attrs']['x'],
            'y' => (float) $m['attrs']['y'],
            'width' => (float) $m['attrs']['width'],
            'height' => (float) $m['attrs']['height'],
            'unplaced' => array_key_exists('stroke-dasharray', $m['attrs']),
        ], $rects));
    }

    private function blueprint(): string
    {
        return Drawings::get('blueprint')['paint'](Palette::get(), 1.0);
    }

    public function test_every_square_stands_on_the_build_grid(): void
    {
        $pitch = Drawings::BLUEPRINT_PITCH;
        $gap = Drawings::BLUEPRINT_GAP;
        foreach ($this->squares($this->blueprint()) as $s) {
            $this->assertEqualsWithDelta(0, fmod($s['x'] - $gap, $pitch), 0.001);
            $this->assertEqualsWithDelta(0, fmod($s['y'] - $gap, $pitch), 0.001);
            $this->assertEqualsWithDelta(0, fmod($s['width'] + $gap * 2, $pitch), 0.001);
            $this->assertEqualsWithDelta(0, fmod($s['height'] + $gap * 2, $pitch), 0.001);
        }
    }

    public function test_the_layout_is_a_base_at_more_than_one_size(): void
    {
        $drawn = $this->squares($this->blueprint());
        $this->assertGreaterThan(6, count($drawn));
        $sizes = array_unique(array_map(fn ($s) => $s['width'].'x'.$s['height'], $drawn));
        $this->assertGreaterThan(2, count($sizes));
    }

    public function test_no_two_buildings_stand_on_the_same_ground(): void
    {
        $gap = Drawings::BLUEPRINT_GAP;
        $drawn = $this->squares($this->blueprint());
        foreach ($drawn as $i => $a) {
            foreach (array_slice($drawn, $i + 1) as $b) {
                $overlaps = $a['x'] - $gap < $b['x'] + $b['width'] + $gap
                    && $b['x'] - $gap < $a['x'] + $a['width'] + $gap
                    && $a['y'] - $gap < $b['y'] + $b['height'] + $gap
                    && $b['y'] - $gap < $a['y'] + $a['height'] + $gap;
                $this->assertFalse($overlaps);
            }
        }
    }

    public function test_exactly_one_square_is_left_unplaced(): void
    {
        $unplaced = array_filter($this->squares($this->blueprint()), fn ($s) => $s['unplaced']);
        $this->assertCount(1, $unplaced);
    }

    public function test_the_build_order_ends_at_the_unplaced_square_and_starts_at_the_spark(): void
    {
        $markup = $this->blueprint();
        $centre = fn ($s) => Palette::num($s['x'] + $s['width'] / 2).','.Palette::num($s['y'] + $s['height'] / 2);
        $drawn = $this->squares($markup);
        $centres = array_map($centre, $drawn);

        $marks = $this->marks($markup);
        $thread = current(array_filter($marks, fn ($m) => $m['tag'] === 'path' && array_key_exists('stroke-dasharray', $m['attrs'])));
        $stops = array_map(
            fn ($p) => preg_replace('/\s+/', ',', trim($p), 1),
            array_values(array_filter(preg_split('/\s*[ML]\s*/', $thread['attrs']['d']))),
        );

        $this->assertGreaterThan(2, count($stops));
        foreach ($stops as $stop) {
            $this->assertContains($stop, $centres);
        }
        $unplaced = current(array_filter($drawn, fn ($s) => $s['unplaced']));
        $this->assertSame($centre($unplaced), end($stops));

        $start = current(array_filter($marks, fn ($m) => $m['tag'] === 'circle'));
        $this->assertSame($start['attrs']['cx'].','.$start['attrs']['cy'], $stops[0]);
    }

    public function test_nothing_in_the_blueprint_is_left_at_the_svg_default_opacity(): void
    {
        foreach ($this->marks($this->blueprint()) as $mark) {
            $a = $mark['attrs'];
            if (isset($a['fill']) && $a['fill'] !== 'none') {
                $this->assertArrayHasKey('fill-opacity', $a);
            }
            if (isset($a['stroke']) && $a['stroke'] !== 'none') {
                $this->assertArrayHasKey('stroke-opacity', $a);
            }
        }
    }

    public function test_num_matches_javascript_string_of_a_number(): void
    {
        $this->assertSame('0.1', Palette::num(0.1));
        $this->assertSame('12', Palette::num(12.0));
        $this->assertSame('-3.5', Palette::num(-3.5));
        $this->assertSame('0', Palette::num(-0.0));
        $this->assertSame('1e-7', Palette::num(1e-7));
        $this->assertSame('0.000001', Palette::num(1e-6));
        $this->assertSame('1e+21', Palette::num(1e21));
        $this->assertSame('0.30000000000000004', Palette::num(0.1 + 0.2));
        $this->assertSame('0.01', Palette::num(Palette::round(0.0144)));
    }

    public function test_the_palette_is_the_neutral_theme(): void
    {
        $this->assertSame([
            'glow' => 'hsl(0 14% 58%)',
            'faint' => 'hsl(0 6% 54%)',
            'line' => 'hsl(0 18% 70%)',
            'spark' => 'hsl(0 0% 80%)',
        ], Palette::get());
    }

    public function test_the_starfield_is_seeded(): void
    {
        $a = Drawings::mulberry32(0x5CA1AB1E);
        $b = Drawings::mulberry32(0x5CA1AB1E);
        $first = $a();
        $this->assertSame($first, $b());
        $this->assertGreaterThanOrEqual(0, $first);
        $this->assertLessThan(1, $first);
        $this->assertNotSame($first, $a());
    }

    public function test_every_drawing_renders_an_svg_keyed_by_its_id(): void
    {
        $this->assertSame(
            ['hub', 'downloads', 'setup-packs', 'games', 'archives', 'skirmish', 'scenario', 'conquest', 'warpath', 'blueprint'],
            array_keys(Drawings::all()),
        );
        foreach (Drawings::all() as $id => $drawing) {
            $svg = CoilArt::svg($id, 0.1, 'h-full w-full');
            $this->assertStringStartsWith('<svg viewBox="0 0 320 '.$drawing['viewHeight'].'" preserveAspectRatio="xMidYMax slice" role="img" aria-label="', $svg);
            $this->assertStringContainsString('class="h-full w-full"', $svg);
            $this->assertStringContainsString('id="'.$id.'-pool0"', $svg);
            $this->assertStringEndsWith('</svg>', $svg);
        }
    }

    public function test_item_art_maps_kinds_to_drawings(): void
    {
        $this->assertSame(['drawing' => 'skirmish', 'strength' => 0.16], ItemArt::for('preset'));
        $this->assertSame(['drawing' => 'scenario', 'strength' => 0.09], ItemArt::for('scenario'));
        $this->assertSame(['drawing' => 'setup-packs', 'strength' => 0.045], ItemArt::for('setup-pack'));
        $this->assertSame(['drawing' => 'blueprint', 'strength' => 0.12], ItemArt::for('blueprint'));
        $this->assertSame(['drawing' => 'conquest', 'strength' => 0.17], ItemArt::for('challenge', 'conquest'));
        $this->assertSame(['drawing' => 'warpath', 'strength' => 0.08], ItemArt::for('challenge', 'warpath'));
    }

    public function test_item_art_falls_back_to_downloads(): void
    {
        $fallback = ['drawing' => 'downloads', 'strength' => 0.08];
        $this->assertSame($fallback, ItemArt::for('campaign'));
        $this->assertSame($fallback, ItemArt::for('challenge'));
        $this->assertSame($fallback, ItemArt::for('challenge', 'unknown'));
    }
}
