<?php

namespace Tests\Unit\Maps;

use App\Domain\Maps\Facts;
use App\Domain\Maps\Js;
use App\Domain\Maps\Slug;
use PHPUnit\Framework\TestCase;

/** Ported from `lib/maps/facts.test.ts` and `lib/maps/slug.test.ts`. */
class FactsTest extends TestCase
{
    /** The fixture from `facts.test.ts`, with extra appearance keys that test number and key formatting. */
    private static function entry(): array
    {
        Js::parse(<<<'JSON'
        {
          "map_name": "Comet Catcher Remake 1.8", "display_name": "Comet Catcher Remake", "description": "A remake", "map_version": "1.8",
          "author": "Beherith & Icexuick", "archive_filename": "comet_catcher_remake_1.8.sd7", "source_archive": "Comet Catcher Remake 1.8",
          "source_hash": "src-comet", "catalog_version": 3, "width_elmos": 6144, "height_elmos": 10240, "world_height_min": -120.5, "world_height_max": 890,
          "min_wind": 5, "max_wind": 40, "tidal_strength": 18, "void_water": false, "void_ground": false, "water_coverage": 0.31,
          "appearance": { "water": { "colour": [0, 0.2, 0.4] }, "sun": { "dir": [1, 1, 0] }, "ünï": "\u2028\u0001\"x\"", "big": 1e21, "small": 1e-7, "mid": 123456789012345680000, "neg": -0.000001 },
          "points": {
            "start": [{ "x": 512, "z": 512, "y": null, "meta": null }, { "x": 5632, "z": 9728, "y": null, "meta": null }],
            "metal": [{ "x": 1024, "z": 2048, "y": null, "meta": { "amount": 2, "radius": 48 } }, { "x": 3000, "z": 400, "y": 12.5, "meta": { "amount": 1.5, "radius": 48 } }],
            "geo": [{ "x": 3072, "z": 4096, "y": null, "meta": { "feature": "geovent", "😀": 1, "￿": 2 } }]
          }
        }
        JSON, $entry);
        $entry = json_decode(json_encode($entry), true);
        $entry['appearance'] = json_decode(json_encode($entry['appearance']));
        foreach (['start', 'metal', 'geo'] as $kind) {
            foreach ($entry['points'][$kind] as $i => $point) {
                $entry['points'][$kind][$i]['meta'] = $point['meta'] === null ? null : (object) $point['meta'];
            }
        }

        return $entry;
    }

    /** The values the Next app's own code gives for the fixture, from `bun`, so a stored digest still matches. */
    public function test_the_digest_matches_the_one_the_next_app_computes(): void
    {
        $this->assertSame('bfde8d881a6e46a3cd2ce9da07f8358f07e1252c144944c28eabc84298e47925', Facts::digest(self::entry()));
        $this->assertSame(
            // U+2028 stays raw, as `JSON.stringify` leaves it.
            '{"big":1e+21,"mid":123456789012345680000,"neg":-0.000001,"small":1e-7,"sun":{"dir":[1,1,0]},"water":{"colour":[0,0.2,0.4]},"ünï":"'."\u{2028}".'\u0001\"x\""}',
            Facts::canonicalJson(self::entry()['appearance']),
        );
        $this->assertSame("{\"feature\":\"geovent\",\"😀\":1,\"\u{FFFF}\":2}", Facts::canonicalJson(self::entry()['points']['geo'][0]['meta']));
    }

    public function test_the_order_an_objects_keys_arrive_in_does_not_change_the_digest(): void
    {
        $reordered = self::entry();
        $reordered['appearance'] = (object) array_reverse(get_object_vars($reordered['appearance']));

        $this->assertSame(Facts::digest(self::entry()), Facts::digest($reordered));
    }

    public function test_reordering_the_metal_spots_is_the_same_digest(): void
    {
        $reordered = self::entry();
        $reordered['points']['metal'] = array_reverse($reordered['points']['metal']);

        $this->assertSame(Facts::digest(self::entry()), Facts::digest($reordered));
        $this->assertEquals(Facts::canonicalEntry(self::entry())['points']['metal'], Facts::canonicalEntry($reordered)['points']['metal']);
    }

    public function test_reordering_the_start_positions_is_a_different_map(): void
    {
        $swapped = self::entry();
        $swapped['points']['start'] = array_reverse($swapped['points']['start']);

        $this->assertNotSame(Facts::digest(self::entry()), Facts::digest($swapped));
    }

    public function test_a_moved_metal_spot_is_a_different_map(): void
    {
        $moved = self::entry();
        $moved['points']['metal'][0]['x'] = 1025;

        $this->assertNotSame(Facts::digest(self::entry()), Facts::digest($moved));
    }

    public function test_a_metal_spot_that_loses_its_amount_is_a_different_map(): void
    {
        $thinner = self::entry();
        $thinner['points']['metal'][0]['meta'] = (object) ['amount' => 1, 'radius' => 48];

        $this->assertNotSame(Facts::digest(self::entry()), Facts::digest($thinner));
    }

    public function test_two_points_at_one_coordinate_sort_by_the_rest_of_the_point(): void
    {
        $stacked = self::entry();
        $stacked['points']['metal'] = [
            ['x' => 100, 'z' => 100, 'y' => null, 'meta' => (object) ['amount' => 2]],
            ['x' => 100, 'z' => 100, 'y' => null, 'meta' => (object) ['amount' => 1]],
        ];
        $reversed = $stacked;
        $reversed['points']['metal'] = array_reverse($stacked['points']['metal']);

        $this->assertSame(Facts::canonicalJson(Facts::canonicalEntry($stacked)), Facts::canonicalJson(Facts::canonicalEntry($reversed)));
    }

    public function test_a_digest_is_64_lowercase_hex_characters_and_stable(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Facts::digest(self::entry()));
        $this->assertSame(Facts::digest(self::entry()), Facts::digest(self::entry()));
    }

    public function test_a_changed_measurement_is_a_changed_digest(): void
    {
        $changed = self::entry();
        $changed['water_coverage'] = 0.32;

        $this->assertNotSame(Facts::digest(self::entry()), Facts::digest($changed));
    }

    public function test_two_spellings_of_one_number_are_one_digest(): void
    {
        $this->assertSame(Facts::canonicalJson(['height' => 890]), Facts::canonicalJson(['height' => 890.0]));
        $this->assertSame(Facts::canonicalJson(['height' => 0]), Facts::canonicalJson(['height' => -0.0]));
    }

    public function test_null_keys_and_arrays(): void
    {
        $this->assertSame('{"min_wind":null}', Facts::canonicalJson(['min_wind' => null]));
        $this->assertSame('{"A":3,"a":2,"b":1}', Facts::canonicalJson(['b' => 1, 'a' => 2, 'A' => 3]));
        $this->assertSame('[3,1,2]', Facts::canonicalJson([3, 1, 2]));
        $this->assertSame('{"sun":{"a":2,"z":1}}', Facts::canonicalJson(['sun' => (object) ['z' => 1, 'a' => 2]]));
    }

    public function test_a_canonical_map_name_becomes_a_name_for_a_url(): void
    {
        $this->assertSame('comet-catcher-remake-1-8', Slug::for('Comet Catcher Remake 1.8'));
        $this->assertSame('bar-tangerine-v1-1', Slug::for('  [BAR] Tangerine!! v1.1 '));
    }

    public function test_a_name_outside_ascii_keeps_its_letters(): void
    {
        $this->assertSame('зоя-1-0', Slug::for('Зоя 1.0'));
        $this->assertSame('zoë-s-map', Slug::for("Zoë's Map"));
        $this->assertSame('ωmega-σοφος-map', Slug::for('Ωmega ΣΟΦΟΣ Map'));
    }

    public function test_two_spellings_of_one_accented_name_give_one_slug(): void
    {
        $this->assertSame(Slug::for("Zo\u{00EB} 1.0"), Slug::for("Zoe\u{0308} 1.0"));
    }

    public function test_a_name_with_nothing_sluggable_still_gets_a_name(): void
    {
        $this->assertSame('map', Slug::for('!!! ???'));
        $this->assertSame('map', Slug::for(str_repeat('😀', 130)));
    }

    public function test_a_slug_fits_the_column(): void
    {
        $slug = Slug::for(str_repeat('Very Long Map ', 40));
        $this->assertLessThanOrEqual(256, mb_strlen($slug));
        $this->assertStringEndsNotWith('-', $slug);
        $this->assertLessThanOrEqual(256, mb_strlen(Slug::alternative(str_repeat('Very Long Map ', 40))));
    }

    public function test_two_names_that_slug_the_same_have_different_alternatives(): void
    {
        $this->assertSame(Slug::for('Comet Catcher 1.8'), Slug::for('Comet_Catcher 1.8'));
        $this->assertNotSame(Slug::alternative('Comet Catcher 1.8'), Slug::alternative('Comet_Catcher 1.8'));
    }

    public function test_an_alternative_matches_the_next_app(): void
    {
        $this->assertSame('comet-catcher-remake-1-8-6b9c91f1', Slug::alternative('Comet Catcher Remake 1.8'));
    }
}
