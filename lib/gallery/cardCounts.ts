import type { SupabaseClient } from "@supabase/supabase-js";
import {
  editCountsFromParsed,
  type ModProjectCounts,
  parseProjectEdits,
} from "./modProjectPreview";
import type { ItemSummary } from "./query";

/**
 * The counts every mod-project card on a page of the gallery needs, in one
 * lookup, the same split `lib/gallery/cardShapes.ts` makes for a challenge's
 * galaxy or a blueprint's layout (issue #307) and `cardPictures.ts` makes for
 * a map picture.
 *
 * A listing row carries no container (`ITEM_SUMMARY_COLUMNS` in `query.ts`),
 * so a card has no way to reach a project's edits on its own. This asks
 * PostgREST for only the five store paths `modProjectPreview.ts` reads -
 * `overrides`, `clones`, `menus`, `text` and `disabled` under
 * `container->payload->edits` - rather than the whole container, which would
 * also carry `readOnlyLua` and anything a future kind version adds. A row of
 * any other kind has none of those paths and PostgREST answers null for it,
 * so one query serves a page mixing every kind and a preset or a blueprint on
 * it pays a handful of bytes of nulls rather than its own container.
 *
 * The clone count is the exception to the paths above. A clone's whole unit
 * table is large and only the number of clones is read, so the database counts
 * them: `public.clone_count(item)` (migration `20261017150000`) is a computed
 * column, and PostgREST serves it by name in the select. A function rather
 * than a generated column, because a stored one would rewrite every row of
 * `public.item` and a generated expression cannot hold the subquery that does
 * the counting (issue #415).
 */

/** A page's worth of counts, keyed on the item id, as it crosses a `"use
 *  cache"` boundary. `lib/maps/cached.ts` sets out why a `Map` cannot cross
 *  that boundary directly and has to travel as entries instead. */
export type CardCountEntries = [string, ModProjectCounts][];

export function cardCountsFromEntries(
  entries: CardCountEntries,
): ReadonlyMap<string, ModProjectCounts> {
  return new Map(entries);
}

const CARD_COUNT_COLUMNS =
  "id," +
  "overrides:container->payload->edits->overrides," +
  "clone_count," +
  "menus:container->payload->edits->menus," +
  "text:container->payload->edits->text," +
  "disabled:container->payload->edits->disabled";

/** One row as PostgREST hands the JSON paths back. */
interface CardCountRow {
  id: string;
  overrides: unknown;
  menus: unknown;
  text: unknown;
  disabled: unknown;
  clone_count: number | null;
}

/** The counts for every mod-project item on the page. `supabase` is the same
 *  anonymous or session client the listing itself was read with, so row
 *  level security answers the same way twice and a withdrawn row cannot
 *  arrive here after being filtered out of the listing. */
export async function cardCounts(
  supabase: SupabaseClient,
  items: Pick<ItemSummary, "id" | "kind">[],
): Promise<ReadonlyMap<string, ModProjectCounts>> {
  const projects = items.filter((item) => item.kind === "mod-project");
  if (projects.length === 0) return new Map();

  const { data } = await supabase
    .from("item")
    .select(CARD_COUNT_COLUMNS)
    .in(
      "id",
      projects.map((item) => item.id),
    );

  const counts = new Map<string, ModProjectCounts>();
  for (const row of (data ?? []) as unknown as CardCountRow[]) {
    counts.set(row.id, {
      ...editCountsFromParsed(parseProjectEdits(row)),
      clones: row.clone_count ?? 0,
    });
  }
  return counts;
}
