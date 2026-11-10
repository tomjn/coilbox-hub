<?php

namespace Tests\Unit\Assets;

use App\Domain\Assets\AssetHaveApi;
use App\Domain\Assets\AssetPicturesApi;
use App\Domain\Assets\AssetResolver;
use App\Domain\Assets\AssetUploadApi;
use App\Domain\Assets\Cdn;
use App\Domain\Assets\Have;
use Tests\TestCase;

/**
 * Ported from `lib/api/assetUpload.test.ts`, `lib/api/assetHave.test.ts` and
 * `lib/api/assetPictures.test.ts`.
 */
class AssetApisTest extends TestCase
{
    private const UNIT = [
        'keyed_on' => 'unit', 'game' => 'BYAR', 'unit_name' => 'armsolar', 'variant' => 'buildpic',
        'source_hash' => 'raw-abc', 'encode_profile' => 'webp-lossless-256', 'origin' => 'extracted',
        'mime' => 'image/webp', 'bytes' => 4096, 'source_archive' => 'byar_1.2.sdz',
    ];

    private const MAP = [
        'keyed_on' => 'map', 'map_name' => 'Comet Catcher Remake 1.8', 'variant' => 'minimap',
        'map_width' => 8192, 'map_height' => 8192, 'source_hash' => 'raw-def', 'encode_profile' => 'webp-q80-512',
        'origin' => 'extracted', 'mime' => 'image/webp', 'bytes' => 40000, 'source_archive' => 'comet_catcher_remake_1.8.sd7',
    ];

    private const FACTION = [
        'keyed_on' => 'faction', 'game' => 'BYAR', 'faction_key' => 'armada', 'variant' => 'logo',
        'source_hash' => 'raw-ghi', 'encode_profile' => 'webp-lossless-128', 'origin' => 'extracted',
        'mime' => 'image/webp', 'bytes' => 3000, 'source_archive' => 'byar_1.2.sdz',
    ];

    /** As `JSON.parse` would hand it over. */
    private static function js(mixed $value): mixed
    {
        return json_decode(json_encode($value), false);
    }

    private function uploadError(mixed $value): string
    {
        $parsed = AssetUploadApi::parse(self::js($value));
        $this->assertFalse($parsed['ok'], 'expected a refusal');

        return $parsed['error'];
    }

    private static function height(array $over = []): array
    {
        return [...self::MAP, 'variant' => 'overlay:height', 'encode_profile' => 'webp-lossless-512', 'world_height_min' => -40.5, 'world_height_max' => 320.25, ...$over];
    }

    // The upload declaration.

    public function test_a_unit_declaration_becomes_a_unit_identity(): void
    {
        $this->assertSame(['ok' => true, 'declaration' => [
            'identity' => ['keyedOn' => 'unit', 'game' => 'BYAR', 'unitName' => 'armsolar', 'variant' => 'buildpic'],
            'sourceHash' => 'raw-abc', 'encodeProfile' => 'webp-lossless-256', 'origin' => 'extracted', 'mime' => 'image/webp',
            'bytes' => 4096, 'sourceArchive' => 'byar_1.2.sdz', 'mapWidth' => null, 'mapHeight' => null, 'worldHeightMin' => null, 'worldHeightMax' => null,
        ]], AssetUploadApi::parse(self::js(self::UNIT)));
    }

    public function test_a_declaration_cannot_say_how_big_or_where(): void
    {
        $this->assertSame('Unknown field: width', $this->uploadError([...self::UNIT, 'width' => 128]));
        $this->assertSame('Unknown field: height', $this->uploadError([...self::UNIT, 'height' => 128]));
        $this->assertSame('Unknown field: hash', $this->uploadError([...self::UNIT, 'hash' => 'enc-abc']));
        $this->assertSame('Unknown field: sourceHash', $this->uploadError([...self::UNIT, 'sourceHash' => 'raw-abc']));
        $this->assertSame('Unknown field: map_name', $this->uploadError([...self::UNIT, 'map_name' => 'Tangerine 1.1']));
        $this->assertSame('Unknown field: game', $this->uploadError([...self::MAP, 'game' => 'BYAR']));
    }

    /** `Object.keys` puts index-like keys first, so the field named is the one JavaScript would name. */
    public function test_an_index_like_unknown_field_is_named_first(): void
    {
        $this->assertSame('Unknown field: 7', $this->uploadError([...self::UNIT, 'zzz' => 1, '7' => 1]));
    }

    public function test_a_map_declaration(): void
    {
        $parsed = AssetUploadApi::parse(self::js(self::MAP));
        $this->assertSame(AssetResolver::map('Comet Catcher Remake 1.8'), $parsed['declaration']['identity']);
        $this->assertSame(8192, $parsed['declaration']['mapWidth']);

        $without = self::MAP;
        unset($without['map_width']);
        $this->assertSame('`map_width` is required and must be a positive integer.', $this->uploadError($without));
        $this->assertSame('`variant` on a map must be one of minimap, overlay:metal, overlay:type, overlay:height.', $this->uploadError([...self::MAP, 'variant' => 'metal']));
        $this->assertTrue(AssetUploadApi::parse(self::js([...self::MAP, 'variant' => 'overlay:type']))['ok']);
    }

    public function test_variants_origins_and_sizes(): void
    {
        $this->assertSame('`variant` on a unit must be "buildpic" or "render:<angle>".', $this->uploadError([...self::UNIT, 'variant' => 'minimap']));
        $this->assertTrue(AssetUploadApi::parse(self::js([...self::UNIT, 'variant' => 'render:270']))['ok']);
        $this->assertSame('`origin` must be one of extracted, rendered, uploaded.', $this->uploadError([...self::UNIT, 'origin' => 'seeded']));
        $this->assertSame('`bytes` is required and must be a positive integer.', $this->uploadError([...self::UNIT, 'bytes' => 0]));
        $this->assertSame('`bytes` is required and must be a positive integer.', $this->uploadError([...self::UNIT, 'bytes' => 40.5]));
        $this->assertSame('`map_width` is required and must be a positive integer.', $this->uploadError([...self::MAP, 'map_width' => '8192']));
        // `4096.0` is the integer 4096 to JavaScript.
        $this->assertSame(4096, AssetUploadApi::parse(json_decode(str_replace('4096', '4096.0', json_encode(self::UNIT))))['declaration']['bytes']);
    }

    public function test_a_height_overlay_carries_its_world_range_and_nothing_else_may(): void
    {
        $parsed = AssetUploadApi::parse(self::js(self::height()));
        $this->assertSame(-40.5, $parsed['declaration']['worldHeightMin']);
        $this->assertSame(320.25, $parsed['declaration']['worldHeightMax']);

        $without = self::height();
        unset($without['world_height_min']);
        $this->assertSame('`world_height_min` is required and must be a number.', $this->uploadError($without));
        $this->assertSame('`world_height_min` and `world_height_max` belong to "overlay:height" and to nothing else.', $this->uploadError([...self::MAP, 'world_height_min' => 0, 'world_height_max' => 320]));
        // A JSON null is still a field that was sent.
        $this->assertSame('`world_height_min` and `world_height_max` belong to "overlay:height" and to nothing else.', $this->uploadError([...self::MAP, 'world_height_min' => null]));
        $this->assertTrue(AssetUploadApi::parse(self::js(self::height(['world_height_min' => -200, 'world_height_max' => -10])))['ok']);
        $this->assertSame('`world_height_max` cannot be below `world_height_min`.', $this->uploadError(self::height(['world_height_min' => 320, 'world_height_max' => 0])));
    }

    public function test_lengths_shapes_and_the_reply(): void
    {
        $this->assertSame('`game` is required and must be a string of 1 to 64 characters.', $this->uploadError([...self::UNIT, 'game' => str_repeat('g', 65)]));
        $this->assertSame('`source_archive` is required and must be a string of 1 to 256 characters.', $this->uploadError([...self::UNIT, 'source_archive' => str_repeat('a', 257)]));
        $this->assertSame('The asset declaration must be a JSON object.', $this->uploadError([self::UNIT]));
        $this->assertSame('The asset declaration must be a JSON object.', $this->uploadError('unit'));
        $this->assertSame('`keyed_on` must be "unit", "map" or "faction".', $this->uploadError([...self::UNIT, 'keyed_on' => 'blueprint']));

        $this->assertSame(['format' => 'coilbox-hub-asset-upload', 'version' => 1, 'moderation' => 'pending'], AssetUploadApi::body());
        $this->assertSame('approved', AssetUploadApi::body('approved')['moderation']);
    }

    public function test_a_faction_declaration(): void
    {
        $this->assertSame(['ok' => true, 'declaration' => [
            'identity' => ['keyedOn' => 'faction', 'game' => 'BYAR', 'factionKey' => 'armada', 'variant' => 'logo'],
            'sourceHash' => 'raw-ghi', 'encodeProfile' => 'webp-lossless-128', 'origin' => 'extracted', 'mime' => 'image/webp',
            'bytes' => 3000, 'sourceArchive' => 'byar_1.2.sdz', 'mapWidth' => null, 'mapHeight' => null, 'worldHeightMin' => null, 'worldHeightMax' => null,
        ]], AssetUploadApi::parse(self::js(self::FACTION)));
        $this->assertSame('`variant` on a faction must be "logo".', $this->uploadError([...self::FACTION, 'variant' => 'buildpic']));
        $this->assertStringContainsString('`variant` on a unit must be', $this->uploadError([...self::UNIT, 'variant' => 'logo']));
        $this->assertSame('Unknown field: map_width', $this->uploadError([...self::FACTION, 'map_width' => 8192]));
        $noKey = self::FACTION;
        unset($noKey['faction_key']);
        $this->assertSame('`faction_key` is required and must be a string of 1 to 128 characters.', $this->uploadError($noKey));
    }

    // The have check.

    private function haveKey(array $over = []): array
    {
        return [...['keyed_on' => 'unit', 'game' => 'bar', 'unit_name' => 'armsolar', 'variant' => 'buildpic', 'source_hash' => 'raw-abc'], ...$over];
    }

    private function haveError(mixed $body): string
    {
        $parsed = AssetHaveApi::parse(self::js($body));
        $this->assertFalse($parsed['ok']);

        return $parsed['status'].' '.$parsed['error'];
    }

    public function test_have_parsing(): void
    {
        $parsed = AssetHaveApi::parse(self::js(['keys' => [
            $this->haveKey(),
            ['keyed_on' => 'map', 'map_name' => 'Comet', 'variant' => 'minimap', 'source_hash' => 'raw-def'],
            ['keyed_on' => 'faction', 'game' => 'bar', 'faction_key' => 'armada', 'variant' => 'logo', 'source_hash' => 'raw-ghi'],
        ]]));
        $this->assertSame([
            ['identity' => AssetResolver::unit('bar', 'armsolar'), 'sourceHash' => 'raw-abc'],
            ['identity' => AssetResolver::map('Comet'), 'sourceHash' => 'raw-def'],
            ['identity' => AssetResolver::faction('bar', 'armada'), 'sourceHash' => 'raw-ghi'],
        ], $parsed['keys']);

        $this->assertSame('400 The request body must be a JSON object.', $this->haveError([$this->haveKey()]));
        $this->assertSame('400 Unknown field: limit', $this->haveError(['keys' => [$this->haveKey()], 'limit' => 5]));
        $this->assertSame('400 keys[1] unknown field: sourceHash', $this->haveError(['keys' => [$this->haveKey(), $this->haveKey(['unit_name' => 'b', 'sourceHash' => 'x'])]]));
        $this->assertSame('400 keys[0] `keyed_on` must be "unit", "map" or "faction".', $this->haveError(['keys' => [['game' => 'bar']]]));
        $this->assertSame('400 keys[0] `source_hash` is required and must be a string of 1 to 128 characters.', $this->haveError(['keys' => [$this->haveKey(['source_hash' => '  '])]]));
        $this->assertSame('400 keys[0] `variant` on a map must be one of minimap, overlay:metal, overlay:type, overlay:height.', $this->haveError(['keys' => [['keyed_on' => 'map', 'map_name' => 'x', 'variant' => 'overlay:metel', 'source_hash' => 'a']]]));
        $this->assertSame('400 keys[0] `variant` on a faction must be "logo".', $this->haveError(['keys' => [['keyed_on' => 'faction', 'game' => 'g', 'faction_key' => 'k', 'variant' => 'buildpic', 'source_hash' => 'a']]]));
        $this->assertSame('400 `keys` must not be empty.', $this->haveError(['keys' => []]));
        $this->assertSame('400 keys[1] repeats a key already in the batch.', $this->haveError(['keys' => [$this->haveKey(), $this->haveKey(['source_hash' => 'other'])]]));

        $many = array_map(fn ($i) => $this->haveKey(['unit_name' => 'u'.$i]), range(0, 499));
        $this->assertTrue(AssetHaveApi::parse(self::js(['keys' => $many]))['ok']);
        $this->assertSame('413 A batch may carry at most 500 keys. That request carried 501. Split it.', $this->haveError(['keys' => [...$many, $this->haveKey(['unit_name' => 'one more'])]]));

        // The same name under two shapes is not a repeat.
        $this->assertTrue(AssetHaveApi::parse(self::js(['keys' => [
            ['keyed_on' => 'map', 'map_name' => 'armsolar', 'variant' => 'minimap', 'source_hash' => 'a'],
            $this->haveKey(),
        ]]))['ok']);
    }

    public function test_have_answers(): void
    {
        $keys = AssetHaveApi::parse(self::js(['keys' => [
            $this->haveKey(),
            $this->haveKey(['unit_name' => 'changed']),
            $this->haveKey(['unit_name' => 'missing']),
            ['keyed_on' => 'faction', 'game' => 'bar', 'faction_key' => 'armada', 'variant' => 'logo', 'source_hash' => 'raw-ghi'],
        ]]))['keys'];
        $hashes = [
            Have::identityKey(AssetResolver::unit('bar', 'armsolar')) => 'raw-abc',
            Have::identityKey(AssetResolver::unit('bar', 'changed')) => 'raw-old',
        ];

        $this->assertSame([
            'format' => 'coilbox-hub-asset-have',
            'version' => 1,
            'results' => [
                ['keyed_on' => 'unit', 'game' => 'bar', 'unit_name' => 'armsolar', 'variant' => 'buildpic', 'status' => 'have'],
                ['keyed_on' => 'unit', 'game' => 'bar', 'unit_name' => 'changed', 'variant' => 'buildpic', 'status' => 'changed'],
                ['keyed_on' => 'unit', 'game' => 'bar', 'unit_name' => 'missing', 'variant' => 'buildpic', 'status' => 'missing'],
                ['keyed_on' => 'faction', 'game' => 'bar', 'faction_key' => 'armada', 'variant' => 'logo', 'status' => 'missing'],
            ],
        ], AssetHaveApi::body($keys, $hashes));
    }

    // The picture lookup.

    private function picturesError(mixed $body): string
    {
        $parsed = AssetPicturesApi::parse(self::js($body));
        $this->assertFalse($parsed['ok']);

        return $parsed['status'].' '.$parsed['error'];
    }

    public function test_pictures_parsing(): void
    {
        $this->assertSame(
            [AssetResolver::unit('bar', 'armsolar', 'render:270'), AssetResolver::map('Comet', 'overlay:height')],
            AssetPicturesApi::parse(self::js(['keys' => [
                ['keyed_on' => 'unit', 'game' => 'bar', 'unit_name' => 'armsolar', 'variant' => 'render:270'],
                ['keyed_on' => 'map', 'map_name' => 'Comet', 'variant' => 'overlay:height'],
            ]]))['keys'],
        );
        $this->assertSame('400 keys[0] unknown field: source_hash', $this->picturesError(['keys' => [$this->haveKey()]]));
        $this->assertSame('400 keys[0] `keyed_on` must be "unit" or "map".', $this->picturesError(['keys' => [['keyed_on' => 'faction', 'game' => 'a', 'faction_key' => 'b', 'variant' => 'logo']]]));
        $this->assertSame('400 keys[0] unknown field: game', $this->picturesError(['keys' => [['keyed_on' => 'map', 'map_name' => 'x', 'variant' => 'minimap', 'game' => 'bar']]]));
        $this->assertSame('400 keys[0] `map_name` is required and must be a string of 1 to 256 characters.', $this->picturesError(['keys' => [['keyed_on' => 'map', 'map_name' => str_repeat('m', 257), 'variant' => 'minimap']]]));
        // Measured in UTF-16 units, as JavaScript measures: an emoji is two.
        $this->assertSame('400 keys[0] `game` is required and must be a string of 1 to 64 characters.', $this->picturesError(['keys' => [['keyed_on' => 'unit', 'game' => '😀'.str_repeat('x', 63), 'unit_name' => 'b', 'variant' => 'buildpic']]]));
        $this->assertTrue(AssetPicturesApi::parse(self::js(['keys' => [['keyed_on' => 'unit', 'game' => 'é'.str_repeat('x', 63), 'unit_name' => 'b', 'variant' => 'buildpic']]]))['ok']);
        $this->assertSame('400 `keys` is required and must be an array.', $this->picturesError(['keys' => new \stdClass]));
    }

    public function test_pictures_answers(): void
    {
        config(['hub.asset_cdn_base' => null, 'app.url' => 'https://hub.test']);
        $buildpic = AssetResolver::unit('bar', 'armsolar');
        $render = AssetResolver::unit('bar', 'armsolar', 'render:270');
        $minimap = AssetResolver::map('Comet');
        $pending = AssetResolver::unit('bar', 'pending');
        $nothing = AssetResolver::map('Nothing');
        $held = [
            Have::identityKey($buildpic) => ['tier' => 'static', 'path' => 'units/bar/buildpic/abc.webp', 'width' => 256, 'height' => 256, 'moderation' => 'approved', 'world_height_min' => null, 'world_height_max' => null],
            Have::identityKey($minimap) => ['tier' => 'bucket', 'path' => 'maps/minimap/def.webp', 'width' => 512, 'height' => 384, 'moderation' => 'approved', 'world_height_min' => null, 'world_height_max' => null],
            Have::identityKey($pending) => ['tier' => 'bucket', 'path' => 'units/bar/buildpic/secret.webp', 'width' => 256, 'height' => 256, 'moderation' => 'pending', 'world_height_min' => null, 'world_height_max' => null],
        ];

        $body = AssetPicturesApi::body([$buildpic, $render, $minimap, $pending, $nothing], $held);

        $this->assertSame('coilbox-hub-asset-pictures', $body['format']);
        $this->assertSame(1, $body['version']);
        $this->assertSame(['tier' => 'static', 'path' => 'units/bar/buildpic/abc.webp', 'url' => Cdn::DEFAULT_BASE.'units/bar/buildpic/abc.webp', 'width' => 256, 'height' => 256, 'served_variant' => 'buildpic', 'substituted' => false], $body['results'][0]['picture']);
        $this->assertSame(['keyed_on' => 'unit', 'game' => 'bar', 'unit_name' => 'armsolar', 'variant' => 'render:270'], array_diff_key($body['results'][1], ['picture' => 1]));
        $this->assertSame(['buildpic', true], [$body['results'][1]['picture']['served_variant'], $body['results'][1]['picture']['substituted']]);
        $this->assertSame(['tier' => 'bucket', 'path' => 'maps/minimap/def.webp', 'url' => 'https://hub.test/assets/staged/maps/minimap/def.webp', 'width' => 512, 'height' => 384, 'served_variant' => 'minimap', 'substituted' => false], $body['results'][2]['picture']);
        $this->assertNull($body['results'][3]['picture']);
        $this->assertNull($body['results'][4]['picture']);
        $this->assertStringNotContainsString('secret', json_encode($body));
        $this->assertStringNotContainsString('moderation', json_encode($body));
    }
}
