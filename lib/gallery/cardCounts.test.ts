import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { cardCounts } from "./cardCounts";

/** One row as PostgREST hands the JSON paths back: every field present, and
 *  null wherever the payload has no such path. */
interface Row {
  id: string;
  overrides: unknown;
  clones: unknown;
  menus: unknown;
  text: unknown;
  disabled: unknown;
}

const empty = { overrides: null, clones: null, menus: null, text: null, disabled: null };

/** Answers from a list of rows and records every set of ids it was asked
 *  for, the same shape `lib/gallery/cardShapes.test.ts` fakes with. */
function fakeSupabase(rows: Row[], asked: string[][] = []): SupabaseClient {
  const from = () => ({
    select: () => ({
      in(_column: string, ids: readonly string[]) {
        asked.push([...ids]);
        return Promise.resolve({
          data: rows.filter((row) => ids.includes(row.id)),
          error: null,
        });
      },
    }),
  });

  return { from } as unknown as SupabaseClient;
}

test("a whole page costs one query, naming only the mod-project rows", async () => {
  const asked: string[][] = [];
  const counts = await cardCounts(
    fakeSupabase(
      [{ id: "a", ...empty, overrides: { armsolar: { maxdamage: 1 } }, disabled: ["armmex"] }],
      asked,
    ),
    [
      { id: "a", kind: "mod-project" },
      { id: "b", kind: "blueprint" },
      { id: "c", kind: "preset" },
    ],
  );

  expect(asked).toEqual([["a"]]);
  expect(counts.get("a")).toEqual({
    unitsTouched: 1,
    fields: 1,
    clones: 0,
    menuOps: 0,
    disabled: 1,
  });
  expect(counts.get("b")).toBeUndefined();
});

test("a page with no mod-project on it makes no query at all", async () => {
  const asked: string[][] = [];
  const counts = await cardCounts(fakeSupabase([], asked), [
    { id: "a", kind: "blueprint" },
    { id: "b", kind: "preset" },
  ]);

  expect(asked).toHaveLength(0);
  expect(counts.size).toBe(0);
});

test("a row the query answered nothing for is absent, not a null entry", async () => {
  const counts = await cardCounts(fakeSupabase([]), [{ id: "a", kind: "mod-project" }]);

  expect(counts.size).toBe(0);
  expect(counts.has("a")).toBe(false);
});
