<?php

namespace App\Domain\Gallery;

use App\Domain\Assets\Asset;
use App\Domain\Assets\AssetResolver;
use App\Domain\Container\Json;
use App\Domain\Maps\Labels as MapLabels;
use App\Domain\Maps\Lookup;
use App\Supabase\Client;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use App\Support\HubCache;

/**
 * What an item page shows, from the Next app's `lib/gallery/itemCached.ts`
 * and `lib/gallery/itemPictures.ts`.
 *
 * The public reading is cached: the anonymous client sees a live item and
 * nothing for a withdrawn one, so a null answer means "nothing here for the
 * public", not "no such item". The author and moderators read a withdrawn
 * item with their own client, outside the cache.
 *
 * The container is read as text and decoded twice: as associative arrays for
 * the page, and as objects (`payload`) for the code that writes Lua and the
 * browser drawings, where `{}` and `[]` differ.
 */
final class ItemPage
{
    public const DETAIL_COLUMNS = 'id,kind,mode,container:container::text,title,description,game_name,game_key,map_name,tags,author_name,author_id,created_at,updated_at,import_count,featured_at';

    /**
     * The item as the public sees it, with its pictures, or null. Tagged with
     * the pictures and the catalog too, since an approved minimap changes
     * this page without the row moving.
     *
     * @return array{item: array<string, mixed>, pictures: array<string, mixed>}|null
     */
    public static function public(string $id): ?array
    {
        $view = HubCache::remember('item:'.$id, [HubCache::ITEMS, HubCache::item($id), HubCache::ASSETS, HubCache::MAPS], HubCache::HOURS, function () use ($id) {
            if (! Supabase::configured()) {
                return null;
            }
            $supabase = Supabase::anon();
            try {
                $row = $supabase->from('item')->select(self::DETAIL_COLUMNS)->eq('id', $id)->maybeSingle();
            } catch (SupabaseException) {
                return null;
            }
            if ($row === null) {
                return null;
            }
            $item = self::decode($row);
            $pictures = self::pictures($supabase, self::admin(), $item);
            // The cache will not unserialize objects, so the object form is
            // rebuilt from the text on the way out.
            unset($item['payload']);

            return ['item' => $item, 'pictures' => $pictures];
        });
        if ($view !== null) {
            $view['item']['payload'] = self::payloadObject($view['item']['containerText']);
        }

        return $view;
    }

    /**
     * The item as a signed in visitor's own client sees it, withdrawn or not,
     * with `deleted_at`. Never cached.
     *
     * @return array<string, mixed>|null
     */
    public static function forVisitor(Client $supabase, string $id): ?array
    {
        try {
            $row = $supabase->from('item')->select(self::DETAIL_COLUMNS.',deleted_at')->eq('id', $id)->maybeSingle();
        } catch (SupabaseException) {
            return null;
        }

        return $row === null ? null : self::decode($row);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function decode(array $row): array
    {
        $text = (string) ($row['container'] ?? 'null');
        $row['containerText'] = $text;
        $row['container'] = json_decode($text, true);
        $row['payload'] = self::payloadObject($text);

        return $row;
    }

    /** The container's payload with objects as `stdClass`, or null. */
    private static function payloadObject(string $text): mixed
    {
        $object = Json::decode($text);

        return is_object($object) && property_exists($object, 'payload') ? $object->payload : null;
    }

    /**
     * Every picture the page draws, in one lookup: the row's map, a setup
     * pack's maps, and a picture per unit in a blueprint, a pack's layouts or
     * a project's changelog. Map facts come through the licence gate, which
     * only the admin client may read (#191).
     *
     * @param  array<string, mixed>  $item
     * @return array{map: array<string, mixed>|null, units: array<string, array<string, mixed>>, packMaps: array<string, array<string, mixed>>, catalog: array<string, array<string, mixed>>}
     */
    public static function pictures(Client $supabase, ?Client $admin, array $item): array
    {
        $mapIdentity = ($item['map_name'] ?? '') !== '' ? AssetResolver::map($item['map_name']) : null;
        $packNames = $item['kind'] === 'setup-pack' ? Previews::setupPackMaps($item['container']) : [];
        $packIdentities = array_map(fn ($name) => AssetResolver::map($name), $packNames);
        $unitIdentities = [...self::blueprintUnitIdentities($item), ...self::projectUnitIdentities($item)];
        if ($mapIdentity === null && $packIdentities === [] && $unitIdentities === []) {
            return ['map' => null, 'units' => [], 'packMaps' => [], 'catalog' => []];
        }

        $mapIdentities = [...($mapIdentity !== null ? [$mapIdentity] : []), ...$packIdentities];
        $mapNames = array_values(array_unique(array_map(fn ($identity) => $identity['mapName'], $mapIdentities)));

        $held = AssetResolver::fetchHeldAssets($supabase, [...$mapIdentities, ...$unitIdentities]);
        $catalog = $admin !== null && $mapNames !== [] ? (Lookup::facts($admin, $mapNames) ?? []) : [];

        $footprint = function (string $mapName) use ($catalog): ?array {
            $facts = $catalog[$mapName] ?? null;

            return $facts !== null ? MapLabels::footprint($facts['width_elmos'], $facts['height_elmos']) : null;
        };

        $units = [];
        foreach ($unitIdentities as $identity) {
            $picture = AssetResolver::resolveAsset($identity, $held);
            if ($picture['from'] !== 'placeholder') {
                $units[$identity['unitName']] = $picture;
            }
        }
        $packMaps = [];
        foreach ($packIdentities as $identity) {
            $packMaps[$identity['mapName']] = AssetResolver::resolveAsset($identity, $held, $footprint($identity['mapName']));
        }

        return [
            'map' => $mapIdentity !== null ? AssetResolver::resolveAsset($mapIdentity, $held, $footprint($mapIdentity['mapName'])) : null,
            'units' => $units,
            'packMaps' => $packMaps,
            'catalog' => $catalog,
        ];
    }

    /**
     * A top down picture per distinct building in a blueprint, or in a pack's
     * layouts made for this game. A def with none draws as its footprint.
     *
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    public static function blueprintUnitIdentities(array $item): array
    {
        $game = $item['game_key'] ?? null;
        if ($game === null) {
            return [];
        }
        $payload = $item['container']['payload'] ?? null;
        $defs = [];
        if ($item['kind'] === 'blueprint') {
            $defs = Previews::blueprintDefs($payload);
        } elseif ($item['kind'] === 'setup-pack' && is_array($payload)) {
            foreach (is_array($payload['blueprints'] ?? null) ? $payload['blueprints'] : [] as $layout) {
                $shortname = is_array($layout) && is_array($layout['game'] ?? null) ? ($layout['game']['shortname'] ?? null) : null;
                if ($shortname === null || $shortname === $game) {
                    array_push($defs, ...Previews::blueprintDefs($layout));
                }
            }
        }
        $unique = array_values(array_unique(array_map(mb_strtolower(...), $defs)));

        return array_map(fn ($def) => AssetResolver::unit($game, $def, Asset::unitTopRenderVariant()), $unique);
    }

    /**
     * A buildpic per unit in a project's changelog, up to the rows it draws.
     *
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    public static function projectUnitIdentities(array $item): array
    {
        $game = $item['game_key'] ?? null;
        if ($item['kind'] !== 'mod-project' || $game === null) {
            return [];
        }
        $rows = array_slice(ModProject::changelog($item['container']['payload'] ?? null), 0, ModProject::CHANGELOG_ROW_LIMIT);
        $units = array_map(fn ($row) => $row['unit'], $rows);

        return array_map(fn ($unit) => AssetResolver::unit($game, $unit, Asset::unitBuildpicVariant()), $units);
    }

    private static function admin(): ?Client
    {
        try {
            return Supabase::admin();
        } catch (SupabaseException) {
            return null;
        }
    }
}
