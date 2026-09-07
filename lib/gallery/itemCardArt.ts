import type { ResolvedAsset } from "@/lib/assets/resolve";
import type { GalleryKind } from "@/lib/container";
import type { CardShape } from "./cardShapes";
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
 * when it may install four. A blueprint names no map on the row at all today.
 * Sibling issue #310 is what widens this for a blueprint, and adds a branch
 * here rather than touching the card.
 *
 * A challenge draws the galaxy or run its `shape` carries (issue #309): a
 * conquest challenge whose shape rebuilt is a `"galaxy"`, a warpath challenge
 * whose shape rebuilt is a `"run"`. Nothing else qualifies, which already
 * covers every way a challenge card can miss out on a drawing: a mode a newer
 * coilbox introduced that this hub cannot generate, a challenge whose
 * settings would not rebuild, and a blueprint's own `"blueprint"` shape, which
 * stays on the kind plate until #310. `shape` comes from a page level batched
 * lookup (`lib/gallery/cardShapes.ts`) and is `undefined` for a kind with
 * nothing to draw and for one that could not be rebuilt, either of which
 * falls back to the plate the same as a picture nothing was looked up for.
 *
 * A row that qualifies for a map still needs a resolved picture to actually
 * show one: `picture` comes from a page level batched lookup
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
  | { type: "art"; shape: Extract<CardShape, { type: "galaxy" | "run" }> }
  | { type: "plate" };

/**
 * The art a card draws: the map's picture (or the placeholder standing in for
 * it, which is still `type: "map"` since either one is a picture of the actual
 * map), a challenge's galaxy or run, or the plate every other row gets.
 */
export function chooseItemCardArt(
  item: Pick<ItemSummary, "kind" | "map_name">,
  picture: ResolvedAsset | undefined,
  shape?: CardShape,
): ItemCardArtChoice {
  if (itemNamesItsMap(item) && picture) return { type: "map", picture };
  if (item.kind === "challenge" && (shape?.type === "galaxy" || shape?.type === "run")) {
    return { type: "art", shape };
  }
  return { type: "plate" };
}
