import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { cardCounts } from "./cardCounts";

/** One row as PostgREST hands the JSON paths back: every field present, and
 *  null wherever the payload has no such path. */
interface Row {
  id: string;
  overrides: unknown;
  menus: unknown;
  text: unknown;
  disabled: unknown;
  /** `public.clone_count(item)`, already counted in the database. */
  clone_count: number | null;
}

const empty = { overrides: null, menus: null, text: null, disabled: null, clone_count: 0 };

/** Answers from a list of rows and records every set of ids it was asked
 *  for, the same shape `lib/gallery/cardShapes.test.ts` fakes with. */
function fakeSupabase(
  rows: Row[],
  asked: string[][] = [],
  selected: string[] = [],
): SupabaseClient {
  const from = () => ({
    select: (columns: string) => ({
      in(_column: string, ids: readonly string[]) {
        selected.push(columns);
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
      [
        {
          id: "a",
          ...empty,
          overrides: { armsolar: { maxdamage: 1 } },
          disabled: ["armmex"],
          clone_count: 3,
        },
      ],
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
    clones: 3,
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

test("the clone count comes from the database and no clone definition is requested", async () => {
  const selected: string[] = [];
  await cardCounts(fakeSupabase([], [], selected), [{ id: "a", kind: "mod-project" }]);

  expect(selected).toHaveLength(1);
  expect(selected[0]).toContain("clone_count");
  expect(selected[0]).not.toContain("edits->clones");
});

test("a row with a null clone count reads as no clones", async () => {
  const counts = await cardCounts(fakeSupabase([{ id: "a", ...empty, clone_count: null }]), [
    { id: "a", kind: "mod-project" },
  ]);

  expect(counts.get("a")?.clones).toBe(0);
});
