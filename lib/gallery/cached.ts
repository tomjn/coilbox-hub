import { cacheLife, cacheTag } from "next/cache";
import { TAGS } from "@/lib/cache/tags";
import { createAnonClient } from "@/lib/supabase/anon";
import {
  applyFilters,
  fetchPage,
  type Filters,
  ITEM_SUMMARY_COLUMNS,
  type ItemSummary,
  PAGE_SIZE,
} from "./query";

/**
 * The gallery's reads, held between requests (issue TBD).
 *
 * Everything here is what the public may see, which is what makes it holdable:
 * the answer does not depend on who asked, so one visitor's copy serves the
 * next. A signed in reader's own withdrawn item is the exception and is read
 * outside these functions, with their own client, by the page that shows it.
 *
 * ## Why these build their own client
 *
 * A `"use cache"` function may not read the request, and a Supabase client made
 * from the session cookie is the request. `createAnonClient` has no cookie, so
 * row level security answers as `anon` and the answer is the same for everybody
 * by construction rather than by our promise. `lib/supabase/anon.ts` says more.
 *
 * ## Filters are arguments, not read here
 *
 * The page reads `searchParams` and hands the parsed filters in, because the
 * arguments are the cache key: one entry per set of filters, and a filter
 * nobody uses costs nothing until somebody uses it.
 */

/** How long a listing may be held before it is read again. Publishing calls
 *  `updateTag` and does not wait for this, so the hour is only the floor under
 *  a write nobody made, such as a row changed straight in the database. */
const LISTING_LIFE = "hours";

/** The newest few, for the landing page. */
export async function newestItems(): Promise<ItemSummary[]> {
  "use cache";
  cacheLife(LISTING_LIFE);
  cacheTag(TAGS.items);

  const { data } = await createAnonClient()
    .from("item")
    .select(ITEM_SUMMARY_COLUMNS)
    .order("created_at", { ascending: false })
    .limit(4);

  return (data ?? []) as unknown as ItemSummary[];
}

export interface GalleryPage {
  items: ItemSummary[];
  count: number;
  error: string | null;
  /** The game keys behind the chips, already narrowed and sorted. */
  games: string[];
  /** The map names behind the chips, the same. */
  maps: string[];
}

/**
 * One page of the gallery and the chips above it.
 *
 * The facets are here rather than in the page because they are read from the
 * same table at the same moment and are the same for everybody, so holding one
 * without the other would leave the page waiting on a round trip anyway.
 */
export async function galleryPage(filters: Filters): Promise<GalleryPage> {
  "use cache";
  cacheLife(LISTING_LIFE);
  cacheTag(TAGS.items);

  const supabase = createAnonClient();

  const query = applyFilters(
    supabase
      .from("item")
      .select(ITEM_SUMMARY_COLUMNS, { count: "exact" })
      .order("created_at", { ascending: false })
      .range((filters.page - 1) * PAGE_SIZE, filters.page * PAGE_SIZE - 1),
    filters,
  );

  const countQuery = async () => {
    const { count, error } = await applyFilters(
      supabase.from("item").select(ITEM_SUMMARY_COLUMNS, { count: "exact" }).range(0, 0),
      filters,
    );
    return { count, error };
  };

  // Filter options come from the rows themselves. At this size that is one small
  // query, and it is honest: an option only appears when something is behind it.
  // It will need a view or a materialised list long before it needs paging.
  // The game facet is built from game_key, not game_name: game_name can hold
  // a version-carrying archive name unique to one row (issue #50), and a
  // chip built from that would offer a filter that matches nothing else.
  // Asked for alongside the page rather than after it: neither read depends on
  // the other, and in series the page waited two round trips instead of one.
  const [{ data, count, error }, { data: facetRows }] = await Promise.all([
    fetchPage(() => query, countQuery),
    supabase.from("item").select("game_key,map_name").limit(1000),
  ]);

  return {
    items: data as unknown as ItemSummary[],
    count,
    error,
    games: distinct(facetRows?.map((row) => row.game_key)),
    maps: distinct(facetRows?.map((row) => row.map_name)),
  };
}

/** The values a facet offers: present, unique, sorted, and few enough to be a
 *  row of chips rather than a list. */
export function distinct(values: (string | null)[] | undefined): string[] {
  return [...new Set((values ?? []).filter((v): v is string => Boolean(v)))]
    .sort()
    .slice(0, 20);
}
