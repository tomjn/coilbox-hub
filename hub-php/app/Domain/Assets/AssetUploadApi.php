<?php

namespace App\Domain\Assets;

use stdClass;

/**
 * The upload declaration, the JSON part of `POST /api/v1/assets/upload`,
 * `lib/api/assetUpload.ts` in the Next app.
 *
 * `width`, `height` and `hash` are absent on purpose. The hub measures the
 * header and hashes the bytes itself, and the unknown field rule turns a client
 * that still sends them into a 400 naming the field. `source_hash` stays,
 * because it is over the archive, which never reaches the hub.
 *
 * A declaration is an array: `identity` (see {@see AssetResolver}),
 * `sourceHash`, `encodeProfile`, `origin`, `mime`, `bytes`, `sourceArchive`,
 * `mapWidth`, `mapHeight`, `worldHeightMin`, `worldHeightMax`.
 */
final class AssetUploadApi
{
    public const FORMAT = 'coilbox-hub-asset-upload';

    public const VERSION = 1;

    private const COMMON = ['keyed_on', 'variant', 'source_hash', 'encode_profile', 'origin', 'mime', 'bytes', 'source_archive'];

    /** @return list<string> */
    private static function fields(string $keyedOn): array
    {
        return match ($keyedOn) {
            'unit' => [...self::COMMON, 'game', 'unit_name'],
            'faction' => [...self::COMMON, 'game', 'faction_key'],
            'map' => [...self::COMMON, 'map_name', 'map_width', 'map_height', 'world_height_min', 'world_height_max'],
        };
    }

    /** @return array{ok: true, declaration: array<string, mixed>}|array{ok: false, error: string} */
    public static function parse(mixed $value): array
    {
        if (! $value instanceof stdClass) {
            return ['ok' => false, 'error' => 'The asset declaration must be a JSON object.'];
        }

        $keyedOn = Js::get($value, 'keyed_on');
        if (! in_array($keyedOn, ['unit', 'map', 'faction'], true)) {
            return ['ok' => false, 'error' => '`keyed_on` must be "unit", "map" or "faction".'];
        }

        $extra = Js::unknownField($value, self::fields($keyedOn));
        if ($extra !== null) {
            return ['ok' => false, 'error' => 'Unknown field: '.$extra];
        }

        $text = [];
        foreach (['variant', 'source_hash', 'encode_profile', 'mime', 'source_archive'] as $field) {
            $read = Fields::text($value, $field);
            if (! $read['ok']) {
                return $read;
            }
            $text[$field] = $read['value'];
        }

        $origin = Js::get($value, 'origin');
        if (! is_string($origin) || ! in_array($origin, Asset::origins(), true)) {
            return ['ok' => false, 'error' => '`origin` must be one of '.implode(', ', Asset::origins()).'.'];
        }

        $bytes = Fields::count($value, 'bytes');
        if (! $bytes['ok']) {
            return $bytes;
        }

        $variant = $text['variant'];
        $common = [
            'sourceHash' => $text['source_hash'],
            'encodeProfile' => $text['encode_profile'],
            'origin' => $origin,
            'mime' => $text['mime'],
            'bytes' => $bytes['value'],
            'sourceArchive' => $text['source_archive'],
        ];
        $noMap = ['mapWidth' => null, 'mapHeight' => null, 'worldHeightMin' => null, 'worldHeightMax' => null];

        if ($keyedOn === 'map') {
            $mapName = Fields::text($value, 'map_name');
            if (! $mapName['ok']) {
                return $mapName;
            }
            if (! Asset::isMapVariant($variant)) {
                return ['ok' => false, 'error' => Fields::mapVariantError()];
            }
            // Mandatory, because nothing downstream of extraction can recover the world size.
            $mapWidth = Fields::count($value, 'map_width');
            if (! $mapWidth['ok']) {
                return $mapWidth;
            }
            $mapHeight = Fields::count($value, 'map_height');
            if (! $mapHeight['ok']) {
                return $mapHeight;
            }
            $range = self::heightRange($value, $variant);
            if (! $range['ok']) {
                return $range;
            }

            return ['ok' => true, 'declaration' => [
                'identity' => AssetResolver::map($mapName['value'], $variant),
                ...$common,
                'mapWidth' => $mapWidth['value'],
                'mapHeight' => $mapHeight['value'],
                ...$range['value'],
            ]];
        }

        $game = Fields::text($value, 'game');
        if (! $game['ok']) {
            return $game;
        }

        if ($keyedOn === 'faction') {
            $factionKey = Fields::text($value, 'faction_key');
            if (! $factionKey['ok']) {
                return $factionKey;
            }
            if ($variant !== Asset::factionLogoVariant()) {
                return ['ok' => false, 'error' => Fields::factionVariantError()];
            }

            return ['ok' => true, 'declaration' => [
                'identity' => AssetResolver::faction($game['value'], $factionKey['value'], $variant),
                ...$common,
                ...$noMap,
            ]];
        }

        $unitName = Fields::text($value, 'unit_name');
        if (! $unitName['ok']) {
            return $unitName;
        }
        if (! Fields::isUnitVariant($variant)) {
            return ['ok' => false, 'error' => Fields::unitVariantError()];
        }

        return ['ok' => true, 'declaration' => [
            'identity' => AssetResolver::unit($game['value'], $unitName['value'], $variant),
            ...$common,
            ...$noMap,
        ]];
    }

    /** @return array<string, mixed> */
    public static function body(string $moderation = 'pending'): array
    {
        return ['format' => self::FORMAT, 'version' => self::VERSION, 'moderation' => $moderation];
    }

    /**
     * A height overlay's world range, required on `overlay:height` and refused
     * on everything else. Only the archive has these two numbers.
     *
     * @return array{ok: true, value: array{worldHeightMin: int|float|null, worldHeightMax: int|float|null}}|array{ok: false, error: string}
     */
    private static function heightRange(stdClass $value, string $variant): array
    {
        if ($variant !== Asset::MAP_HEIGHT_OVERLAY_VARIANT) {
            return Js::has($value, 'world_height_min') || Js::has($value, 'world_height_max')
                ? ['ok' => false, 'error' => '`world_height_min` and `world_height_max` belong to "'.Asset::MAP_HEIGHT_OVERLAY_VARIANT.'" and to nothing else.']
                : ['ok' => true, 'value' => ['worldHeightMin' => null, 'worldHeightMax' => null]];
        }

        $min = Fields::measure($value, 'world_height_min');
        if (! $min['ok']) {
            return $min;
        }
        $max = Fields::measure($value, 'world_height_max');
        if (! $max['ok']) {
            return $max;
        }
        if ($max['value'] < $min['value']) {
            return ['ok' => false, 'error' => '`world_height_max` cannot be below `world_height_min`.'];
        }

        return ['ok' => true, 'value' => ['worldHeightMin' => $min['value'], 'worldHeightMax' => $max['value']]];
    }
}
