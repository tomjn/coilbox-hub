<?php

namespace Tests\Unit\Assets;

use App\Domain\Assets\Cdn;
use Tests\TestCase;

/** Ported from `lib/assets/cdn.test.ts`. The override is `hub.asset_cdn_base`. */
class CdnTest extends TestCase
{
    public function test_unset_falls_back_to_the_assets_repo(): void
    {
        config(['hub.asset_cdn_base' => null]);
        $this->assertSame(Cdn::DEFAULT_BASE, Cdn::base());
    }

    public function test_an_empty_or_whitespace_override_counts_as_unset(): void
    {
        foreach (['', '   ', '/', '   /'] as $value) {
            config(['hub.asset_cdn_base' => $value]);
            $this->assertSame(Cdn::DEFAULT_BASE, Cdn::base(), var_export($value, true));
        }
    }

    public function test_the_override_replaces_the_default(): void
    {
        config(['hub.asset_cdn_base' => 'https://assets.example.net/']);
        $this->assertSame('https://assets.example.net/', Cdn::base());
        $this->assertSame('https://assets.example.net/units/bar/abc.webp', Cdn::staticTierUrl('units/bar/abc.webp'));
    }

    public function test_the_base_always_ends_in_exactly_one_slash(): void
    {
        config(['hub.asset_cdn_base' => 'https://assets.example.net/coilbox']);
        $this->assertSame('https://assets.example.net/coilbox/', Cdn::base());

        config(['hub.asset_cdn_base' => 'https://assets.example.net/coilbox///']);
        $this->assertSame('https://assets.example.net/coilbox/', Cdn::base());
    }

    public function test_joining_produces_no_double_slash(): void
    {
        foreach (['https://assets.example.net/coilbox/', 'https://assets.example.net/coilbox'] as $base) {
            config(['hub.asset_cdn_base' => $base]);
            $this->assertSame('https://assets.example.net/coilbox/maps/abc.webp', Cdn::staticTierUrl('maps/abc.webp'));
            $this->assertSame('https://assets.example.net/coilbox/maps/abc.webp', Cdn::staticTierUrl('/maps/abc.webp'));
        }
    }

    public function test_joining_keeps_the_subpath_and_nested_paths(): void
    {
        config(['hub.asset_cdn_base' => null]);
        $this->assertSame('https://tomjn.github.io/coilbox-assets/maps/abc.webp', Cdn::staticTierUrl('/maps/abc.webp'));
        $this->assertSame(
            'https://tomjn.github.io/coilbox-assets/units/bar/render/270/0a1b2c3d.webp',
            Cdn::staticTierUrl('units/bar/render/270/0a1b2c3d.webp'),
        );
        $this->assertStringEndsWith('/', Cdn::DEFAULT_BASE);
    }
}
