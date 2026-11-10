<?php

namespace Tests\Unit\Assets;

use App\Domain\Assets\AssetPath;
use App\Domain\Assets\AssetResolver;
use Tests\TestCase;

/** Ported from `lib/assets/path.test.ts`. */
class AssetPathTest extends TestCase
{
    private function unit(array $over = []): array
    {
        return [...AssetResolver::unit('BYAR', 'armsolar'), ...$over];
    }

    private function map(array $over = []): array
    {
        return [...AssetResolver::map('Comet, Catcher (Remake) 1.8 "beta"'), ...$over];
    }

    private function faction(array $over = []): array
    {
        return [...AssetResolver::faction('BYAR', 'armada'), ...$over];
    }

    public function test_a_unit_path_names_the_game_and_the_variant(): void
    {
        $this->assertSame('units/BYAR/buildpic/0a1b2c3d.webp', AssetPath::objectPath($this->unit(), '0a1b2c3d', 'image/webp'));
    }

    public function test_a_render_angle_becomes_a_path_segment(): void
    {
        $this->assertSame('units/BYAR/render/270/0a1b2c3d.webp', AssetPath::objectPath($this->unit(['variant' => 'render:270']), '0a1b2c3d', 'image/webp'));
    }

    public function test_a_map_path_carries_no_map_name(): void
    {
        $path = AssetPath::objectPath($this->map(), '0a1b2c3d', 'image/webp');
        $this->assertSame('maps/minimap/0a1b2c3d.webp', $path);
        $this->assertStringNotContainsString('Comet', $path);
    }

    public function test_an_overlay_splits_like_a_render(): void
    {
        $this->assertSame('maps/overlay/height/0a1b.webp', AssetPath::objectPath($this->map(['variant' => 'overlay:height']), '0a1b', 'image/webp'));
    }

    public function test_the_extension_follows_the_declared_type(): void
    {
        $this->assertSame('units/BYAR/buildpic/abc.png', AssetPath::objectPath($this->unit(), 'abc', 'image/png'));
    }

    public function test_two_units_sharing_a_picture_share_one_object(): void
    {
        $this->assertSame(
            AssetPath::objectPath($this->unit(), 'abc', 'image/webp'),
            AssetPath::objectPath($this->unit(['unitName' => 'cormoon']), 'abc', 'image/webp'),
        );
    }

    public function test_a_type_outside_the_allowlist_has_no_path(): void
    {
        $this->assertNull(AssetPath::objectPath($this->unit(), 'abc', 'image/gif'));
        $this->assertNull(AssetPath::objectPath($this->unit(), 'abc', 'application/zip'));
        $this->assertFalse(AssetPath::isAssetMime('image/gif'));
        $this->assertSame(['image/webp', 'image/png'], array_keys(AssetPath::MIME_EXTENSIONS));
    }

    public function test_nothing_that_could_climb_out_of_the_tree_can_be_spelled(): void
    {
        $this->assertNull(AssetPath::objectPath($this->unit(['game' => '..']), 'abc', 'image/webp'));
        $this->assertNull(AssetPath::objectPath($this->unit(['game' => 'a/b']), 'abc', 'image/webp'));
        $this->assertNull(AssetPath::objectPath($this->unit(['variant' => 'render:../../x']), 'abc', 'image/webp'));
        $this->assertNull(AssetPath::objectPath($this->unit(), '../secret', 'image/webp'));
        $this->assertNull(AssetPath::objectPath($this->unit(), '.hidden', 'image/webp'));
        $this->assertNull(AssetPath::objectPath($this->map(['variant' => 'mini map']), 'abc', 'image/webp'));
        // A trailing newline must not slip past the end anchor.
        $this->assertNull(AssetPath::objectPath($this->unit(), "abc\n", 'image/webp'));
    }

    public function test_a_faction_logo_path(): void
    {
        $this->assertSame('factions/BYAR/logo/0a1b2c3d.webp', AssetPath::objectPath($this->faction(), '0a1b2c3d', 'image/webp'));
        $this->assertSame(
            AssetPath::objectPath($this->faction(), 'abc', 'image/webp'),
            AssetPath::objectPath($this->faction(['factionKey' => '../a b/c']), 'abc', 'image/webp'),
        );
        $this->assertNull(AssetPath::objectPath($this->faction(['game' => '..']), 'abc', 'image/webp'));
        $this->assertNull(AssetPath::objectPath($this->faction(['game' => 'a/b']), 'abc', 'image/webp'));
    }
}
