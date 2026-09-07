import type { SupabaseClient } from "@supabase/supabase-js";
import { MAP_MINIMAP_VARIANT } from "@/lib/assets/asset";
import { fetchHeldAssets, resolveAsset, type ResolvedAsset } from "@/lib/assets/resolve";
import { itemNamesItsMap } from "./itemCardArt";
import type { ItemSummary } from "./query";

/**
 * The map picture every card on a page of the gallery needs, in one lookup
 * (issue #308).
 *
 * One batched query rather than one per card, the same split
 * `lib/maps/query.ts` `mapPictures` makes for the map catalog and
 * `lib/gallery/itemPictures.ts` makes for one item's own page.
 *
 * Unlike either of those, this asks for no footprint. `mapPictures` has the
 * catalog row on hand and draws a map with nothing stored at its own
 * proportions. `itemPictures` reads the catalog for the same reason. A gallery
 * card does not: `components/ItemCardArt.tsx` draws every card in the same
 * fixed box regardless of what fills it, so there is no shape for a footprint
 * to improve on. A map with no picture resolves to a plain square placeholder.
 *
 * Nothing here reads `public.asset_licence` either. That gate protects
 * `public.map_facts`, the richer read behind a map's size, player count and
 * `/map/[slug]` link (`lib/gallery/itemPictures.ts` says why), not the picture
 * itself: `resolveAsset` already never discloses anything beyond what
 * `public.asset` and its `moderation` column already say is approved, on the
 * map catalog's own listing as much as here.
 */

/** A page's worth of map pictures, keyed on the canonical map name, as it
 *  crosses a `"use cache"` boundary. `lib/maps/cached.ts` sets out why a `Map`
 *  cannot cross that boundary directly and has to travel as entries instead. */
export type CardPictureEntries = [string, ResolvedAsset][];

export function cardPicturesFromEntries(
  entries: CardPictureEntries,
): ReadonlyMap<string, ResolvedAsset> {
  return new Map(entries);
}

/**
 * The map picture for every scenario or preset on the page that names one.
 *
 * `supabase` is a session or anonymous client, so row level security sits
 * underneath the answer the same way it does for `mapPictures`.
 */
export async function cardMapPictures(
  supabase: SupabaseClient,
  items: Pick<ItemSummary, "kind" | "map_name">[],
): Promise<ReadonlyMap<string, ResolvedAsset>> {
  // Deduplicated: more than one scenario is often played on the same map.
  const names = [...new Set(items.filter(itemNamesItsMap).map((item) => item.map_name))];
  if (names.length === 0) return new Map();

  const identities = names.map((mapName) => ({
    keyedOn: "map" as const,
    mapName,
    variant: MAP_MINIMAP_VARIANT,
  }));

  const held = await fetchHeldAssets(supabase, identities);

  const pictures = new Map<string, ResolvedAsset>();
  for (const identity of identities) {
    pictures.set(identity.mapName, resolveAsset(identity, held));
  }
  return pictures;
}
