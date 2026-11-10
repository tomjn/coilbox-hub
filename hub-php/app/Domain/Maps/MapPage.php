<?php

namespace App\Domain\Maps;

use App\Domain\Assets\Asset;
use App\Domain\Assets\AssetResolver;
use App\Domain\Gallery\GalleryQuery;
use App\Supabase\Client;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use App\Support\HubCache;

/**
 * Everything one map's page shows, `lib/maps/page.ts`, `lib/maps/mirrors.ts`
 * and `lib/maps/preview.ts`.
 *
 * The facts come from `public.map_facts`, as the lookup route's do, because the
 * author alias hop and the name a merged author is shown under only exist in
 * the database. The licence table decides whether the page exists at all and
 * only the secret key may read it, so this reads facts as `service_role`.
 * Everything else uses the anonymous client.
 */
final class MapPage
{
    /**
     * The page for a slug, or null for not found. Null covers an unknown slug,
     * a map with no facts, a takedown and an unreadable licence table, so a
     * takedown looks exactly like a map the hub never heard of.
     *
     * Held between requests under the catalog's tag, the map's own, pictures
     * and items, as `mapPageCached` was.
     *
     * @return array<string, mixed>|null
     */
    public static function cached(string $slug): ?array
    {
        return HubCache::remember('maps:map:'.$slug, [HubCache::MAPS, HubCache::map($slug), HubCache::ASSETS, HubCache::ITEMS], HubCache::WEEKS, function () use ($slug) {
            try {
                $admin = Supabase::admin();
            } catch (SupabaseException) {
                return null;
            }

            return self::load(Supabase::anon(), $admin, $slug);
        });
    }

    /** @return array<string, mixed>|null */
    public static function load(Client $supabase, Client $admin, string $slug): ?array
    {
        try {
            $row = $supabase->from('map')->select('id, map_name, archive_filename')->eq('slug', $slug)->maybeSingle();
        } catch (SupabaseException) {
            $row = null;
        }
        if ($row === null) {
            return null;
        }

        $mapName = (string) $row['map_name'];
        $identity = AssetResolver::map($mapName);
        // Asked for in the same lookup as the minimap, so the common answer is one round trip.
        $overlay = AssetResolver::map($mapName, Asset::MAP_HEIGHT_OVERLAY_VARIANT);

        $facts = Lookup::facts($admin, [$mapName])[$mapName] ?? null;
        if ($facts === null) {
            return null;
        }
        $held = AssetResolver::fetchHeldAssets($supabase, [$identity, $overlay]);
        $played = self::playedHere($supabase, $mapName);
        $hosts = self::mirrorHosts($supabase);

        // The catalog's own size, so a 12 x 20 with no picture is drawn as a 12 x 20.
        $picture = AssetResolver::resolveAsset($identity, $held, Labels::footprint($facts['width_elmos'], $facts['height_elmos']));

        return [
            'mapId' => $row['id'],
            'mapName' => $mapName,
            'facts' => $facts,
            'picture' => $picture,
            'preview' => self::preview($mapName, $facts, $held, $picture),
            'played' => $played,
            'mirrors' => self::mirrorLinks($hosts, $mapName, $row['archive_filename'] ?? null),
        ];
    }

    /**
     * Where a point sits in the figure, as percentages. The frame is the map's
     * extent, so it is `x / width` across and `z / height` down.
     *
     * @param  array{x: int|float, z: int|float}  $point
     * @return array{left: float, top: float}
     */
    public static function markerPosition(array $point, int|float $widthElmos, int|float $heightElmos): array
    {
        return ['left' => ($point['x'] / $widthElmos) * 100, 'top' => ($point['z'] / $heightElmos) * 100];
    }

    /** Gallery items made for this map, newest first, one gallery page at most. @return list<array<string, mixed>> */
    private static function playedHere(Client $supabase, string $mapName): array
    {
        try {
            return $supabase->from('item')->select(GalleryQuery::ITEM_SUMMARY_COLUMNS)->eq('map_name', $mapName)
                ->order('created_at', false)->limit(GalleryQuery::PAGE_SIZE)->get();
        } catch (SupabaseException) {
            return [];
        }
    }

    /** The mirror hosts, best first. An error is no hosts, which draws no section. @return list<array<string, mixed>> */
    private static function mirrorHosts(Client $supabase): array
    {
        try {
            return $supabase->from('map_mirror_host')->select('name, url_template, enabled')->eq('enabled', true)->order('sort_order', true)->get();
        } catch (SupabaseException) {
            return [];
        }
    }

    /**
     * One host's URL for one map, or null when the template needs a filename
     * the map does not have. Both placeholders are URL encoded.
     */
    public static function mirrorUrl(string $template, string $mapName, ?string $archiveFilename): ?string
    {
        $filename = $archiveFilename === null ? '' : Js::trim($archiveFilename);
        if (str_contains($template, '{filename}') && $filename === '') {
            return null;
        }

        return str_replace(['{springname}', '{filename}'], [self::encodeURIComponent($mapName), self::encodeURIComponent($filename)], $template);
    }

    /**
     * Every link one map has, in host order. Disabled hosts are dropped here
     * as well as in the query, because that is the rule.
     *
     * @param  list<array<string, mixed>>  $hosts
     * @return list<array{name: string, url: string}>
     */
    public static function mirrorLinks(array $hosts, string $mapName, ?string $archiveFilename): array
    {
        $links = [];
        foreach ($hosts as $host) {
            if (! ($host['enabled'] ?? false)) {
                continue;
            }
            $url = self::mirrorUrl((string) $host['url_template'], $mapName, $archiveFilename);
            if ($url !== null) {
                $links[] = ['name' => (string) $host['name'], 'url' => $url];
            }
        }

        return $links;
    }

    /**
     * What the 3D view needs, or null when there is nothing to draw: no
     * minimap to drape, no approved height overlay, or an overlay without the
     * range that decodes it. The range is the overlay row's, never the map's.
     *
     * @param  array<string, mixed>  $facts
     * @param  array<string, array<string, mixed>>  $held
     * @param  array<string, mixed>  $picture
     * @return array<string, mixed>|null
     */
    public static function preview(string $mapName, array $facts, array $held, array $picture): ?array
    {
        if ($picture['from'] === 'placeholder') {
            return null;
        }
        $overlay = AssetResolver::servable($held, AssetResolver::map($mapName, Asset::MAP_HEIGHT_OVERLAY_VARIANT));
        if ($overlay === null) {
            return null;
        }
        $min = $overlay['world_height_min'] ?? null;
        $max = $overlay['world_height_max'] ?? null;
        if ($min === null || $max === null) {
            return null;
        }

        $place = fn (array $point) => ['x' => $point['x'], 'z' => $point['z']];

        return [
            'heightUrl' => AssetResolver::assetTierUrl($overlay['tier'], $overlay['path']),
            'range' => ['min' => $min, 'max' => $max],
            'textureUrl' => $picture['url'],
            'widthElmos' => $facts['width_elmos'],
            'heightElmos' => $facts['height_elmos'],
            'voidWater' => ($facts['void_water'] ?? null) === true,
            'appearance' => self::readAppearance($facts['appearance'] ?? null),
            'points' => [
                'start' => array_map($place, $facts['points']['start'] ?? []),
                'metal' => array_map($place, $facts['points']['metal'] ?? []),
                'geo' => array_map($place, $facts['points']['geo'] ?? []),
            ],
        ];
    }

    /**
     * The appearance keys the preview knows, from a blob with no schema. A key
     * of the wrong type is ignored and the preview picks its own colour.
     *
     * @return array{water: list<float>|null, waterAlpha: float|null, sunDirection: list<float>|null, sunColour: list<float>|null}
     */
    public static function readAppearance(mixed $blob): array
    {
        $record = is_array($blob) && ! array_is_list($blob) ? $blob : ($blob instanceof \stdClass ? (array) $blob : []);
        $alpha = self::finite($record['waterAlpha'] ?? null);

        return [
            'water' => self::rgb($record['waterColor'] ?? null),
            'waterAlpha' => $alpha === null ? null : min(1, max(0, $alpha)),
            'sunDirection' => self::triple($record['sunDir'] ?? null),
            'sunColour' => self::rgb($record['sunColor'] ?? null),
        ];
    }

    private static function finite(mixed $value): int|float|null
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? $value : null;
    }

    /** @return list<int|float>|null */
    private static function triple(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) !== 3) {
            return null;
        }
        $parts = array_map(self::finite(...), $value);

        return in_array(null, $parts, true) ? null : $parts;
    }

    /** A colour triple, clamped to 0 to 1 so the hue survives. @return list<int|float>|null */
    private static function rgb(mixed $value): ?array
    {
        $parts = self::triple($value);

        return $parts === null ? null : array_map(fn ($c) => min(1, max(0, $c)), $parts);
    }

    /** JavaScript's `encodeURIComponent`. */
    public static function encodeURIComponent(string $value): string
    {
        return strtr(rawurlencode($value), ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')']);
    }
}
