import { expect, test } from "bun:test";
import {
  applyFilters,
  fetchAllPages,
  fetchPage,
  filterHref,
  PAGE_GAP,
  pageNumbers,
  parseFilters,
} from "./query";

test("filters come out of the query string", () => {
  const filters = parseFilters({
    kind: "preset",
    game: "Beyond All Reason",
    tag: "ECO",
    page: "3",
  });

  expect(filters.kind).toBe("preset");
  expect(filters.game).toBe("Beyond All Reason");
  expect(filters.tag).toBe("eco");
  expect(filters.page).toBe(3);
});

test("an unrecognised kind is dropped rather than passed to the database", () => {
  expect(parseFilters({ kind: "campaign" }).kind).toBeNull();
  expect(parseFilters({ kind: "'; drop table item; --" }).kind).toBeNull();
});

test("a nonsense page falls back to the first", () => {
  expect(parseFilters({ page: "0" }).page).toBe(1);
  expect(parseFilters({ page: "-4" }).page).toBe(1);
  expect(parseFilters({ page: "banana" }).page).toBe(1);
});

test("empty values are treated as absent", () => {
  const filters = parseFilters({ game: "   ", map: "" });
  expect(filters.game).toBeNull();
  expect(filters.map).toBeNull();
});

test("a repeated parameter takes the first, not an array", () => {
  expect(parseFilters({ game: ["One", "Two"] }).game).toBe("One");
});

/** A fake query builder that just records which column each filter matched
 * against, standing in for the Supabase query chain. */
function recordingQuery() {
  const calls: { method: string; column: string; value: unknown }[] = [];
  const query = {
    eq(column: string, value: string) {
      calls.push({ method: "eq", column, value });
      return query;
    },
    contains(column: string, value: readonly string[]) {
      calls.push({ method: "contains", column, value });
      return query;
    },
    textSearch(column: string, value: string) {
      calls.push({ method: "textSearch", column, value });
      return query;
    },
  };
  return { query, calls };
}

test("the game filter matches game_key, not game_name (issue #50)", () => {
  // game_name can hold a version-carrying archive name unique to one row, so
  // filtering on it would match a group of one rather than "no game".
  const { query, calls } = recordingQuery();
  applyFilters(query, parseFilters({ game: "BA" }));

  expect(calls).toContainEqual({ method: "eq", column: "game_key", value: "BA" });
  expect(calls.some((c) => c.column === "game_name")).toBe(false);
});

test("changing a filter keeps the others and drops the page", () => {
  const current = parseFilters({ kind: "preset", game: "BAR", page: "5" });

  expect(filterHref(current, { map: "Comet" })).toBe(
    "/gallery?kind=preset&game=BAR&map=Comet",
  );
});

test("paging keeps every filter", () => {
  const current = parseFilters({ kind: "scenario", tag: "hard" });

  expect(filterHref(current, { page: 2 })).toBe(
    "/gallery?kind=scenario&tag=hard&page=2",
  );
});

test("clearing everything is a bare path", () => {
  const current = parseFilters({ kind: "preset", game: "BAR" });

  expect(filterHref(current, { kind: null, game: null })).toBe("/gallery");
});

/** A fake `.range()` call over an in-memory table, standing in for the
 * Supabase client so the paging logic can be exercised without a database. */
function tablePage(rows: number[], from: number, to: number) {
  return {
    data: rows.slice(from, to + 1),
    count: rows.length,
    error: null,
  };
}

test("a result set smaller than one page comes back whole", async () => {
  const rows = [1, 2, 3];
  const { data, error } = await fetchAllPages(
    (from, to) => Promise.resolve(tablePage(rows, from, to)),
    24,
  );

  expect(error).toBeNull();
  expect(data).toEqual(rows);
});

test("a result set larger than one page comes back whole", async () => {
  const rows = Array.from({ length: 45 }, (_, i) => i);
  const { data, error } = await fetchAllPages(
    (from, to) => Promise.resolve(tablePage(rows, from, to)),
    24,
  );

  expect(error).toBeNull();
  expect(data).toEqual(rows);
});

test("a cap that shrinks a page below what was asked for still ends up with everything", async () => {
  // A stand-in for max_rows: no matter how large a range is requested, this
  // server hands back at most 10 rows per call. Asking for a page of 24 and
  // getting fewer back used to be read as "that was the last page".
  const rows = Array.from({ length: 33 }, (_, i) => i);
  const CAP = 10;
  const { data, error } = await fetchAllPages(
    (from, to) =>
      Promise.resolve(tablePage(rows, from, Math.min(to, from + CAP - 1))),
    24,
  );

  expect(error).toBeNull();
  expect(data).toEqual(rows);
});

test("an empty table comes back as an empty array, not an extra request", async () => {
  let calls = 0;
  const { data, error } = await fetchAllPages((from, to) => {
    calls += 1;
    return Promise.resolve(tablePage([], from, to));
  }, 24);

  expect(error).toBeNull();
  expect(data).toEqual([]);
  expect(calls).toBe(1);
});

test("an error partway through is surfaced rather than swallowed", async () => {
  const rows = Array.from({ length: 45 }, (_, i) => i);
  let calls = 0;

  const { error } = await fetchAllPages((from, to) => {
    calls += 1;
    if (calls === 2) {
      return Promise.resolve({ data: null, count: null, error: { message: "boom" } });
    }
    return Promise.resolve(tablePage(rows, from, to));
  }, 24);

  expect(error).toBe("boom");
});

test("a page inside the table comes back whole, no count query needed", async () => {
  let countCalls = 0;
  const { data, count, error } = await fetchPage(
    () => Promise.resolve({ data: [1, 2, 3], count: 3, error: null }),
    () => {
      countCalls += 1;
      return Promise.resolve({ count: 3, error: null });
    },
  );

  expect(error).toBeNull();
  expect(data).toEqual([1, 2, 3]);
  expect(count).toBe(3);
  expect(countCalls).toBe(0);
});

test("an offset past the last row comes back as an empty page against the real total, not an error", async () => {
  const { data, count, error } = await fetchPage(
    () =>
      Promise.resolve({
        data: null,
        count: null,
        error: { message: "Requested range not satisfiable", code: "PGRST103" },
      }),
    () => Promise.resolve({ count: 4, error: null }),
  );

  expect(error).toBeNull();
  expect(data).toEqual([]);
  expect(count).toBe(4);
});

test("a failed follow-up count still surfaces an error rather than a false empty page", async () => {
  const { data, error } = await fetchPage(
    () =>
      Promise.resolve({
        data: null,
        count: null,
        error: { message: "Requested range not satisfiable", code: "PGRST103" },
      }),
    () => Promise.resolve({ count: null, error: { message: "boom" } }),
  );

  expect(error).toBe("boom");
  expect(data).toEqual([]);
});

test("an error that is not the offset-past-end code is passed straight through", async () => {
  let countCalls = 0;
  const { data, error } = await fetchPage(
    () =>
      Promise.resolve({
        data: null,
        count: null,
        error: { message: "connection refused", code: "PGRST000" },
      }),
    () => {
      countCalls += 1;
      return Promise.resolve({ count: 0, error: null });
    },
  );

  expect(error).toBe("connection refused");
  expect(data).toEqual([]);
  expect(countCalls).toBe(0);
});

// The numbers a pager offers. Arithmetic rather than markup, because what goes
// wrong here is a page that cannot be reached from any other page.

test("a short listing offers every page and no gap", () => {
  expect(pageNumbers(1, 1)).toEqual([1]);
  expect(pageNumbers(3, 6)).toEqual([1, 2, 3, 4, 5, 6]);
});

/** The two questions a next link cannot answer: where the start is, and how far
 *  this goes. */
test("the first and the last page are always offered", () => {
  const steps = pageNumbers(40, 90);

  expect(steps[0]).toBe(1);
  expect(steps.at(-1)).toBe(90);
  expect(steps).toContain(PAGE_GAP);
});

test("the run around the current page is five wide", () => {
  expect(pageNumbers(40, 90)).toEqual([1, PAGE_GAP, 38, 39, 40, 41, 42, PAGE_GAP, 90]);
});

/** A gap where nothing is missing would claim pages that are not there. Page 3
 *  of 90 runs 1 2 3 4 5 with no break before it. */
test("a gap is only drawn where a page is actually missing", () => {
  expect(pageNumbers(3, 90)).toEqual([1, 2, 3, 4, 5, PAGE_GAP, 90]);
  expect(pageNumbers(88, 90)).toEqual([1, PAGE_GAP, 86, 87, 88, 89, 90]);
});

/**
 * The page can be past the end. `fetchPage` turns an offset past the last row
 * into an empty page against the real total, so a reader who bookmarked page 40
 * of a listing that has since shrunk lands here, and the pager still has to give
 * them a way back.
 */
test("a page outside the listing still gets a usable pager", () => {
  expect(pageNumbers(40, 3)).toEqual([1, 2, 3]);
  expect(pageNumbers(0, 5)).toEqual([1, 2, 3, PAGE_GAP, 5]);
  expect(pageNumbers(-2, 1)).toEqual([1]);
});
