<?php

namespace App\Domain\Assets;

use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * The one place that turns an asset into something a page can render,
 * `lib/assets/resolve.ts` in the Next app. Read `README.md` beside this file
 * for how a page uses it.
 *
 * ## Shapes
 *
 * An identity is a plain array, one of three shapes. Build them with
 * {@see unit()}, {@see map()} and {@see faction()}:
 *
 *     ['keyedOn' => 'unit', 'game' => 'BYAR', 'unitName' => 'armsolar', 'variant' => 'buildpic']
 *     ['keyedOn' => 'map', 'mapName' => 'Comet Catcher Remake 1.8', 'variant' => 'minimap']
 *     ['keyedOn' => 'faction', 'game' => 'BYAR', 'factionKey' => 'armada', 'variant' => 'logo']
 *
 * Held assets, from {@see fetchHeldAssets()}, are an array keyed by
 * {@see Have::identityKey()}. Each value is a held row:
 *
 *     ['tier' => 'static'|'bucket', 'path' => string, 'width' => int, 'height' => int,
 *      'moderation' => string, 'world_height_min' => ?float, 'world_height_max' => ?float]
 *
 * A resolved asset, from {@see resolveAsset()}, is one of two shapes. Switch on `from`:
 *
 *     ['from' => 'static'|'bucket', 'url' => string, 'served' => identity,
 *      'substituted' => bool, 'width' => int, 'height' => int]
 *     ['from' => 'placeholder', 'name' => string, 'keyedOn' => string,
 *      'footprint' => ['width' => n, 'height' => n]|null]
 *
 * `served` is whose bytes these are, which differs from what was asked for
 * when `substituted` is true. Use it for alt text. A placeholder array is
 * what `<x-assets.placeholder :of="...">` draws.
 *
 * ## The ladder
 *
 * 1. The atlas, not built yet, so omitted.
 * 2 and 3. Whichever tier the one row for the identity names: the durable tier
 *    on GitHub Pages, or the staging bucket through the hub's own route.
 * 4. Another view of the same unit: the buildpic for a missing render, and a
 *    render for a missing buildpic.
 * 5. A placeholder drawn from the name and the footprint. It cannot fail.
 *
 * ## Approved rows only
 *
 * Row level security shows `anon` approved rows only, {@see fetchHeldAssets()}
 * filters on `moderation` anyway, and {@see resolveAsset()} drops anything not
 * approved before it looks at the tier.
 */
final class AssetResolver
{
    /** Which rung answered. */
    public const SOURCES = ['static', 'bucket', 'placeholder'];

    /**
     * The columns serving needs. Narrow on purpose, since every column here is
     * one a page may disclose. `bytes_missing_at` is read to drop a row.
     *
     * `faction_key` is not here, as in the Next app, so a faction row reads
     * back as a unit identity and a faction logo always resolves to a
     * placeholder through this class.
     */
    private const SERVE_COLUMNS = 'game, unit_name, map_name, variant, tier, path, width, height, moderation, world_height_min, world_height_max, bytes_missing_at';

    /** A buildpic is a three quarter icon, so the angled render is the nearest stand in. */
    private const PORTRAIT_PREFERENCE = ['angled', 'front', 'side', 'top'];

    /** @return array{keyedOn: 'unit', game: string, unitName: string, variant: string} */
    public static function unit(string $game, string $unitName, ?string $variant = null): array
    {
        return ['keyedOn' => 'unit', 'game' => $game, 'unitName' => $unitName, 'variant' => $variant ?? Asset::unitBuildpicVariant()];
    }

    /** @return array{keyedOn: 'map', mapName: string, variant: string} */
    public static function map(string $mapName, string $variant = Asset::MAP_MINIMAP_VARIANT): array
    {
        return ['keyedOn' => 'map', 'mapName' => $mapName, 'variant' => $variant];
    }

    /** @return array{keyedOn: 'faction', game: string, factionKey: string, variant: string} */
    public static function faction(string $game, string $factionKey, ?string $variant = null): array
    {
        return ['keyedOn' => 'faction', 'game' => $game, 'factionKey' => $factionKey, 'variant' => $variant ?? Asset::factionLogoVariant()];
    }

    /**
     * The absolute URL for a tier relative path, given the tier off the row.
     * The only place that chooses between the two tiers.
     */
    public static function assetTierUrl(string $tier, string $path): string
    {
        return match ($tier) {
            'static' => Cdn::staticTierUrl($path),
            'bucket' => Bucket::tierUrl($path),
        };
    }

    /**
     * The buildpic that stands in for a missing render, or null. A map's
     * variants have no substitute: they are pictures of different things.
     *
     * @param  array<string, string>  $identity
     * @return array<string, string>|null
     */
    public static function buildpicSubstitute(array $identity): ?array
    {
        if ($identity['keyedOn'] !== 'unit') {
            return null;
        }
        if (! str_starts_with($identity['variant'], Asset::unitRenderVariantPrefix())) {
            return null;
        }

        return [...$identity, 'variant' => Asset::unitBuildpicVariant()];
    }

    /**
     * The renders that stand in for a missing buildpic, best first, or none
     * when the identity is not a unit's buildpic.
     *
     * @param  array<string, string>  $identity
     * @return list<array<string, string>>
     */
    public static function renderSubstitutes(array $identity): array
    {
        if ($identity['keyedOn'] !== 'unit' || $identity['variant'] !== Asset::unitBuildpicVariant()) {
            return [];
        }

        return array_map(
            fn (string $angle) => [...$identity, 'variant' => Asset::unitRenderVariantPrefix().$angle],
            self::portraitAngles(),
        );
    }

    /**
     * Everything that may stand in for one identity, in the order rung 4 tries
     * them. One level deep and never recursive.
     *
     * @param  array<string, string>  $identity
     * @return list<array<string, string>>
     */
    public static function substituteIdentities(array $identity): array
    {
        $buildpic = self::buildpicSubstitute($identity);

        return $buildpic !== null ? [$buildpic] : self::renderSubstitutes($identity);
    }

    /**
     * Every identity the ladder may need, deduplicated, in the order asked.
     *
     * @param  list<array<string, string>>  $identities
     * @return list<array<string, string>>
     */
    public static function ladderIdentities(array $identities): array
    {
        $wanted = [];
        foreach ($identities as $identity) {
            foreach ([$identity, ...self::substituteIdentities($identity)] as $needed) {
                $key = Have::identityKey($needed);
                // A Map in JavaScript keeps the first insertion position and the last value.
                $wanted[$key] = $needed;
            }
        }

        return array_values($wanted);
    }

    /**
     * What the hub holds for these identities and their substitutes, ready for
     * {@see resolveAsset()}. Pass `Supabase::anon()` or a session client.
     *
     * A chunk that errors is dropped rather than thrown, so a failed lookup
     * draws placeholders rather than taking a page down over a thumbnail.
     *
     * @param  list<array<string, string>>  $identities
     * @return array<string, array{tier: string, path: string, width: int, height: int, moderation: string, world_height_min: int|float|null, world_height_max: int|float|null}>
     */
    public static function fetchHeldAssets(Client $supabase, array $identities): array
    {
        $wanted = self::ladderIdentities($identities);
        $held = [];

        foreach (Have::queryChunks($wanted) as $chunk) {
            try {
                $rows = $supabase->from('asset')
                    ->select(self::SERVE_COLUMNS)
                    ->eq('moderation', 'approved')
                    ->or(implode(',', array_map(Have::identityFilter(...), $chunk)))
                    ->get();
            } catch (SupabaseException) {
                continue;
            }

            foreach ($rows as $row) {
                // A row whose store lost the bytes is served as if there were none.
                if (! empty($row['bytes_missing_at'])) {
                    continue;
                }
                $held[Have::identityKey(Have::rowIdentity($row))] = [
                    'tier' => (string) $row['tier'],
                    'path' => (string) $row['path'],
                    'width' => $row['width'],
                    'height' => $row['height'],
                    'moderation' => (string) $row['moderation'],
                    'world_height_min' => $row['world_height_min'] ?? null,
                    'world_height_max' => $row['world_height_max'] ?? null,
                ];
            }
        }

        return $held;
    }

    /**
     * The held row for an identity, only if the public may be shown it. For a
     * caller that needs a column a resolved asset does not carry, such as a
     * height overlay's world range.
     *
     * @param  array<string, array<string, mixed>>  $held
     * @param  array<string, string>  $identity
     * @return array<string, mixed>|null
     */
    public static function servable(array $held, array $identity): ?array
    {
        $row = $held[Have::identityKey($identity)] ?? null;

        return $row !== null && $row['moderation'] === 'approved' ? $row : null;
    }

    /**
     * The picture to show for one identity. Never fails.
     *
     * `$footprint` is what the placeholder is drawn from: build squares for a
     * unit, 512 elmo squares for a map. See {@see Placeholder}.
     *
     * @param  array<string, string>  $identity
     * @param  array<string, array<string, mixed>>  $held
     * @param  array{width: int|float, height: int|float}|null  $footprint
     * @return array<string, mixed>
     */
    public static function resolveAsset(array $identity, array $held, ?array $footprint = null): array
    {
        $own = self::servable($held, $identity);
        if ($own !== null) {
            return self::serve($identity, $identity, $own);
        }

        foreach (self::substituteIdentities($identity) as $instead) {
            $row = self::servable($held, $instead);
            if ($row !== null) {
                return self::serve($identity, $instead, $row);
            }
        }

        return ['from' => 'placeholder', ...Placeholder::missingPicture($identity, $footprint)];
    }

    /**
     * @param  array<string, string>  $asked
     * @param  array<string, string>  $served
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function serve(array $asked, array $served, array $row): array
    {
        return [
            'from' => $row['tier'],
            'url' => self::assetTierUrl($row['tier'], $row['path']),
            'served' => $served,
            'substituted' => Have::identityKey($asked) !== Have::identityKey($served),
            'width' => $row['width'],
            'height' => $row['height'],
        ];
    }

    /** The vocabulary's angles in portrait order. A stable sort, so a new angle lands last. @return list<string> */
    private static function portraitAngles(): array
    {
        $angles = Asset::unitRenderAngles();
        $rank = function (string $angle): int {
            $at = array_search($angle, self::PORTRAIT_PREFERENCE, true);

            return $at === false ? count(self::PORTRAIT_PREFERENCE) : $at;
        };
        usort($angles, fn (string $a, string $b) => $rank($a) <=> $rank($b));

        return $angles;
    }
}
