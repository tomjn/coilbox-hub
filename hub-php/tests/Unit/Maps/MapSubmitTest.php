<?php

namespace Tests\Unit\Maps;

use App\Domain\Maps\Js;
use App\Domain\Maps\MapSubmit;
use Tests\TestCase;

/** Ported from `lib/api/mapSubmit.test.ts`. */
class MapSubmitTest extends TestCase
{
    private const MAP = [
        'map_name' => 'Comet Catcher Remake 1.8',
        'display_name' => 'Comet Catcher Remake',
        'description' => 'A remake of the original',
        'map_version' => '1.8',
        'author' => 'Beherith & Icexuick',
        'archive_filename' => 'comet_catcher_remake_1.8.sd7',
        'source_archive' => 'Comet Catcher Remake 1.8',
        'source_hash' => 'src-comet',
        'catalog_version' => 3,
        'width_elmos' => 6144,
        'height_elmos' => 10240,
        'world_height_min' => -120.5,
        'world_height_max' => 890,
        'min_wind' => 5,
        'max_wind' => 40,
        'tidal_strength' => 18,
        'void_water' => false,
        'void_ground' => false,
        'water_coverage' => 0.31,
        'appearance' => ['water' => ['colour' => [0, 0.2, 0.4]]],
        'points' => [
            'start' => [['x' => 512, 'z' => 512]],
            'metal' => [['x' => 1024, 'z' => 2048, 'meta' => ['amount' => 2, 'radius' => 48]]],
            'geo' => [['x' => 3072, 'z' => 4096, 'meta' => ['feature' => 'geovent']]],
        ],
    ];

    /** A PHP value as the route would see it after parsing the JSON body. */
    private static function wire(mixed $value): mixed
    {
        Js::parse(json_encode($value, JSON_PRESERVE_ZERO_FRACTION), $out);

        return $out;
    }

    private static function body(array ...$maps): array
    {
        return ['format' => MapSubmit::FORMAT, 'version' => MapSubmit::VERSION, 'maps' => $maps];
    }

    /** The map with overrides. A null override removes the field, as `undefined` does in JSON. */
    private static function map(array $overrides = []): array
    {
        $map = self::MAP;
        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($map[$key]);
            } else {
                $map[$key] = $value;
            }
        }

        return $map;
    }

    private static function entries(array $body): array
    {
        $parsed = MapSubmit::parse(self::wire($body));
        if (! $parsed['ok']) {
            throw new \RuntimeException($parsed['error']);
        }

        return $parsed['entries'];
    }

    private static function entry(array $overrides = []): array
    {
        $parsed = self::entries(self::body(self::map($overrides)))[0];
        if (! $parsed['ok']) {
            throw new \RuntimeException($parsed['said']);
        }

        return $parsed['entry'];
    }

    private static function said(array $overrides): string
    {
        $parsed = self::entries(self::body(self::map($overrides)))[0];
        if ($parsed['ok']) {
            throw new \RuntimeException('expected a refusal');
        }

        return $parsed['said'];
    }

    /** As JSON, so `stdClass` and arrays compare by what they would send. */
    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
    }

    public function test_an_entry_parses_into_the_facts_the_table_holds(): void
    {
        $this->assertSame(self::encode([
            'map_name' => 'Comet Catcher Remake 1.8',
            'display_name' => 'Comet Catcher Remake',
            'description' => 'A remake of the original',
            'map_version' => '1.8',
            'author' => 'Beherith & Icexuick',
            'archive_filename' => 'comet_catcher_remake_1.8.sd7',
            'source_archive' => 'Comet Catcher Remake 1.8',
            'source_hash' => 'src-comet',
            'catalog_version' => 3,
            'width_elmos' => 6144,
            'height_elmos' => 10240,
            'world_height_min' => -120.5,
            'world_height_max' => 890,
            'min_wind' => 5,
            'max_wind' => 40,
            'tidal_strength' => 18,
            'void_water' => false,
            'void_ground' => false,
            'water_coverage' => 0.31,
            'appearance' => ['water' => ['colour' => [0, 0.2, 0.4]]],
            'points' => [
                'start' => [['x' => 512, 'z' => 512, 'y' => null, 'meta' => null]],
                'metal' => [['x' => 1024, 'z' => 2048, 'y' => null, 'meta' => ['amount' => 2, 'radius' => 48]]],
                'geo' => [['x' => 3072, 'z' => 4096, 'y' => null, 'meta' => ['feature' => 'geovent']]],
            ],
        ]), self::encode(self::entry()));
    }

    public function test_a_client_cannot_declare_what_the_hub_works_out_for_itself(): void
    {
        $this->assertSame('Unknown field: slug', self::said(['slug' => 'comet']));
        $this->assertSame('Unknown field: facts_digest', self::said(['facts_digest' => 'd']));
        $this->assertSame('Unknown field: author_key', self::said(['author_key' => 'beherith']));
    }

    public function test_a_client_sends_measurements_and_not_conclusions(): void
    {
        $this->assertSame('Unknown field: tags', self::said(['tags' => ['water map']]));
    }

    public function test_an_unknown_field_is_refused_rather_than_ignored(): void
    {
        $this->assertSame('Unknown field: sourceHash', self::said(['sourceHash' => 'src-comet']));
    }

    public function test_one_malformed_entry_is_refused_on_its_own(): void
    {
        $entries = self::entries(self::body(
            self::map(['map_name' => 'Alpha 1.0']),
            self::map(['map_name' => 'Beta 1.0', 'nonsense' => true]),
            self::map(['map_name' => 'Gamma 1.0']),
        ));

        $this->assertSame([true, false, true], array_column($entries, 'ok'));
        $this->assertSame(['ok' => false, 'mapName' => 'Beta 1.0', 'said' => 'Unknown field: nonsense'], $entries[1]);
    }

    public function test_an_entry_with_no_usable_name_is_refused_under_an_empty_one(): void
    {
        $entries = self::entries(self::body(self::map(['map_name' => 7])));

        $this->assertSame([
            'ok' => false,
            'mapName' => '',
            'said' => '`map_name` is required and must be a string of 1 to 256 characters.',
        ], $entries[0]);
    }

    public function test_the_envelope_has_to_be_one_the_hub_speaks(): void
    {
        $this->assertSame(
            ['ok' => false, 'error' => '`format` must be "coilbox-hub-maps".', 'status' => 400],
            MapSubmit::parse(self::wire(['format' => 'coilbox-hub-assets'] + self::body(self::MAP))),
        );
        $this->assertSame(
            ['ok' => false, 'error' => '`version` must be 1. This hub speaks no other.', 'status' => 400],
            MapSubmit::parse(self::wire(['version' => 2] + self::body(self::MAP))),
        );
    }

    public function test_a_body_that_is_not_an_object_or_names_an_unknown_field_is_refused_whole(): void
    {
        foreach (['[]', 'null', '"maps"', '3'] as $raw) {
            Js::parse($raw, $value);
            $this->assertFalse(MapSubmit::parse($value)['ok']);
        }

        $this->assertSame(
            ['ok' => false, 'error' => 'Unknown field: since', 'status' => 400],
            MapSubmit::parse(self::wire(self::body(self::MAP) + ['since' => 'yesterday'])),
        );
    }

    public function test_maps_has_to_be_a_non_empty_array(): void
    {
        $this->assertSame(
            ['ok' => false, 'error' => '`maps` must not be empty.', 'status' => 400],
            MapSubmit::parse(self::wire(self::body())),
        );
        $this->assertSame(
            ['ok' => false, 'error' => '`maps` is required and must be an array.', 'status' => 400],
            MapSubmit::parse(self::wire(['format' => MapSubmit::FORMAT, 'version' => 1, 'maps' => self::MAP])),
        );
    }

    public function test_the_caps_are_the_ones_the_vendored_catalog_names(): void
    {
        $catalog = json_decode(file_get_contents(resource_path('vendor/map-catalog.json')), true);

        $this->assertSame($catalog['caps']['submitMaps'], MapSubmit::maxMaps());
        $this->assertSame(50, MapSubmit::maxMaps());
        $this->assertSame($catalog['caps']['submitBytes'], MapSubmit::maxBytes());
        $this->assertSame(1048576, MapSubmit::maxBytes());
    }

    public function test_a_batch_at_the_limit_is_accepted_and_one_over_is_refused_whole(): void
    {
        $atLimit = array_map(fn (int $i) => self::map(['map_name' => 'Map '.$i]), range(0, 49));

        $this->assertTrue(MapSubmit::parse(self::wire(self::body(...$atLimit)))['ok']);
        $this->assertSame(
            ['ok' => false, 'error' => 'A batch may carry at most 50 maps. That request carried 51. Split it.', 'status' => 413],
            MapSubmit::parse(self::wire(self::body(...[...$atLimit, self::map(['map_name' => 'Map 50'])]))),
        );
    }

    public function test_a_repeated_map_name_is_refused(): void
    {
        $this->assertSame(
            ['ok' => false, 'error' => 'maps[1] names a map already in the batch.', 'status' => 400],
            MapSubmit::parse(self::wire(self::body(self::MAP, self::map(['source_hash' => 'src-other'])))),
        );
    }

    public function test_the_facts_a_map_cannot_be_stored_without_are_required(): void
    {
        foreach (['map_name', 'source_archive', 'source_hash', 'catalog_version', 'width_elmos', 'height_elmos', 'world_height_min', 'world_height_max'] as $field) {
            $parsed = self::entries(self::body(self::map([$field => null])))[0];
            $this->assertFalse($parsed['ok'], $field);
        }
    }

    public function test_a_field_longer_than_the_column_is_refused(): void
    {
        $this->assertSame('`map_name` is required and must be a string of 1 to 256 characters.', self::said(['map_name' => str_repeat('m', 257)]));
        $this->assertSame('`source_hash` is required and must be a string of 1 to 128 characters.', self::said(['source_hash' => str_repeat('s', 129)]));
        $this->assertSame('`description` must be a string of at most 4000 characters.', self::said(['description' => str_repeat('d', 4001)]));
    }

    public function test_lengths_count_utf16_units_after_trimming(): void
    {
        // 128 emoji are 256 code units, the most the column takes. One more is refused.
        $this->assertSame(str_repeat('😀', 128), self::entry(['map_name' => str_repeat('😀', 128)])['map_name']);
        $this->assertSame('`map_name` is required and must be a string of 1 to 256 characters.', self::said(['map_name' => str_repeat('😀', 129)]));
        // A non breaking space is whitespace to `trim()`, so a name of only that is blank.
        $this->assertSame('`map_name` is required and must be a string of 1 to 256 characters.', self::said(['map_name' => "\u{A0}"]));
    }

    public function test_a_size_and_a_version_are_positive_integers(): void
    {
        $this->assertSame('`width_elmos` is required and must be a positive integer.', self::said(['width_elmos' => 0]));
        $this->assertSame('`width_elmos` is required and must be a positive integer.', self::said(['width_elmos' => 6144.5]));
        $this->assertSame('`catalog_version` is required and must be a positive integer.', self::said(['catalog_version' => 0]));
        $this->assertSame(6144, self::entry(['width_elmos' => 6144.0])['width_elmos']);
    }

    public function test_a_world_height_may_be_negative_and_neither_infinite_nor_missing(): void
    {
        $this->assertSame(-900, self::entry(['world_height_min' => -900, 'world_height_max' => -10])['world_height_min']);

        Js::parse('{"format":"coilbox-hub-maps","version":1,"maps":[{"map_name":"m","source_archive":"a","source_hash":"h","catalog_version":1,"width_elmos":1,"height_elmos":1,"world_height_min":1e400,"world_height_max":1}]}', $body);
        $this->assertSame('`world_height_min` is required and must be a number.', MapSubmit::parse($body)['entries'][0]['said']);
    }

    public function test_the_pairs_that_have_to_agree_are_checked_before_the_write(): void
    {
        $this->assertSame('`world_height_max` cannot be below `world_height_min`.', self::said(['world_height_min' => 320, 'world_height_max' => 0]));
        $this->assertSame('`max_wind` cannot be below `min_wind`.', self::said(['min_wind' => 40, 'max_wind' => 5]));
        $this->assertSame('`water_coverage` is a share of the map between 0 and 1, not a percentage.', self::said(['water_coverage' => 42]));
        $this->assertSame('A map with `void_water` has no water to report a `water_coverage` for.', self::said(['void_water' => true]));
        $this->assertNull(self::entry(['void_water' => true, 'water_coverage' => null])['water_coverage']);
    }

    public function test_a_measurement_the_archive_does_not_carry_stays_absent(): void
    {
        $sparse = self::entry(['min_wind' => null, 'max_wind' => null, 'tidal_strength' => null, 'void_water' => null, 'void_ground' => null, 'water_coverage' => null, 'appearance' => null]);

        $this->assertNull($sparse['min_wind']);
        $this->assertNull($sparse['tidal_strength']);
        $this->assertNull($sparse['void_water']);
        $this->assertSame('{}', self::encode($sparse['appearance']));
    }

    public function test_a_blank_optional_field_is_absent_rather_than_a_refusal(): void
    {
        $this->assertNull(self::entry(['display_name' => '   ', 'description' => ''])['display_name']);
        $this->assertSame('Comet', self::entry(['display_name' => '  Comet  '])['display_name']);
    }

    public function test_a_map_with_no_points_at_all_is_an_ordinary_map(): void
    {
        $this->assertSame(['start' => [], 'metal' => [], 'geo' => []], self::entry(['points' => null])['points']);
    }

    public function test_a_point_kind_outside_the_three_is_refused(): void
    {
        $this->assertSame('`points` names a kind the hub does not know: geothermal', self::said(['points' => ['geothermal' => []]]));
    }

    public function test_a_point_says_where_it_is_and_may_say_what_its_kind_carries(): void
    {
        $parsed = self::entry(['points' => ['metal' => [['x' => 1, 'z' => 2, 'y' => 3, 'meta' => ['amount' => 2]]]]]);

        $this->assertSame('[{"x":1,"z":2,"y":3,"meta":{"amount":2}}]', self::encode($parsed['points']['metal']));
        $this->assertSame('`points.metal[0]` `z` is required and must be a number.', self::said(['points' => ['metal' => [['x' => 1]]]]));
        $this->assertSame('`points.metal[0]` unknown field: colour', self::said(['points' => ['metal' => [['x' => 1, 'z' => 2, 'colour' => 'red']]]]));
        $this->assertSame('`points.start` must be an array.', self::said(['points' => ['start' => '512,512']]));
    }

    public function test_the_reply_carries_the_envelope_a_shipped_build_reads_first(): void
    {
        $this->assertSame(
            ['format' => 'coilbox-hub-maps', 'version' => 1, 'results' => [['map_name' => 'Comet Catcher Remake 1.8', 'outcome' => 'stored']]],
            MapSubmit::body([['map_name' => 'Comet Catcher Remake 1.8', 'outcome' => 'stored']]),
        );
    }
}
