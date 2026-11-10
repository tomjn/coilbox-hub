<?php

namespace App\Domain\Assets;

use stdClass;

/**
 * The field readers the three asset request parsers share in the Next app
 * (`readField`, `readText`, `readCount`, `readMeasure`). Each returns
 * `['ok' => true, 'value' => ...]` or `['ok' => false, 'error' => ...]`.
 */
final class Fields
{
    /**
     * The lengths `public.asset` accepts. Measured after trimming, the way the
     * check constraints measure, but the untrimmed value is what gets used,
     * because the unique indexes are on the stored text.
     */
    public const MAX_LENGTHS = [
        'game' => 64,
        'unit_name' => 128,
        'faction_key' => 128,
        'map_name' => 256,
        'variant' => 64,
        'source_hash' => 128,
        'encode_profile' => 64,
        'mime' => 128,
        'source_archive' => 256,
    ];

    /** @return array{ok: true, value: string}|array{ok: false, error: string} */
    public static function text(stdClass $record, string $field): array
    {
        $value = Js::get($record, $field);
        $max = self::MAX_LENGTHS[$field];
        $length = is_string($value) ? Js::trimmedLength($value) : 0;
        if (! is_string($value) || $length < 1 || $length > $max) {
            return ['ok' => false, 'error' => '`'.$field.'` is required and must be a string of 1 to '.$max.' characters.'];
        }

        return ['ok' => true, 'value' => $value];
    }

    /** A required positive integer. @return array{ok: true, value: int}|array{ok: false, error: string} */
    public static function count(stdClass $record, string $field): array
    {
        $value = Js::get($record, $field);
        if (! Js::isSafeInteger($value) || $value < 1) {
            return ['ok' => false, 'error' => '`'.$field.'` is required and must be a positive integer.'];
        }

        return ['ok' => true, 'value' => (int) $value];
    }

    /** A required finite number. @return array{ok: true, value: int|float}|array{ok: false, error: string} */
    public static function measure(stdClass $record, string $field): array
    {
        $value = Js::get($record, $field);
        if (! Js::isNumber($value) || ! is_finite((float) $value)) {
            return ['ok' => false, 'error' => '`'.$field.'` is required and must be a number.'];
        }

        return ['ok' => true, 'value' => $value];
    }

    /** The unit variant rule, `asset_unit_variant_check`, and no stricter. */
    public static function isUnitVariant(string $variant): bool
    {
        return $variant === Asset::unitBuildpicVariant() || str_starts_with($variant, Asset::unitRenderVariantPrefix());
    }

    public static function unitVariantError(): string
    {
        return '`variant` on a unit must be "'.Asset::unitBuildpicVariant().'" or "'.Asset::unitRenderVariantPrefix().'<angle>".';
    }

    public static function mapVariantError(): string
    {
        return '`variant` on a map must be one of '.implode(', ', Asset::mapVariants()).'.';
    }

    public static function factionVariantError(): string
    {
        return '`variant` on a faction must be "'.Asset::factionLogoVariant().'".';
    }
}
