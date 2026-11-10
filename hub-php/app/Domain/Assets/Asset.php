<?php

namespace App\Domain\Assets;

/**
 * The words the hub uses for a row in `public.asset`, `lib/assets/asset.ts` in
 * the Next app.
 *
 * The literal lists are repeated in the migrations as check constraints, and
 * `AssetTest` compares the two. The names Coilbox also has to know are read
 * from `resources/vendor/asset-vocabulary.json`, copied from Coilbox.
 */
final class Asset
{
    /** `bucket` is the private staging bucket, `static` the durable tier on GitHub Pages. */
    public const TIERS = ['bucket', 'static'];

    public const MODERATION_STATES = ['pending', 'approved', 'rejected'];

    public const APPROVAL_SOURCES = ['seed', 'bypass', 'moderator'];

    public const REJECTION_KINDS = ['safety', 'editorial', 'unrecorded'];

    /** The two a moderator may choose, in the order the grid offers them. */
    public const MODERATOR_REJECTION_KINDS = ['editorial', 'safety'];

    public const EVENT_ACTIONS = [
        'seeded',
        'bypassed',
        'approved',
        'rejected',
        'returned',
        'conflict_acknowledged',
        'replaced',
    ];

    /** The one variant carrying a world height range. */
    public const MAP_HEIGHT_OVERLAY_VARIANT = 'overlay:height';

    /** The picture of the map itself, as opposed to a layer drawn over it. */
    public const MAP_MINIMAP_VARIANT = 'minimap';

    /** @var array<string, mixed>|null */
    private static ?array $vocabulary = null;

    /** The vendored vocabulary, decoded once. @return array<string, mixed> */
    public static function vocabulary(): array
    {
        return self::$vocabulary ??= json_decode(
            (string) file_get_contents(resource_path('vendor/asset-vocabulary.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /** @return list<string> */
    public static function origins(): array
    {
        return self::vocabulary()['origins'];
    }

    public static function unitBuildpicVariant(): string
    {
        return self::vocabulary()['unit']['buildpicVariant'];
    }

    public static function unitRenderVariantPrefix(): string
    {
        return self::vocabulary()['unit']['renderVariantPrefix'];
    }

    /** Every angle Coilbox draws, in the order a unit's page shows them. @return list<string> */
    public static function unitRenderAngles(): array
    {
        return self::vocabulary()['unit']['renderAngles'];
    }

    /** The unit seen from above, which a blueprint plan asks for by hand. */
    public static function unitTopRenderVariant(): string
    {
        return self::unitRenderVariantPrefix().'top';
    }

    public static function factionLogoVariant(): string
    {
        return self::vocabulary()['faction']['logoVariant'];
    }

    /** @return list<string> */
    public static function mapVariants(): array
    {
        return self::vocabulary()['mapVariants'];
    }

    public static function isMapVariant(string $value): bool
    {
        return in_array($value, self::mapVariants(), true);
    }

    public static function isModeratorRejectionKind(string $value): bool
    {
        return in_array($value, self::MODERATOR_REJECTION_KINDS, true);
    }
}
