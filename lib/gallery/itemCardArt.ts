import type { ResolvedAsset } from "@/lib/assets/resolve";
import type { GalleryKind } from "@/lib/container";
import type { ItemSummary } from "./query";

/**
 * "Which art does this row get", as a card in the gallery grid (issue #308).
 *
 * Kept apart from `components/ItemCardArt.tsx` so the decision is testable
 * without rendering anything, the same split `lib/gallery/itemArt.ts` makes for
 * the item page's own backdrop.
 *
 * Only a scenario or a preset draws the map it names. A setup pack's `map_name`
 * is only the first of however many maps it installs (`lib/gallery/publish.ts`
 * `describe()`), so drawing it here would claim the pack is about that one map
 * when it may install four. A challenge and a blueprint name no map on the row
 * at all today. Sibling issues #309 and #310 are what widen this for a challenge
 * and a blueprint, and both add a branch here rather than touching the card.
 *
 * A row that qualifies still needs a resolved picture to actually show one:
 * `picture` comes from a page level batched lookup
 * (`lib/gallery/cardPictures.ts`) and is `undefined` for a row nothing was
 * looked up for. That row falls back to the plate rather than an empty slot,
 * which is what keeps this safe to call before every page passes pictures
 * through.
 */

/** Kinds whose `map_name` is safe to draw as *the* map the thing was played on. */
const KINDS_WITH_MAP_ART: ReadonlySet<GalleryKind> = new Set(["scenario", "preset"]);

/** Narrows `item.map_name` to a string, so a caller that has already checked
 *  this does not have to null check it again. */
export function itemNamesItsMap(
  item: Pick<ItemSummary, "kind" | "map_name">,
): item is Pick<ItemSummary, "kind" | "map_name"> & { map_name: string } {
  return KINDS_WITH_MAP_ART.has(item.kind) && Boolean(item.map_name);
}

export type ItemCardArtChoice =
  | { type: "map"; picture: ResolvedAsset }
  | { type: "plate" };

/**
 * The art a card draws: the map's picture (or the placeholder standing in for
 * it, which is still `type: "map"` since either one is a picture of the actual
 * map), or the plate every other row gets.
 */
export function chooseItemCardArt(
  item: Pick<ItemSummary, "kind" | "map_name">,
  picture: ResolvedAsset | undefined,
): ItemCardArtChoice {
  if (itemNamesItsMap(item) && picture) return { type: "map", picture };
  return { type: "plate" };
}
