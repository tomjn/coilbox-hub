# Pictures

`AssetResolver` is the Next app's `lib/assets/resolve.ts`. A page never asks for a tier or builds a picture URL itself. It names what it wants a picture of, and the resolver returns a URL or a placeholder.

## Drawing pictures on a page

1. Build an identity for each picture with `AssetResolver::unit()`, `::map()` or `::faction()`. Identities are plain arrays.
2. Call `AssetResolver::fetchHeldAssets(Supabase::anon(), $identities)` once for the whole page. It adds the substitute identities itself (a unit's buildpic behind a render, and the renders behind a buildpic), batches 50 to a request, and returns an empty result rather than throwing when Supabase fails.
3. Call `AssetResolver::resolveAsset($identity, $held, $footprint)` per picture. It never fails.
4. Switch on `from`. `static` or `bucket` means draw an `<img>` from `url`, `width` and `height`. `placeholder` means draw `<x-assets.placeholder :of="$picture" />`.

```php
use App\Domain\Assets\AssetResolver;
use App\Supabase\Supabase;

$ids = [
    AssetResolver::unit('BYAR', 'armsolar'),                  // buildpic
    AssetResolver::unit('BYAR', 'armsolar', 'render:top'),    // a render angle
    AssetResolver::map('Comet Catcher Remake 1.8'),           // minimap
];

// Inside a HubCache::remember() loader if the page caches its data.
$held = AssetResolver::fetchHeldAssets(Supabase::anon(), $ids);

$picture = AssetResolver::resolveAsset($ids[0], $held, ['width' => 4, 'height' => 4]);
```

```blade
@if ($picture['from'] === 'placeholder')
    <x-assets.placeholder :of="$picture" class="aspect-square" />
@else
    <img src="{{ $picture['url'] }}" width="{{ $picture['width'] }}" height="{{ $picture['height'] }}"
         alt="{{ $picture['served']['unitName'] ?? $picture['served']['mapName'] }}" loading="lazy">
@endif
```

## Shapes

The full shapes are documented at the top of `AssetResolver.php`.

- A resolved picture is `['from' => 'static'|'bucket', 'url', 'served', 'substituted', 'width', 'height']` or `['from' => 'placeholder', 'name', 'keyedOn', 'footprint']`.
- `served` is the identity whose bytes the URL holds. When `substituted` is true it is not the one you asked for, for example the buildpic standing in for a missing render. Write alt text from `served`.
- The footprint is optional, and with none the placeholder is a square. For a unit it is build squares. For a map it is 512 elmo squares, so `width_elmos / 512`.
- `<x-assets.placeholder>` takes `:of`, an optional `quiet` (no caption, for callers that print the name below) and `class` for the outer box.

## Other entry points

- `AssetResolver::servable($held, $identity)` is the held row, approved only, for a column a resolved picture does not carry. The map preview reads a height overlay's `world_height_min` and `world_height_max` through it.
- `AssetResolver::assetTierUrl($tier, $path)` turns a row's tier and path into a URL.
- `Have::identityKey($identity)` is the key `$held` is indexed by.
- `Asset` has the vocabulary: `Asset::unitBuildpicVariant()`, `unitTopRenderVariant()`, `unitRenderAngles()`, `factionLogoVariant()`, `MAP_MINIMAP_VARIANT`, `MAP_HEIGHT_OVERLAY_VARIANT`.

As in the Next app, `fetchHeldAssets` does not read `faction_key`, so a faction logo identity always resolves to a placeholder through it.
