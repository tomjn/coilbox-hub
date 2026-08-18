import type { SupabaseClient } from "@supabase/supabase-js";
import { expect, test } from "bun:test";
import { PAGE_SIZE } from "@/lib/gallery/query";
import {
  applyFilters,
  applySort,
  filterHref,
  isFiltered,
  type MapSort,
  parseFilters,
  resolveAuthorKey,
} from "./query";

/**
 * A stand-in catalog and a stand-in query chain.
 *
 * The chain filters a real array rather than recording which methods were
 * called, because the questions #189 asks are about the answer: does a filter
 * narrow, do two of them compose, does page two repeat page one. A recorder can
 * only prove a call was made.
 *
 * What it cannot stand in for is the database's own rules. Full text matching
 * and the alias hop are proved against real rows in
 * `supabase/tests/map_browse.test.sql`, and the fakes below are only close
 * enough to exercise the code in this file.
 */

interface Row {
  id: string;
  map_name: string;
  slug: string;
  display_name: string | null;
  width_elmos: number;
  height_elmos: number;
  tags: string[];
  start_positions: number;
  author_keys: string[];
  author_names: string[];
  longer_edge_elmos: number;
  created_at: string;
  /** What the view builds a tsvector from: the two names, the description and
   *  the author names. Plain text here, matched on whole words. */
  search: string;
}

function row(over: Partial<Row> & { slug: string; map_name: string }): Row {
  return {
    id: over.slug,
    display_name: null,
    width_elmos: 4096,
    height_elmos: 4096,
    tags: [],
    start_positions: 0,
    author_keys: [],
    author_names: [],
    longer_edge_elmos: 4096,
    created_at: "2026-08-01T00:00:00Z",
    search: "",
    ...over,
  };
}

/** Four maps, enough that every filter has something to leave behind as well as
 *  something to drop. */
const CATALOG: Row[] = [
  row({
    slug: "comet",
    map_name: "Comet Catcher Remake 1.8",
    display_name: "Comet Catcher Remake",
    width_elmos: 6144,
    height_elmos: 10240,
    longer_edge_elmos: 10240,
    tags: ["medium", "water map"],
    start_positions: 8,
    author_keys: ["beherith"],
    author_names: ["Beherith"],
    created_at: "2026-08-04T00:00:00Z",
    search: "Comet Catcher Remake 1.8 a remake of an old favourite Beherith",
  }),
  row({
    slug: "quiet",
    map_name: "Quiet 1.0",
    tags: ["small"],
    created_at: "2026-08-03T00:00:00Z",
    search: "Quiet 1.0",
  }),
  row({
    slug: "charlie",
    map_name: "Charlie 1.0",
    // `small` is derived from the longer edge and `asymmetric` is what a
    // maintainer wrote. The view merges them into one array, which is the point.
    tags: ["asymmetric", "small"],
    start_positions: 2,
    author_keys: ["beherith"],
    author_names: ["Beherith"],
    created_at: "2026-08-02T00:00:00Z",
    search: "Charlie 1.0 Beherith",
  }),
  row({
    slug: "foxtrot",
    map_name: "Foxtrot 1.0",
    width_elmos: 12288,
    height_elmos: 4096,
    longer_edge_elmos: 12288,
    tags: ["large"],
    start_positions: 16,
    author_keys: ["zeta"],
    author_names: ["Zeta"],
    created_at: "2026-08-01T00:00:00Z",
    search: "Foxtrot 1.0 Zeta",
  }),
];

interface Sorting {
  column: keyof Row;
  ascending: boolean;
}

interface Fake {
  rows: Row[];
  orders: Sorting[];
  contains(column: string, value: readonly string[]): Fake;
  gte(column: string, value: number): Fake;
  textSearch(column: string, query: string, options?: { type?: string }): Fake;
  order(column: string, options?: { ascending?: boolean }): Fake;
  /** The slugs this query answers with, in order. */
  slugs(): string[];
  /** One page of them, the way `.range()` is called. */
  page(from: number, to: number): string[];
}

/** Numbers by size and everything else as text, so a 10240 elmo edge does not
 *  sort below a 4096 one the way a string comparison would put it. */
function compare(left: number | string, right: number | string): number {
  if (typeof left === "number" && typeof right === "number") return left - right;
  const a = String(left);
  const b = String(right);
  return a < b ? -1 : a > b ? 1 : 0;
}

function table(rows: Row[], orders: Sorting[] = []): Fake {
  const sorted = () =>
    [...rows].sort((a, b) => {
      for (const { column, ascending } of orders) {
        const left = a[column];
        const right = b[column];
        const order = compare(
          typeof left === "number" ? left : String(left ?? ""),
          typeof right === "number" ? right : String(right ?? ""),
        );
        if (order !== 0) return ascending ? order : -order;
      }
      return 0;
    });

  return {
    rows,
    orders,
    contains(column, value) {
      const held = (held: Row) => held[column as keyof Row] as string[];
      return table(
        rows.filter((r) => value.every((wanted) => held(r).includes(wanted))),
        orders,
      );
    },
    gte(column, value) {
      return table(
        rows.filter((r) => (r[column as keyof Row] as number) >= value),
        orders,
      );
    },
    textSearch(column, query) {
      const words = query.toLowerCase().split(/\s+/).filter(Boolean);
      return table(
        rows.filter((r) => {
          const text = String(r[column as keyof Row]).toLowerCase().split(/\W+/);
          return words.every((word) => text.includes(word));
        }),
        orders,
      );
    },
    order(column, options) {
      return table(rows, [
        ...orders,
        { column: column as keyof Row, ascending: options?.ascending ?? true },
      ]);
    },
    slugs() {
      return sorted().map((r) => r.slug);
    },
    page(from, to) {
      return sorted()
        .slice(from, to + 1)
        .map((r) => r.slug);
    },
  };
}

/** What the two database functions answer, without being them. `author_key`
 *  folds the case and strips one clan tag, and `resolved_author_key` applies a
 *  merge a maintainer recorded. `supabase/tests/author_keys.test.sql` is where
 *  the real rules are proved. */
function fakeSupabase(aliases: Record<string, string> = {}) {
  const calls: { fn: string; args: Record<string, unknown> }[] = [];

  const supabase = {
    rpc(fn: string, args: Record<string, unknown>) {
      calls.push({ fn, args });
      if (fn === "author_key") {
        const folded = String(args.credit)
          .toLowerCase()
          .replace(/^\s*\[[^\]]*\]/, "")
          .trim();
        return Promise.resolve({ data: folded, error: null });
      }
      const key = String(args.credit_key);
      return Promise.resolve({ data: aliases[key] ?? key, error: null });
    },
  };

  return { supabase: supabase as unknown as SupabaseClient, calls };
}

/** The filters a request arrives with, with the author already resolved. */
async function narrow(
  params: Record<string, string | string[] | undefined>,
  aliases: Record<string, string> = {},
): Promise<string[]> {
  const filters = parseFilters(params);
  const { supabase } = fakeSupabase(aliases);
  const authorKey = await resolveAuthorKey(supabase, filters.author);

  return applySort(applyFilters(table(CATALOG), filters, authorKey), filters.sort).slugs();
}

test("filters come out of the query string", () => {
  const filters = parseFilters({
    q: "comet",
    tag: "Water Map",
    author: "[BAR]Beherith",
    size: "medium",
    players: "8",
    sort: "players",
    page: "3",
  });

  expect(filters.q).toBe("comet");
  expect(filters.tag).toBe("water map");
  expect(filters.author).toBe("[BAR]Beherith");
  expect(filters.size).toBe("medium");
  expect(filters.players).toBe(8);
  expect(filters.sort).toBe("players");
  expect(filters.page).toBe(3);
});

test("a size nobody has heard of is dropped rather than passed to the database", () => {
  expect(parseFilters({ size: "enormous" }).size).toBeNull();
  expect(parseFilters({ size: "'; drop table map; --" }).size).toBeNull();
});

test("a sort nobody has heard of falls back to the one a bare listing uses", () => {
  expect(parseFilters({ sort: "cheapest" }).sort).toBe("name");
  expect(parseFilters({}).sort).toBe("name");
});

test("a nonsense page or player count falls back to no filter at all", () => {
  expect(parseFilters({ page: "0" }).page).toBe(1);
  expect(parseFilters({ page: "banana" }).page).toBe(1);
  expect(parseFilters({ players: "0" }).players).toBeNull();
  expect(parseFilters({ players: "-4" }).players).toBeNull();
  expect(parseFilters({ players: "lots" }).players).toBeNull();
});

test("empty values are treated as absent", () => {
  const filters = parseFilters({ q: "   ", tag: "", author: "  " });
  expect(filters.q).toBeNull();
  expect(filters.tag).toBeNull();
  expect(filters.author).toBeNull();
});

test("a repeated parameter takes the first, not an array", () => {
  expect(parseFilters({ author: ["One", "Two"] }).author).toBe("One");
});

// ## Each filter narrows, and two of them compose

test("no filters at all is the whole catalog, by name", async () => {
  expect(await narrow({})).toEqual(["charlie", "comet", "foxtrot", "quiet"]);
});

test("a tag filter narrows to the maps that carry it", async () => {
  expect(await narrow({ tag: "water map" })).toEqual(["comet"]);
});

test("a size filter narrows, because a size band is a tag", async () => {
  expect(await narrow({ size: "small" })).toEqual(["charlie", "quiet"]);
});

test("an author filter narrows to that person's maps", async () => {
  expect(await narrow({ author: "Beherith" })).toEqual(["charlie", "comet"]);
});

test("a player count filter is a minimum, so a bigger map still counts", async () => {
  expect(await narrow({ players: "8" })).toEqual(["comet", "foxtrot"]);
});

test("a free search narrows to the maps the words are on", async () => {
  expect(await narrow({ q: "remake" })).toEqual(["comet"]);
});

test("two filters compose rather than replacing each other", async () => {
  expect(await narrow({ author: "Beherith", size: "small" })).toEqual(["charlie"]);
  expect(await narrow({ players: "2", tag: "asymmetric" })).toEqual(["charlie"]);
});

// ## The author filter goes through the database's own rules

test("an author filter matches through an alias", async () => {
  // `Behe` was filed under its own key until a maintainer merged it into
  // `beherith`, and the stored keys are not rewritten by a merge. So a listing
  // that trusted the column would answer nothing here.
  expect(await narrow({ author: "Behe" }, { behe: "beherith" })).toEqual([
    "charlie",
    "comet",
  ]);
});

test("and through a spelling the archive never used", async () => {
  expect(await narrow({ author: "[BAR]Beherith" })).toEqual(["charlie", "comet"]);
});

test("the key filtered on is the database's answer, never a rule in this file", async () => {
  // Whatever the two functions answer is what the query matches on. A
  // `toLowerCase()` here would quietly become a second copy of a rule that lives
  // in 20260818110000_author_keys.sql.
  const { supabase, calls } = fakeSupabase({ beherith: "somebody else" });
  const key = await resolveAuthorKey(supabase, "[BAR]Beherith");

  expect(calls[0]).toEqual({ fn: "author_key", args: { credit: "[BAR]Beherith" } });
  expect(calls[1]).toEqual({ fn: "resolved_author_key", args: { credit_key: "beherith" } });
  expect(key).toBe("somebody else");
});

test("no author filter asks the database nothing", async () => {
  const { supabase, calls } = fakeSupabase();

  expect(await resolveAuthorKey(supabase, null)).toBeNull();
  expect(calls).toEqual([]);
});

test("a credit that is nothing but a clan tag finds no maps rather than every map", async () => {
  expect(await narrow({ author: "[BAR]" })).toEqual([]);
});

// ## A curated tag and a derived tag are the same filter

test("a tag filter matches a curated tag and a derived tag alike", async () => {
  // `small` is worked out from the longer edge and `asymmetric` is what a
  // maintainer wrote. Both are in one merged array, so a reader browsing by tag
  // never has to know which half a tag came from.
  expect(await narrow({ tag: "asymmetric" })).toEqual(["charlie"]);
  expect(await narrow({ tag: "small" })).toEqual(["charlie", "quiet"]);
});

// ## Anything unrecognised

test("an unknown query parameter neither narrows nor widens the result", async () => {
  const everything = await narrow({});

  expect(await narrow({ mode: "wild" })).toEqual(everything);
  expect(await narrow({ author_keys: "zeta" })).toEqual(everything);
  expect(await narrow({ tags: "cs.{large}" })).toEqual(everything);
});

test("an unknown parameter is dropped rather than carried into a link", () => {
  const filters = parseFilters({ tag: "small", mode: "wild" });

  expect(filterHref(filters, {})).toBe("/maps?tag=small");
});

// ## Sorting and paging

test("every sort names a column the listing view carries", () => {
  const columns = (["name", "size", "players", "added"] as MapSort[]).map(
    (sort) => applySort(table([]), sort).orders,
  );

  expect(columns).toEqual([
    [{ column: "map_name", ascending: true }],
    [
      { column: "longer_edge_elmos", ascending: false },
      { column: "map_name", ascending: true },
    ],
    [
      { column: "start_positions", ascending: false },
      { column: "map_name", ascending: true },
    ],
    [
      { column: "created_at", ascending: false },
      { column: "map_name", ascending: true },
    ],
  ]);
});

test("sorting by size puts the map that plays largest first", async () => {
  expect(await narrow({ sort: "size" })).toEqual(["foxtrot", "comet", "charlie", "quiet"]);
});

test("sorting by when it arrived is newest first", async () => {
  expect(await narrow({ sort: "added" })).toEqual(["comet", "quiet", "charlie", "foxtrot"]);
});

test("page two of a two page result does not repeat page one", () => {
  // Every map has the same player count, so the sort the reader asked for
  // settles nothing and the tie break decides the whole order. Without one the
  // database is free to break the tie differently per request, which puts one
  // map on both pages and another on neither.
  const many = Array.from({ length: PAGE_SIZE + 6 }, (_, i) =>
    row({
      slug: `map-${i}`,
      map_name: `Map ${String((PAGE_SIZE + 6 - i) * 3).padStart(3, "0")} 1.0`,
      start_positions: 8,
    }),
  );
  const query = applySort(table(many), "players");

  const first = query.page(0, PAGE_SIZE - 1);
  const second = query.page(PAGE_SIZE, PAGE_SIZE * 2 - 1);

  expect(first).toHaveLength(PAGE_SIZE);
  expect(second).toHaveLength(6);
  expect(first.filter((slug) => second.includes(slug))).toEqual([]);
  expect(new Set([...first, ...second]).size).toBe(many.length);
});

// ## Links

test("changing a filter keeps the others and drops the page", () => {
  const current = parseFilters({ size: "small", author: "beherith", page: "5" });

  expect(filterHref(current, { tag: "asymmetric" })).toBe(
    "/maps?tag=asymmetric&author=beherith&size=small",
  );
});

test("paging keeps every filter", () => {
  const current = parseFilters({ q: "comet", players: "8", sort: "added" });

  expect(filterHref(current, { page: 2 })).toBe("/maps?q=comet&players=8&sort=added&page=2");
});

test("the ordinary listing is a bare path", () => {
  const current = parseFilters({ tag: "small", sort: "name" });

  expect(filterHref(current, { tag: null })).toBe("/maps");
});

test("a link keeps the author's name as the reader spelled it", () => {
  // The URL is what somebody pastes into chat. The key it resolves to is the
  // database's business and changes when a maintainer records a merge.
  expect(filterHref(parseFilters({ author: "[BAR]Beherith" }), {})).toBe(
    "/maps?author=%5BBAR%5DBeherith",
  );
});

test("a sort is a narrowing of nothing, and neither is a page", () => {
  expect(isFiltered(parseFilters({ sort: "added", page: "3" }))).toBe(false);
  expect(isFiltered(parseFilters({ players: "2" }))).toBe(true);
  expect(isFiltered(parseFilters({ q: "comet" }))).toBe(true);
});
