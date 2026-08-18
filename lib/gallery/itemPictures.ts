/**
 * The pictures one item page needs, in one lookup (issue #109).
 *
 * This is the milestone's payoff wired up. Everything under `lib/assets` exists
 * so that a page can ask "what does this look like" and get an answer, and this
 * is the first caller that asks. It sits between the page and
 * `lib/assets/resolve.ts` so that neither component below has to know how the
 * ladder works or how many rows one page is worth.
 *
 * One call, not one per card. A blueprint of thirty buildings is thirty
 * identities and a map is one more, a setup pack's own list of maps is however
 * many it installs, and {@link fetchHeldAssets} takes the lot in a single
 * batched query. Resolving per building would be thirty round trips for a
 * picture.
 *
 * ## Two absences, drawn two different ways
 *
 * A map with no picture gets `components/AssetPlaceholder.tsx`, because the item
 * page has a slot the width of a thumbnail there and an empty one reads as
 * broken. So {@link ItemPictures.map} carries the whole {@link ResolvedAsset}
 * union and the component switches over it.
 *
 * A unit with no picture is drawn as its footprint, exactly as
 * `lib/gallery/blueprintPreview.ts` has always drawn it: a rounded square as big
 * as the ground it stands on, on the plan's own grid. That is already a better
 * answer than a dashed box in a plan of thirty, and it is the drawing the whole
 * blueprint preview was built around. So {@link ItemPictures.units} holds only
 * the units the hub actually has a picture of, and a def missing from it means
 * "draw it the way you always did" rather than "draw nothing".
 *
 * ## The unit half is asked for from above, and substituted when it has to be
 *
 * A plan is drawn from above, so it asks for `render:top` rather than the
 * buildpic. A buildpic is a three quarter icon, which on a footprint is a
 * picture of the building from somewhere the plan is not.
 *
 * Where no top render exists, `resolveAsset` serves the buildpic instead, so
 * `substituted` does come back true here and the page is no worse off than it
 * was before it started asking. That costs no extra query: `ladderIdentities`
 * puts the buildpic behind each render into the same batch.
 *
 * Nothing on the page labels a unit picture, so no caption has to qualify
 * itself. A later caller that captions one must read `served.variant` first,
 * which is what that field is for. The map half has no substitute at all: a
 * minimap and an overlay are different pictures of different things, not
 * different views of one.
 */

import type { SupabaseClient } from "@supabase/supabase-js";
import {
  type AssetIdentity,
  MAP_MINIMAP_VARIANT,
  UNIT_TOP_RENDER_VARIANT,
} from "@/lib/assets/asset";
import {
  fetchHeldAssets,
  type ResolvedAsset,
  resolveAsset,
  type ServedAsset,
} from "@/lib/assets/resolve";
import { parseBlueprintPayload } from "@/lib/blueprint/payload";
import { setupPackMaps } from "./setupPackPreview";

/** As much of an item as choosing its pictures depends on. */
export interface PicturedItem {
  kind: string;
  container: unknown;
  /** The game's shortname, which is half of a unit's identity. A blueprint
   *  published without one names units nothing can be looked up by. */
  game_key: string | null;
  map_name: string | null;
}

export interface ItemPictures {
  /** The map's picture, or the placeholder standing in for it. Null when the
   *  item names no map at all, which is a page with no map slot rather than a
   *  page with an empty one. */
  map: ResolvedAsset | null;
  /** Def name in lower case to the picture the hub holds of that unit. A def
   *  with no picture is absent. */
  units: ReadonlyMap<string, ServedAsset>;
  /** Map name to picture, for a setup pack's own list of maps (issue #176).
   *  Every map the pack names has an entry, since a map with nothing stored
   *  resolves to the placeholder rather than to nothing. */
  packMaps: ReadonlyMap<string, ResolvedAsset>;
}

const NOTHING: ItemPictures = {
  map: null,
  units: new Map(),
  packMaps: new Map(),
};

/** The unit half of {@link AssetIdentity}, so a list known to be all units does
 *  not have to be narrowed again to read a unit name off it. */
type UnitIdentity = Extract<AssetIdentity, { keyedOn: "unit" }>;

/** The map half, for the same reason. */
type MapIdentity = Extract<AssetIdentity, { keyedOn: "map" }>;

/**
 * One minimap identity per map a setup pack installs, in the order the pack
 * lists them (issue #176).
 *
 * Empty for every other kind. A preset names its map on the row and gets it
 * through {@link PicturedItem.map_name}, and a pack of four maps has one name
 * on the row at most (`lib/gallery/publish.ts`), so the payload is the only
 * place the whole list is.
 */
export function packMapIdentities(item: PicturedItem): MapIdentity[] {
  if (item.kind !== "setup-pack") return [];

  return setupPackMaps(item.container).map((mapName) => ({
    keyedOn: "map",
    mapName,
    variant: MAP_MINIMAP_VARIANT,
  }));
}

/**
 * One top render identity per distinct def in a blueprint, in the order the
 * layout places them.
 *
 * Lower cased, the way `declaredFootprint` reads a footprint, because a layout
 * holds whatever its author typed and the hub stores one row per unit. A layout
 * with twenty solar collectors is therefore one identity.
 */
export function blueprintUnitIdentities(item: PicturedItem): UnitIdentity[] {
  const game = item.game_key;
  if (item.kind !== "blueprint" || !game) return [];

  const payload = (item.container as { payload?: unknown } | null)?.payload;
  const blueprint = parseBlueprintPayload(payload);
  if (!blueprint) return [];

  const defs = new Set(blueprint.buildings.map((b) => b.def.toLowerCase()));
  return [...defs].map((unitName) => ({
    keyedOn: "unit",
    game,
    unitName,
    variant: UNIT_TOP_RENDER_VARIANT,
  }));
}

/**
 * What to draw for this item.
 *
 * Wants a session or anonymous client, so row level security is underneath the
 * answer as well as the two checks {@link fetchHeldAssets} and
 * {@link resolveAsset} make themselves.
 */
export async function itemPictures(
  supabase: SupabaseClient,
  item: PicturedItem,
): Promise<ItemPictures> {
  const mapIdentity: MapIdentity | null = item.map_name
    ? { keyedOn: "map", mapName: item.map_name, variant: MAP_MINIMAP_VARIANT }
    : null;
  const packIdentities = packMapIdentities(item);
  const unitIdentities = blueprintUnitIdentities(item);
  if (!mapIdentity && packIdentities.length === 0 && unitIdentities.length === 0) {
    return NOTHING;
  }

  const held = await fetchHeldAssets(supabase, [
    ...(mapIdentity ? [mapIdentity] : []),
    ...packIdentities,
    ...unitIdentities,
  ]);

  const units = new Map<string, ServedAsset>();
  for (const identity of unitIdentities) {
    const picture = resolveAsset(identity, held);
    if (picture.from !== "placeholder") units.set(identity.unitName, picture);
  }

  // Unlike a unit, every one of these is shown: a pack's map has a card of its
  // own, so a map with nothing stored keeps the placeholder rather than
  // dropping out of the list of what the pack installs.
  const packMaps = new Map<string, ResolvedAsset>();
  for (const identity of packIdentities) {
    packMaps.set(identity.mapName, resolveAsset(identity, held));
  }

  return {
    map: mapIdentity ? resolveAsset(mapIdentity, held) : null,
    units,
    packMaps,
  };
}
