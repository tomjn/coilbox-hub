import type { SupabaseClient } from "@supabase/supabase-js";
import { type BlueprintShape, blueprintShape } from "./blueprintPreview";
import { conquestGalaxy, type GalaxyShape } from "./conquestGalaxy";
import type { ItemSummary } from "./query";
import { type RunShape, warpathRun } from "./warpathRun";

/**
 * The drawing a challenge or a blueprint card needs, for a whole page of the
 * gallery in one lookup (issue #307).
 *
 * A listing row deliberately carries no container, so a card had no way to
 * reach the galaxy, the run or the layout its item page draws. This is the
 * answer #307 settled on: ask PostgREST for only the part of the container each
 * kind's generator reads, then run the same generator the item page runs. The
 * numbers are on the issue. The short version is that the premise behind
 * storing a precomputed shape in its own column turned out to be backwards. A
 * conquest challenge's container is 389 bytes, the recipe for a galaxy rather
 * than the galaxy, and the galaxy it builds is 7,484 bytes of JSON. Storing the
 * result would have put nineteen times more in the database than reading the
 * recipe does, and would have needed a migration, a backfill, and a rule for
 * what happens when the vendored generator moves on and a stored shape starts
 * disagreeing with the item page. Reading the recipe needs none of that,
 * existing rows work at once, and a card cannot disagree with the item page
 * because both run the same function.
 *
 * ## What is asked for, and what it costs
 *
 * `settings` is everything the two challenge generators read.
 * `name`, `ordered`, `buildings` and `footprints` are everything the blueprint
 * layout reads. A row of the wrong kind has none of those paths and PostgREST
 * answers null for it, so one query serves both kinds and a scenario or a setup
 * pack pays a few bytes of nulls rather than its whole container. Measured over
 * a page of 24 mixed rows against the local stack, the listing carries 23,690
 * bytes with these paths against 132,292 with the container column added, which
 * is what makes this affordable at all.
 *
 * The rebuild is the real cost and it is bounded. Twenty four conquest galaxies
 * at the 80 node cap take 46 ms, twenty four long warpath runs take 0.8 ms, and
 * twenty four thirty-building layouts take 0.3 ms. That is once per cache fill,
 * not once per view: the callers are `galleryPage()` and `newestItems()` in
 * `lib/gallery/cached.ts`, both held for hours.
 *
 * ## Only rows that could be drawn are asked about
 *
 * `mode` is already on the listing row, generated in the database from the
 * payload, so which challenges are worth asking about is settled before the
 * query runs. A page with no challenge and no blueprint on it makes no query at
 * all.
 *
 * `supabase` is the same anonymous client the listing itself was read with, so
 * row level security answers the same way twice and a withdrawn row cannot
 * arrive here after being filtered out of the listing.
 */

/** The one drawing a card gets. A card has exactly one or none, because a row
 *  is one kind and a kind has one drawing. */
export type CardShape =
  | { type: "galaxy"; galaxy: GalaxyShape }
  | { type: "run"; run: RunShape }
  | { type: "blueprint"; layout: BlueprintShape };

/** A page's worth of drawings, keyed on the item id, as it crosses a
 *  `"use cache"` boundary. `lib/maps/cached.ts` sets out why a `Map` cannot
 *  cross that boundary directly and has to travel as entries instead. */
export type CardShapeEntries = [string, CardShape][];

export function cardShapesFromEntries(
  entries: CardShapeEntries,
): ReadonlyMap<string, CardShape> {
  return new Map(entries);
}

/** The challenge modes the hub can rebuild. A mode from a newer coilbox is not
 *  one of them, and asking for its settings would buy nothing. */
const DRAWN_MODES: ReadonlySet<string> = new Set(["conquest", "warpath"]);

/** Whether this row has a drawing to fetch at all. */
export function itemHasCardShape(
  item: Pick<ItemSummary, "kind" | "mode">,
): boolean {
  if (item.kind === "challenge") {
    return item.mode !== null && DRAWN_MODES.has(item.mode);
  }
  return item.kind === "blueprint";
}

/** The slices of one row's container the generators read, exactly as PostgREST
 *  hands them back. Every field is null for a row whose payload has no such
 *  path, which is every field of the wrong kind. */
export interface CardShapeParts {
  settings: unknown;
  name: unknown;
  ordered: unknown;
  buildings: unknown;
  footprints: unknown;
}

/** The JSON paths above, as a PostgREST select. Aliased so each one arrives
 *  under the name the payload gives it rather than under a path expression. */
export const CARD_SHAPE_COLUMNS =
  "id," +
  "settings:container->payload->settings," +
  "name:container->payload->>name," +
  "ordered:container->payload->ordered," +
  "buildings:container->payload->buildings," +
  "footprints:container->payload->footprints";

/**
 * Rebuild one row's drawing, or null when it cannot be rebuilt.
 *
 * Null is the ordinary answer, not a failure: a challenge whose settings a
 * newer coilbox has changed, a blueprint with no buildings in it, a row whose
 * container was not readable. The card falls back to its kind plate, the same
 * way `lib/gallery/itemCardArt.ts` already falls back for a map with no
 * picture.
 *
 * The payload handed to each generator is rebuilt from the parts rather than
 * fetched whole, so `conquestGalaxy`, `warpathRun` and `blueprintShape` see
 * what they would have seen reading the container itself. `mode` comes off the
 * row, where the database already generated it from the same payload.
 */
export function cardShape(
  item: Pick<ItemSummary, "kind" | "mode">,
  parts: CardShapeParts,
): CardShape | null {
  if (item.kind === "challenge") {
    const payload = { mode: item.mode, settings: parts.settings };
    if (item.mode === "conquest") {
      const galaxy = conquestGalaxy(payload);
      return galaxy ? { type: "galaxy", galaxy } : null;
    }
    if (item.mode === "warpath") {
      const run = warpathRun(payload);
      return run ? { type: "run", run } : null;
    }
    return null;
  }

  if (item.kind === "blueprint") {
    const layout = blueprintShape({
      name: parts.name,
      ordered: parts.ordered,
      buildings: parts.buildings,
      footprints: parts.footprints,
    });
    return layout ? { type: "blueprint", layout } : null;
  }

  return null;
}

/**
 * The drawing for every challenge and blueprint on the page, in one query.
 *
 * A row nothing came back for, and a row whose parts do not rebuild, is simply
 * absent from the answer rather than present as a null, so a caller reading
 * this by id gets `undefined` for "no drawing" either way.
 */
export async function cardShapes(
  supabase: SupabaseClient,
  items: Pick<ItemSummary, "id" | "kind" | "mode">[],
): Promise<ReadonlyMap<string, CardShape>> {
  const drawable = items.filter(itemHasCardShape);
  if (drawable.length === 0) return new Map();

  const { data } = await supabase
    .from("item")
    .select(CARD_SHAPE_COLUMNS)
    .in(
      "id",
      drawable.map((item) => item.id),
    );

  const parts = new Map(
    ((data ?? []) as unknown as (CardShapeParts & { id: string })[]).map(
      (row) => [row.id, row],
    ),
  );

  const shapes = new Map<string, CardShape>();
  for (const item of drawable) {
    const part = parts.get(item.id);
    if (!part) continue;
    const shape = cardShape(item, part);
    if (shape) shapes.set(item.id, shape);
  }
  return shapes;
}
