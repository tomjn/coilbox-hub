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
 *
 * ## Every map comes with its facts, through the licence gate
 *
 * A map's size draws the placeholder at the right shape, its size and player
 * count caption the picture, and its slug links to `/map/[slug]` (#191). All
 * three come from {@link fetchMapFacts} rather than from a read of `public.map`
 * here.
 *
 * That is the point of going through it. #190 settled that a map denied in
 * `public.asset_licence` publishes nothing at all, no page and no facts, so an
 * item page captioning that map off the catalog would publish the same facts
 * through a second door. A denied map and a map the hub has never heard of both
 * come back absent, and an item naming either is drawn exactly as every map was
 * drawn before this.
 *
 * The gate is why this wants an admin client alongside the visitor's own:
 * `public.asset_licence` is readable by `service_role` and nobody else, which is
 * the same reason `lib/maps/page.ts` takes both.
 *
 * It costs more than the two numbers it reads. `public.map_facts` assembles a
 * map's whole payload, so a pack that installs twenty maps pulls a few hundred
 * metal spots per map in order to count start positions and read a size. It is
 * one call rather than a second gated read path, and a narrower one is a follow
 * up if it ever matters.
 */

import type { SupabaseClient } from "@supabase/supabase-js";
import type { MapFacts } from "@/lib/api/mapLookup";
import {
  type AssetIdentity,
  MAP_MINIMAP_VARIANT,
  UNIT_TOP_RENDER_VARIANT,
} from "@/lib/assets/asset";
import type { Footprint } from "@/lib/assets/placeholder";
import {
  fetchHeldAssets,
  type ResolvedAsset,
  resolveAsset,
  type ServedAsset,
} from "@/lib/assets/resolve";
import { parseBlueprintPayload } from "@/lib/blueprint/payload";
import { mapSquares } from "@/lib/maps/labels";
import { fetchMapFacts } from "@/lib/maps/lookup";
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
  /** What the catalog holds for each map this item names, keyed on the name the
   *  item spells it with (issue #191). A map the hub has no row for is absent,
   *  and so is one it holds a row for and may not publish. */
  catalog: ReadonlyMap<string, MapFacts>;
}

const NOTHING: ItemPictures = {
  map: null,
  units: new Map(),
  packMaps: new Map(),
  catalog: new Map(),
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
 * What the catalog holds for these maps, or nothing at all when it could not be
 * asked.
 *
 * A read that failed is read as an empty catalog rather than propagated, which
 * is `fetchHeldAssets`'s reading of the same failure and for the same reason. An
 * item page is a page about an item. So when the hub cannot say how big the map
 * is, the honest render is the one it gave for every map until now: the picture,
 * and no claim about it. Turning it into a 500 would take down an item page over
 * a caption.
 */
async function catalogFacts(
  admin: SupabaseClient,
  mapNames: string[],
): Promise<ReadonlyMap<string, MapFacts>> {
  if (mapNames.length === 0) return new Map();

  const lookup = await fetchMapFacts(admin, mapNames);
  return lookup.ok ? lookup.facts : new Map();
}

/**
 * What to draw for this item.
 *
 * `supabase` is a session or anonymous client, so row level security is
 * underneath the pictures as well as the two checks {@link fetchHeldAssets} and
 * {@link resolveAsset} make themselves. `admin` reads the catalog and the
 * licence gate over it, which no other key may, and the note at the top of this
 * file says why that gate is the way in.
 */
export async function itemPictures(
  supabase: SupabaseClient,
  admin: SupabaseClient,
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

  const mapIdentities = [...(mapIdentity ? [mapIdentity] : []), ...packIdentities];
  // A pack may install a map the row also names, and the item's own map is one
  // of a pack's list often enough to be worth deduplicating.
  const mapNames = [...new Set(mapIdentities.map((identity) => identity.mapName))];

  // Both batches at once. Neither depends on the other, and running them in
  // series would double what the page waits on for two reads of the same names.
  const [held, catalog] = await Promise.all([
    fetchHeldAssets(supabase, [...mapIdentities, ...unitIdentities]),
    catalogFacts(admin, mapNames),
  ]);

  // The catalog's own size, so a 12 x 20 map with no stored minimap is drawn as
  // a 12 x 20 rather than as a square. In squares rather than elmos, which is
  // the unit `lib/assets/placeholder.ts` says a map's footprint is in.
  const footprint = (mapName: string): Footprint | null => {
    const facts = catalog.get(mapName);
    return facts ? mapSquares(facts.width_elmos, facts.height_elmos) : null;
  };

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
    packMaps.set(identity.mapName, resolveAsset(identity, held, footprint(identity.mapName)));
  }

  return {
    map: mapIdentity
      ? resolveAsset(mapIdentity, held, footprint(mapIdentity.mapName))
      : null,
    units,
    packMaps,
    catalog,
  };
}
