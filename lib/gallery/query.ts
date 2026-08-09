import { GALLERY_KINDS, type GalleryKind } from "@/lib/container";

/** How many fit on a page. Small enough that the first screen is the whole
 * story early on, large enough not to page constantly later. */
export const PAGE_SIZE = 24;

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

/** A row as a listing needs it. The container is deliberately absent: it is the
 * largest column by far and nothing on a card reads from it. */
export interface ItemSummary {
  id: string;
  kind: GalleryKind;
  /** Only challenges have one. Generated from the payload in the database. */
  mode: string | null;
  title: string;
  description: string;
  game_name: string | null;
  map_name: string | null;
  tags: string[];
  author_name: string;
  created_at: string;
}

export const ITEM_SUMMARY_COLUMNS =
  "id,kind,mode,title,description,game_name,map_name,tags,author_name,created_at";

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
