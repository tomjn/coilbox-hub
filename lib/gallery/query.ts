import { GALLERY_KINDS, type GalleryKind } from "@/lib/container";

/** How many fit on a page. Small enough that the first screen is the whole
 * story early on, large enough not to page constantly later. */
export const PAGE_SIZE = 24;

/** A break in the run of page numbers, which a pager draws as an ellipsis
 *  rather than as a link. */
export const PAGE_GAP = "gap";

/** One entry in a numbered pager: a page to link to, or the break between two
 *  stretches of them. */
export type PageStep = number | typeof PAGE_GAP;

/** How many pages either side of the current one are always offered. Two, so
 *  the run is five wide in the middle of a listing, which is enough to step
 *  back a page or jump a few without a scroll bar's worth of numbers. */
const AROUND = 2;

/**
 * The pages a numbered pager offers, which is not all of them.
 *
 * The first and the last are always there, because "back to the start" and "how
 * far does this go" are the two questions a reader has that a next link cannot
 * answer. Around the current page there is a short run, so stepping is one
 * click. Everything else collapses into a gap.
 *
 * A gap is only emitted where a page is genuinely missing, so a listing of six
 * pages shows all six rather than 1 ... 3 4 5 ... 6, which lists every page and
 * still looks like it is hiding some.
 */
export function pageNumbers(current: number, lastPage: number): PageStep[] {
  const last = Math.max(1, lastPage);
  const page = Math.min(last, Math.max(1, current));

  const wanted = new Set<number>([1, last]);
  for (let near = page - AROUND; near <= page + AROUND; near++) {
    if (near >= 1 && near <= last) wanted.add(near);
  }

  const steps: PageStep[] = [];
  let previous = 0;
  for (const number of [...wanted].sort((left, right) => left - right)) {
    if (previous && number - previous > 1) steps.push(PAGE_GAP);
    steps.push(number);
    previous = number;
  }

  return steps;
}

export interface FetchPageResult<T> {
  data: T[] | null;
  /** The true size of the table, not how many rows this page returned. */
  count: number | null;
  error: { message: string } | null;
}

/**
 * Follow a `.range()` query to the end rather than trusting one request to
 * return everything. `max_rows`, a project setting on the Data API that
 * differs locally versus in the cloud, can cap a single request below what
 * was asked for, silently. Comparing the rows collected so far against the
 * reported `count` survives that: a request that comes back short only ends
 * the loop once it has actually closed the gap to the total, however small
 * the cap on any one request turns out to be.
 */
export async function fetchAllPages<T>(
  fetchPage: (from: number, to: number) => Promise<FetchPageResult<T>>,
  pageSize: number,
): Promise<{ data: T[]; error: string | null }> {
  const all: T[] = [];
  let total: number | null = null;

  while (total === null || all.length < total) {
    const { data, count, error } = await fetchPage(
      all.length,
      all.length + pageSize - 1,
    );
    if (error) return { data: all, error: error.message };

    const rows = data ?? [];
    if (total === null) total = count ?? rows.length;
    if (rows.length === 0) break;

    all.push(...rows);
  }

  return { data: all, error: null };
}

/** A Postgrest error carries a stable `code` alongside its message. Only
 * `code` is read here, to tell "the offset ran off the end of the table"
 * apart from any other failure. */
export interface QueryError {
  message: string;
  code?: string;
}

/** What PostgREST answers a `.range()` whose offset sits past the last
 * matching row with. A 416, rather than the empty page every other "ran off
 * the end" query returns. */
const OFFSET_PAST_END = "PGRST103";

/**
 * Run a ranged, counted query and turn "the offset is past the last row"
 * into the empty page it should have been, rather than a fetch failure.
 * Paging one step past the end is routine. The last page a reader saw a
 * moment ago can be gone by the time they click "next" again, so it needs
 * to look like paging ran out, not like the gallery is unreadable.
 *
 * `runQuery` is the normal ranged request. Only when it fails with
 * `PGRST103` does `countQuery`, the same filters without a range, run to
 * learn the real total. Any other error is passed straight through.
 */
export async function fetchPage<T>(
  runQuery: () => PromiseLike<{
    data: T[] | null;
    count: number | null;
    error: QueryError | null;
  }>,
  countQuery: () => PromiseLike<{ count: number | null; error: QueryError | null }>,
): Promise<{ data: T[]; count: number; error: string | null }> {
  const { data, count, error } = await runQuery();
  if (!error) return { data: data ?? [], count: count ?? 0, error: null };
  if (error.code !== OFFSET_PAST_END) return { data: [], count: 0, error: error.message };

  const { count: total, error: countError } = await countQuery();
  if (countError) return { data: [], count: 0, error: countError.message };
  return { data: [], count: total ?? 0, error: null };
}

/** A row as a listing needs it. The container is deliberately absent: it is the
 * largest column by far and nothing on a card reads from it. */
export interface ItemSummary {
  id: string;
  kind: GalleryKind;
  /** Only challenges have one. Generated from the payload in the database. */
  mode: string | null;
  title: string;
  description: string;
  /** What a person reads: the stable shortname when there is one, else the
   * exact pinned build. See lib/gallery/publish.ts `describe()`. */
  game_name: string | null;
  /** What a listing groups and filters by. Narrower than `game_name`
   * (issue #50): only ever the shortname, so an item that names its game
   * only by its exact, version-carrying archive name has a `game_name` but
   * no `game_key`, and does not appear in the game facet. */
  game_key: string | null;
  map_name: string | null;
  tags: string[];
  author_name: string;
  created_at: string;
}

export const ITEM_SUMMARY_COLUMNS =
  "id,kind,mode,title,description,game_name,game_key,map_name,tags,author_name,created_at";

export interface Filters {
  kind: GalleryKind | null;
  game: string | null;
  map: string | null;
  tag: string | null;
  author: string | null;
  q: string | null;
  page: number;
}

function one(value: string | string[] | undefined): string | null {
  const first = Array.isArray(value) ? value[0] : value;
  const trimmed = first?.trim();
  return trimmed ? trimmed : null;
}

/**
 * Read filters out of the query string. Everything a listing does lives in the
 * URL so a filtered view can be linked to, which is how people recommend things
 * to each other. Anything unrecognised is dropped rather than passed to the
 * database.
 */
export function parseFilters(
  params: Record<string, string | string[] | undefined>,
): Filters {
  const kind = one(params.kind);
  const page = Number.parseInt(one(params.page) ?? "1", 10);

  return {
    kind:
      kind && (GALLERY_KINDS as readonly string[]).includes(kind)
        ? (kind as GalleryKind)
        : null,
    game: one(params.game),
    map: one(params.map),
    tag: one(params.tag)?.toLowerCase() ?? null,
    author: one(params.author),
    q: one(params.q),
    page: Number.isFinite(page) && page > 0 ? page : 1,
  };
}

/** The subset of a Postgrest query builder that filtering needs. Matches the
 * chain `supabase.from("item").select(...)` produces without pinning down its
 * full, heavily generic type. */
interface FilterableQuery<Query> {
  eq(column: string, value: string): Query;
  contains(column: string, value: readonly string[]): Query;
  textSearch(
    column: string,
    query: string,
    options?: { type?: "plain" | "phrase" | "websearch" },
  ): Query;
}

/**
 * Turn parsed filters into the same `.eq()`/`.contains()`/`.textSearch()` chain
 * everywhere a listing of items is built, so the gallery page and the API read
 * the database the same way rather than two hand written copies drifting apart.
 */
export function applyFilters<Query extends FilterableQuery<Query>>(
  query: Query,
  filters: Filters,
): Query {
  let next = query;
  if (filters.kind) next = next.eq("kind", filters.kind);
  // Filters on the grouping key, not the display name (issue #50): game_name
  // can hold a version-carrying archive name that no other row shares, and
  // matching on that would filter to a group of one rather than "no game".
  if (filters.game) next = next.eq("game_key", filters.game);
  if (filters.map) next = next.eq("map_name", filters.map);
  if (filters.tag) next = next.contains("tags", [filters.tag]);
  if (filters.author) next = next.eq("author_name", filters.author);
  // websearch_to_tsquery takes what a person would actually type, quotes and all,
  // and never throws on punctuation the way plainto_ or to_tsquery can.
  if (filters.q) next = next.textSearch("search", filters.q, { type: "websearch" });
  return next;
}

/** Build a query string that keeps the current filters and changes some of them.
 * Paging back to the first page on a filter change is deliberate: page 7 of a
 * different filter is almost never where you wanted to be. */
export function filterHref(
  current: Filters,
  change: Partial<Filters>,
): string {
  const next = { ...current, ...change };
  const params = new URLSearchParams();

  if (next.kind) params.set("kind", next.kind);
  if (next.game) params.set("game", next.game);
  if (next.map) params.set("map", next.map);
  if (next.tag) params.set("tag", next.tag);
  if (next.author) params.set("author", next.author);
  if (next.q) params.set("q", next.q);
  if (next.page > 1 && change.page !== undefined) {
    params.set("page", String(next.page));
  }

  const query = params.toString();
  return query ? `/gallery?${query}` : "/gallery";
}
