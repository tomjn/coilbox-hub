import { expect, test } from "bun:test";
import { readAll, READ_ALL_PAGE } from "./readAll";

/**
 * PostgREST caps a response at `max_rows`, 1000 in `supabase/config.toml`,
 * while a game may carry 2000 units. A read that took one request for the whole
 * table came back partial and said nothing about it, which is worse than
 * failing: every walk over the answer drew a game that does not exist.
 */

/** A table of `total` rows that will hand over at most `cap` of them at a
 *  time, which is what the server does. */
function table(total: number, cap: number) {
  const rows = Array.from({ length: total }, (_, index) => ({ n: index }));
  const asked: [number, number][] = [];

  return {
    asked,
    page: (from: number, to: number) => {
      asked.push([from, to]);
      return Promise.resolve({
        data: rows.slice(from, Math.min(to + 1, from + cap)),
        error: null,
      });
    },
  };
}

test("a table larger than one response comes back whole", async () => {
  const held = table(2_500, READ_ALL_PAGE);
  const rows = await readAll<{ n: number }>(held.page);

  expect(rows).toHaveLength(2_500);
  expect(rows?.[0].n).toBe(0);
  expect(rows?.[2_499].n).toBe(2_499);
});

test("a table that fits in one response costs two requests, not more", async () => {
  const held = table(3, READ_ALL_PAGE);
  const rows = await readAll<{ n: number }>(held.page);

  expect(rows).toHaveLength(3);
  // One for the rows, one to learn there are no more. A short page cannot end
  // the loop, because a short page is exactly what the cap produces.
  expect(held.asked).toHaveLength(2);
});

test("a server that caps lower than we ask for is still read whole", async () => {
  // A deployment may lower max_rows. Advancing by what arrived rather than by
  // what was asked for is what makes that safe.
  const held = table(50, 7);
  const rows = await readAll<{ n: number }>(held.page);

  expect(rows).toHaveLength(50);
  expect(new Set(rows?.map((row) => row.n)).size).toBe(50);
});

test("an empty table is an empty answer, not a failure", async () => {
  const rows = await readAll<{ n: number }>(table(0, READ_ALL_PAGE).page);
  expect(rows).toEqual([]);
});

test("a failed request is null, not a partial answer", async () => {
  let requests = 0;
  const rows = await readAll<{ n: number }>((from, to) => {
    requests += 1;
    if (requests > 1) {
      return Promise.resolve({ data: null, error: { message: "gone" } });
    }
    return Promise.resolve({
      data: Array.from({ length: to - from + 1 }, (_, index) => ({ n: from + index })),
      error: null,
    });
  });

  // Half a game is worse than no game: the caller can tell a failure from an
  // answer, and cannot tell a partial answer from a whole one.
  expect(rows).toBeNull();
});

test("a server that never returns an empty page does not loop forever", async () => {
  let requests = 0;
  const rows = await readAll<{ n: number }>(() => {
    requests += 1;
    return Promise.resolve({ data: [{ n: requests }], error: null });
  });

  expect(rows).not.toBeNull();
  expect(requests).toBe(2_000);
});
