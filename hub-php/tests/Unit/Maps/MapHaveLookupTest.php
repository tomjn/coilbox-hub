<?php

namespace Tests\Unit\Maps;

use App\Domain\Maps\Js;
use App\Domain\Maps\MapHave;
use App\Domain\Maps\MapLookup;
use App\Domain\Maps\MapPackList;
use Tests\TestCase;

/** Ported from `lib/api/mapHave.test.ts`, `lib/api/mapLookup.test.ts` and `lib/api/mapPackList.test.ts`. */
class MapHaveLookupTest extends TestCase
{
    private const KEY = ['map_name' => 'Comet Catcher Remake 1.8', 'source_hash' => 'src-a', 'catalog_version' => 3];

    private const COMET = 'Comet Catcher Remake 1.8';

    private static function wire(mixed $value): mixed
    {
        Js::parse(json_encode($value), $out);

        return $out;
    }

    private static function have(mixed $body): array
    {
        return MapHave::parse(self::wire($body));
    }

    private static function keys(mixed $body): array
    {
        $parsed = self::have($body);
        if (! $parsed['ok']) {
            throw new \RuntimeException($parsed['error']);
        }

        return $parsed['keys'];
    }

    private static function held(array ...$rows): array
    {
        $held = [];
        foreach ($rows as [$name, $hash, $version]) {
            $held[$name] = ['sourceHash' => $hash, 'catalogVersion' => $version];
        }

        return $held;
    }

    private static function statuses(array $held): array
    {
        return array_column(MapHave::body(self::keys(['keys' => [self::KEY]]), $held)['results'], 'status');
    }

    private static function bad(string $error, int $status = 400): array
    {
        return ['ok' => false, 'error' => $error, 'status' => $status];
    }

    public function test_a_key_parses_into_the_three_facts_that_decide_a_status(): void
    {
        $this->assertSame(['mapName' => self::COMET, 'sourceHash' => 'src-a', 'catalogVersion' => 3], self::keys(['keys' => [self::KEY]])[0]);
    }

    public function test_have_refuses_malformed_bodies(): void
    {
        foreach (['[]', 'null', '"keys"', '3'] as $raw) {
            Js::parse($raw, $body);
            $this->assertFalse(MapHave::parse($body)['ok']);
        }
        $this->assertSame(self::bad('Unknown field: since'), self::have(['keys' => [self::KEY], 'since' => 'yesterday']));
        $this->assertSame(self::bad('keys[1] unknown field: facts_digest'), self::have(['keys' => [self::KEY, self::KEY + ['facts_digest' => 'd']]]));
        $this->assertSame(self::bad('keys[0] must be a JSON object.'), self::have(['keys' => [self::COMET]]));
        $this->assertSame(self::bad('`keys` must not be empty.'), self::have(['keys' => []]));
        $this->assertSame(self::bad('`keys` is required and must be an array.'), self::have(['keys' => ['map_name' => 'x']]));
    }

    public function test_have_holds_names_and_hashes_to_the_column_lengths(): void
    {
        $this->assertSame(self::bad('keys[0] `map_name` is required and must be a string of 1 to 256 characters.'), self::have(['keys' => [['map_name' => '  '] + self::KEY]]));
        $this->assertSame(self::bad('keys[0] `map_name` is required and must be a string of 1 to 256 characters.'), self::have(['keys' => [['map_name' => str_repeat('m', 257)] + self::KEY]]));
        $this->assertCount(1, self::keys(['keys' => [['map_name' => str_repeat('m', 256)] + self::KEY]]));
        $this->assertSame(self::bad('keys[0] `source_hash` is required and must be a string of 1 to 128 characters.'), self::have(['keys' => [['source_hash' => str_repeat('s', 129)] + self::KEY]]));
    }

    public function test_a_catalog_version_is_a_required_positive_integer(): void
    {
        $error = self::bad('keys[0] `catalog_version` is required and must be an integer of 1 or more.');
        $this->assertSame($error, self::have(['keys' => [['map_name' => self::COMET, 'source_hash' => 'src-a']]]));
        foreach ([3.5, '3', null, true, 0, -1] as $version) {
            $this->assertSame($error, self::have(['keys' => [['catalog_version' => $version] + self::KEY]]), var_export($version, true));
        }
        Js::parse('{"keys":[{"map_name":"m","source_hash":"h","catalog_version":1e400}]}', $infinite);
        $this->assertSame($error, MapHave::parse($infinite));
        $this->assertSame(1, self::keys(['keys' => [['catalog_version' => 1] + self::KEY]])[0]['catalogVersion']);
    }

    public function test_have_cap_and_repeats(): void
    {
        $this->assertSame(500, MapHave::maxKeys());
        $atLimit = array_map(fn (int $i) => ['map_name' => 'Map '.$i] + self::KEY, range(0, 499));
        $this->assertTrue(self::have(['keys' => $atLimit])['ok']);
        $this->assertSame(
            self::bad('A batch may carry at most 500 keys. That request carried 501. Split it.', 413),
            self::have(['keys' => [...$atLimit, ['map_name' => 'Map 500'] + self::KEY]]),
        );
        $this->assertSame(self::bad('keys[1] repeats a key already in the batch.'), self::have(['keys' => [self::KEY, ['source_hash' => 'src-z'] + self::KEY]]));
    }

    public function test_a_have_body_answers_in_request_order(): void
    {
        $body = MapHave::body(
            self::keys(['keys' => [['map_name' => 'Zed 1.0'] + self::KEY, ['map_name' => 'Alpha 1.0'] + self::KEY, ['map_name' => 'Middle 1.0'] + self::KEY]]),
            self::held(['Alpha 1.0', 'src-a', 3]),
        );

        $this->assertSame([
            'format' => 'coilbox-hub-map-have',
            'version' => 1,
            'results' => [
                ['map_name' => 'Zed 1.0', 'status' => 'missing'],
                ['map_name' => 'Alpha 1.0', 'status' => 'have'],
                ['map_name' => 'Middle 1.0', 'status' => 'missing'],
            ],
        ], $body);
    }

    public function test_the_status_rule(): void
    {
        $this->assertSame(['missing'], self::statuses([]));
        $this->assertSame(['have'], self::statuses(self::held([self::COMET, 'src-a', 3])));
        $this->assertSame(['changed'], self::statuses(self::held([self::COMET, 'src-a', 2])));
        $this->assertSame(['have'], self::statuses(self::held([self::COMET, 'src-a', 9])));
        foreach ([1, 3, 9] as $stored) {
            $this->assertSame(['changed'], self::statuses(self::held([self::COMET, 'src-other', $stored])));
        }
    }

    public function test_a_have_result_says_nothing_about_what_the_hub_holds(): void
    {
        $json = json_encode(MapHave::body(self::keys(['keys' => [self::KEY]]), self::held([self::COMET, 'src-stored', 7])));

        $this->assertStringNotContainsString('src-stored', $json);
        $this->assertStringNotContainsString('catalog_version', $json);
    }

    private static function lookup(mixed $body): array
    {
        return MapLookup::parse(self::wire($body));
    }

    public function test_lookup_refuses_malformed_bodies(): void
    {
        foreach (['[]', 'null', '"names"', '3'] as $raw) {
            Js::parse($raw, $body);
            $this->assertFalse(MapLookup::parse($body)['ok']);
        }
        $this->assertSame(self::bad('Unknown field: since'), self::lookup(['names' => [self::COMET], 'since' => 'yesterday']));
        $this->assertSame(self::bad('`names` is required and must be an array.'), self::lookup(['names' => self::COMET]));
        $this->assertSame(self::bad('names[0] must be a string of 1 to 256 characters.'), self::lookup(['names' => [['map_name' => self::COMET]]]));
        $this->assertSame(self::bad('`names` must not be empty.'), self::lookup(['names' => []]));
        $this->assertSame(self::bad('names[0] must be a string of 1 to 256 characters.'), self::lookup(['names' => [str_repeat('m', 257)]]));
        $this->assertTrue(self::lookup(['names' => [str_repeat('m', 256)]])['ok']);
        $this->assertSame(self::bad('names[1] must be a string of 1 to 256 characters.'), self::lookup(['names' => [self::COMET, '  ']]));
    }

    public function test_lookup_cap(): void
    {
        $this->assertSame(500, MapLookup::maxNames());
        $atLimit = array_map(fn (int $i) => 'Map '.$i, range(0, 499));
        $this->assertTrue(self::lookup(['names' => $atLimit])['ok']);
        $this->assertSame(
            self::bad('A batch may carry at most 500 names. That request carried 501. Split it.', 413),
            self::lookup(['names' => [...$atLimit, 'One More 1.0']]),
        );
    }

    public function test_a_name_may_appear_twice_and_both_positions_are_answered(): void
    {
        $names = self::lookup(['names' => [self::COMET, 'Other 1.0', self::COMET]])['names'];
        $body = MapLookup::body($names, [self::COMET => ['slug' => 'comet']]);

        $this->assertSame('coilbox-hub-map-lookup', $body['format']);
        $this->assertSame(1, $body['version']);
        $this->assertSame([self::COMET, 'Other 1.0', self::COMET], array_column($body['results'], 'map_name'));
        $this->assertSame(['slug' => 'comet'], $body['results'][0]['map']);
        $this->assertNull($body['results'][1]['map']);
        $this->assertSame($body['results'][0]['map'], $body['results'][2]['map']);
    }

    private static function pack(array $fields): array
    {
        return $fields + [
            'title' => 'BAR maps',
            'blurb' => null,
            'featuredAt' => null,
            'maps' => [['mapName' => 'Isis 1.3', 'slug' => 'isis-1-3', 'displayName' => null, 'archiveFilename' => null, 'takenDown' => false]],
        ];
    }

    public function test_a_pack_list_is_coilboxs_hub_map_pack_shape(): void
    {
        $this->assertSame(['format' => 'coilbox-hub-map-packs', 'version' => 1, 'packs' => []], MapPackList::body([]));

        $body = MapPackList::body([self::pack([
            'id' => '9e0f',
            'blurb' => 'Every map on the mirror',
            'featuredAt' => '2026-09-27T12:00:00Z',
            'maps' => [['mapName' => self::COMET, 'slug' => 'c', 'displayName' => 'Comet Catcher', 'archiveFilename' => 'comet_catcher_remake_1.8.sd7', 'takenDown' => false]],
        ])]);
        $this->assertSame([[
            'id' => '9e0f',
            'title' => 'BAR maps',
            'blurb' => 'Every map on the mirror',
            'featured' => true,
            'maps' => [[
                'id' => self::COMET,
                'title' => 'Comet Catcher',
                'download' => ['kind' => 'map', 'springName' => self::COMET],
                'filename' => 'comet_catcher_remake_1.8.sd7',
            ]],
        ]], $body['packs']);
    }

    public function test_a_map_the_catalog_no_longer_holds_goes_out_under_its_name_alone(): void
    {
        $row = MapPackList::body([self::pack(['id' => 'p'])])['packs'][0];

        $this->assertSame([['id' => 'Isis 1.3', 'title' => 'Isis 1.3', 'download' => ['kind' => 'map', 'springName' => 'Isis 1.3']]], $row['maps']);
        $this->assertArrayNotHasKey('blurb', $row);
        $this->assertFalse($row['featured']);
    }

    public function test_empty_and_taken_down_packs_are_left_out(): void
    {
        $body = MapPackList::body([self::pack(['id' => 'first']), self::pack(['id' => 'empty', 'maps' => []]), self::pack(['id' => 'second'])]);
        $this->assertSame(['first', 'second'], array_column($body['packs'], 'id'));

        $down = ['mapName' => 'Taken Down 1.0', 'slug' => null, 'displayName' => null, 'archiveFilename' => null, 'takenDown' => true];
        $body = MapPackList::body([
            self::pack(['id' => 'mixed', 'maps' => [$down, ['mapName' => 'Isis 1.3', 'takenDown' => false] + $down]]),
            self::pack(['id' => 'only-down', 'maps' => [$down]]),
        ]);
        $this->assertSame(['mixed'], array_column($body['packs'], 'id'));
        $this->assertSame(['Isis 1.3'], array_column($body['packs'][0]['maps'], 'id'));
    }
}
