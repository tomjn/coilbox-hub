import type { SupabaseClient } from "@supabase/supabase-js";
import { MAP_MINIMAP_VARIANT } from "@/lib/assets/asset";
import { fetchHeldAssets, type ResolvedAsset, resolveAsset } from "@/lib/assets/resolve";
import { mapSquares } from "./labels";

/**
 * How a reader narrows the catalog down to the map they want (issue #189).
 *
 * `lib/gallery/query.ts` for maps, and deliberately the same shape: filters come
 * out of the query string, turn into one query chain, and turn back into a link
 * that keeps them. `/maps?author=beherith` is what somebody pastes into lobby
 * chat, so every filter is in the URL and nothing about the listing lives in a
 * component's state.
 *
 * The paging is that file's own. {@link PAGE_SIZE}, `fetchPage` and
 * `fetchAllPages` are imported rather than restated, because a second page size
 * would put the gallery and the catalog a page apart for no reason anybody could
 * name, and the offset-past-the-end handling is the same problem here as there.
 *
 * ## Every filter is a column on public.map_browse
 *
 * That view is where a measurement becomes something a reader browses by, and
 * `20260818160000_map_browse.sql` states the rule it follows: anything a listing
 * filters or sorts on gets a column of its own, and only that. So nothing here
 * computes, and there is no second copy of a threshold to drift.
 *
 * It is a view of its own rather than more columns on `public.map_listing`,
 * which the lookup route joins on every request. The migration argues that
 * split. What matters here is that nothing but this page reads `map_browse`.
 *
 * `size` is the one that looks missing. `small`, `medium` and `large` are
 * derived tags, so a size filter is the same array match a tag filter is, and
 * the migration says why a column repeating the band would be a second answer to
 * a settled question.
 *
 * ## An unknown parameter never becomes a filter
 *
 * {@link parseFilters} reads the parameters it knows and drops everything else,
 * which is what the gallery does. A parameter nobody recognises therefore
 * reaches no query and changes no result, rather than being refused with a
 * status code that would turn a stale bookmark or a tracking parameter somebody
 * else appended into an error page.
 */

/** A row as a card needs it. Narrow on purpose: `search` is a tsvector nothing
 *  renders, and `created_at` and `longer_edge_elmos` are sorted on rather than
 *  shown, and a column PostgREST orders by does not have to be selected. */
export interface MapSummary {
  id: string;
  /** The canonical name, which is identity and what a picture is keyed on. */
  map_name: string;
  slug: string;
  display_name: string | null;
  width_elmos: number;
  height_elmos: number;
  tags: string[];
  start_positions: number;
  /** Resolved keys, so a link built from one finds every map that person made
   *  including the ones they signed differently. */
  author_keys: string[];
  /** In step with {@link author_keys}: the name at position two belongs to the
   *  key at position two. */
  author_names: string[];
}

export const MAP_SUMMARY_COLUMNS =
  "id,map_name,slug,display_name,width_elmos,height_elmos,tags,start_positions,author_keys,author_names";

/** The three bands `20260818120000_map_listing.sql` cuts the catalog into, which
 *  are tags rather than a column, and the only values a size filter may take. */
export const MAP_SIZES = ["small", "medium", "large"] as const;

export type MapSize = (typeof MAP_SIZES)[number];

/** The four orders #189 asks for, in the order the page offers them. */
export const MAP_SORTS = ["name", "size", "players", "added"] as const;

export type MapSort = (typeof MAP_SORTS)[number];

/**
 * Alphabetical, rather than the gallery's newest first.
 *
 * The gallery is a feed: things are published one at a time and the newest is
 * the one nobody has seen. The catalog is a reference list of thousands of maps
 * that mostly arrive in one seeding pass, so "recently added" would put nearly
 * all of them in an order that means nothing, and a reader looking for a map by
 * name would have to search for something they can already see.
 */
const DEFAULT_SORT: MapSort = "name";

export interface Filters {
  /** Free text over name, description and author. */
  q: string | null;
  tag: string | null;
  /** As the reader spelled it, which is not a key. See {@link resolveAuthorKey}. */
  author: string | null;
  size: MapSize | null;
  /** A minimum, so `players=8` includes a twelve player map. */
  players: number | null;
  sort: MapSort;
  page: number;
}

/** The first value, trimmed, with an empty one treated as absent. The same
 *  reading `lib/gallery/query.ts` takes of a repeated or blank parameter. */
function one(value: string | string[] | undefined): string | null {
  const first = Array.isArray(value) ? value[0] : value;
  const trimmed = first?.trim();
  return trimmed ? trimmed : null;
}

/** A whole number of at least one, or null. Used for both the minimum player
 *  count and the page, which fail the same two ways: a word, and a number below
 *  where the scale starts. */
function counted(value: string | null): number | null {
  const parsed = Number.parseInt(value ?? "", 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
}

/**
 * Read filters out of the query string.
 *
 * Everything the listing does lives in the URL, so a filtered view can be linked
 * to, which is how people recommend maps to each other. A value this cannot make
 * sense of becomes null and the filter is simply not applied: a size nobody has
 * heard of narrows to nothing useful and a sort nobody has heard of has no
 * column, and refusing either would break a link somebody had already shared.
 */
export function parseFilters(
  params: Record<string, string | string[] | undefined>,
): Filters {
  const size = one(params.size);
  const sort = one(params.sort);

  return {
    q: one(params.q),
    // Lower cased, the same as the gallery's, because the derived tags are lower
    // case and a chip a reader clicked carries whatever case it was written in.
    tag: one(params.tag)?.toLowerCase() ?? null,
    author: one(params.author),
    size: size && (MAP_SIZES as readonly string[]).includes(size) ? (size as MapSize) : null,
    players: counted(one(params.players)),
    sort: sort && (MAP_SORTS as readonly string[]).includes(sort) ? (sort as MapSort) : DEFAULT_SORT,
    page: counted(one(params.page)) ?? 1,
  };
}

/**
 * The key an author filter matches on, as the database works it out.
 *
 * Two calls, both of which `anon` may execute. `public.author_key` folds a
 * credit the way ingest folded it, so `[BAR]Beherith`, `Beherith` and `beherith`
 * arrive at one key. `public.resolved_author_key` then applies whatever merge a
 * maintainer has recorded, so a listing follows an alias the moment it exists.
 *
 * In the database and never here. `20260818110000_author_keys.sql` argues at
 * length that one copy of the rule is the point, and names the failure two
 * copies cause: a listing showing nothing while the credits sit in the table,
 * correctly keyed, under a key the reader spelled a hair differently. A
 * `toLowerCase()` in this file would be that second copy on the day somebody
 * changed how a clan tag is stripped.
 *
 * An empty string is an answer rather than a failure. A credit that is nothing
 * but a clan tag folds to nothing, no stored key can be blank, and matching on
 * it finds no maps, which is the truthful reply to "everything by [BAR]".
 */
export async function resolveAuthorKey(
  supabase: SupabaseClient,
  author: string | null,
): Promise<string | null> {
  if (author === null) return null;

  const { data: folded } = await supabase.rpc("author_key", { credit: author });
  const key = typeof folded === "string" ? folded : "";

  const { data: resolved } = await supabase.rpc("resolved_author_key", { credit_key: key });
  return typeof resolved === "string" ? resolved : key;
}

/** The subset of a Postgrest query builder that filtering needs. Matches the
 *  chain `supabase.from("map_browse").select(...)` produces without pinning
 *  down its full, heavily generic type. */
interface FilterableQuery<Query> {
  contains(column: string, value: readonly string[]): Query;
  gte(column: string, value: number): Query;
  textSearch(
    column: string,
    query: string,
    options?: { type?: "plain" | "phrase" | "websearch" },
  ): Query;
}

/**
 * Turn parsed filters into one query chain, so the page and anything else
 * listing maps read the catalog the same way.
 *
 * `authorKey` is the database's answer from {@link resolveAuthorKey} and is
 * separate from `filters.author` on purpose: the URL keeps the spelling the
 * reader used, and the query matches the key the hub files that person under.
 */
export function applyFilters<Query extends FilterableQuery<Query>>(
  query: Query,
  filters: Filters,
  authorKey: string | null,
): Query {
  let next = query;
  // The same array, twice over. A size band is a derived tag, so `size=small`
  // and `tag=small` are one question asked two ways.
  if (filters.tag) next = next.contains("tags", [filters.tag]);
  if (filters.size) next = next.contains("tags", [filters.size]);
  if (authorKey !== null) next = next.contains("author_keys", [authorKey]);
  // A minimum rather than an equality. Somebody who wants an eight player map
  // will take a twelve player map, and a lobby decides the teams anyway.
  if (filters.players !== null) next = next.gte("start_positions", filters.players);
  // websearch_to_tsquery takes what a person would actually type, quotes and
  // all, and never throws on punctuation the way plainto_ or to_tsquery can.
  if (filters.q) next = next.textSearch("search", filters.q, { type: "websearch" });
  return next;
}

/** The subset of the query builder that ordering needs. */
interface SortableQuery<Query> {
  order(column: string, options?: { ascending?: boolean }): Query;
}

/** Which column each order reads, and which way. Written down once, because a
 *  sort naming a column the view does not have fails as an unreadable listing
 *  rather than as a wrong order. */
const SORT_COLUMNS: Record<MapSort, { column: string; ascending: boolean }> = {
  name: { column: "map_name", ascending: true },
  size: { column: "longer_edge_elmos", ascending: false },
  players: { column: "start_positions", ascending: false },
  added: { column: "created_at", ascending: false },
};

/**
 * Order a listing, with the name as the tie break.
 *
 * The tie break is not decoration. Three of the four orders have thousands of
 * rows sharing a value, and a paged query whose order is not total can return
 * one map on both pages and another on neither, silently, because the database
 * is free to break the tie differently on each request. `map_name` is unique, so
 * adding it makes every order total.
 */
export function applySort<Query extends SortableQuery<Query>>(
  query: Query,
  sort: MapSort,
): Query {
  const { column, ascending } = SORT_COLUMNS[sort];
  const ordered = query.order(column, { ascending });
  return column === "map_name" ? ordered : ordered.order("map_name", { ascending: true });
}

/**
 * Build a query string that keeps the current filters and changes some of them.
 *
 * Paging back to the first page on a filter change is deliberate: page 7 of a
 * different filter is almost never where you wanted to be. The same reading
 * `lib/gallery/query.ts` takes, and the same mechanism.
 */
export function filterHref(current: Filters, change: Partial<Filters>): string {
  const next = { ...current, ...change };
  const params = new URLSearchParams();

  if (next.q) params.set("q", next.q);
  if (next.tag) params.set("tag", next.tag);
  if (next.author) params.set("author", next.author);
  if (next.size) params.set("size", next.size);
  if (next.players !== null) params.set("players", String(next.players));
  // Left out when it is the one a bare /maps already gives, so the ordinary link
  // is the short one.
  if (next.sort !== DEFAULT_SORT) params.set("sort", next.sort);
  if (next.page > 1 && change.page !== undefined) params.set("page", String(next.page));

  const query = params.toString();
  return query ? `/maps?${query}` : "/maps";
}

/** Whether the reader has narrowed the catalog at all, which is the difference
 *  between "nothing matches that" and "the catalog is empty". The sort is not a
 *  narrowing and the page is not either. */
export function isFiltered(filters: Filters): boolean {
  return Boolean(
    filters.q || filters.tag || filters.author || filters.size || filters.players !== null,
  );
}

/**
 * The minimap for every map on the page, in one lookup.
 *
 * One batched query rather than one per card, which is the split
 * `lib/gallery/itemPictures.ts` already makes: `fetchHeldAssets` asks what the
 * hub holds and `resolveAsset` decides what to draw.
 *
 * Every map gets an entry, because the last rung of the ladder cannot fail. A
 * map with nothing stored resolves to the drawing, at the catalog's own
 * proportions, so a 12 x 20 map with no picture is drawn as a 12 x 20 rather
 * than as a square.
 *
 * Wants a session or anonymous client, so row level security is underneath the
 * answer as well as the two checks the resolver makes itself.
 */
export async function mapPictures(
  supabase: SupabaseClient,
  maps: MapSummary[],
): Promise<ReadonlyMap<string, ResolvedAsset>> {
  const identities = maps.map((map) => ({
    keyedOn: "map" as const,
    mapName: map.map_name,
    variant: MAP_MINIMAP_VARIANT,
  }));

  const held = await fetchHeldAssets(supabase, identities);
  const pictures = new Map<string, ResolvedAsset>();

  for (const [index, identity] of identities.entries()) {
    const map = maps[index];
    pictures.set(
      map.map_name,
      resolveAsset(identity, held, mapSquares(map.width_elmos, map.height_elmos)),
    );
  }

  return pictures;
}
