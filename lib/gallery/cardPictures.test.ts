import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { cardMapPictures } from "./cardPictures";

interface Row {
  game: string | null;
  unit_name: string | null;
  map_name: string | null;
  variant: string;
  tier: string;
  path: string;
  width: number;
  height: number;
  moderation: string;
}

/** Answers from a list of rows and records every `or()` filter it was asked,
 *  the same shape `lib/gallery/itemPictures.test.ts` fakes with. */
function fakeSupabase(rows: Row[], queries: string[] = []): SupabaseClient {
  const from = () => ({
    select: () => {
      const filters: [string, string][] = [];
      const builder = {
        eq(column: string, value: string) {
          filters.push([column, value]);
          return builder;
        },
        or(filter: string) {
          queries.push(filter);
          return Promise.resolve({
            data: rows.filter((row) =>
              filters.every(([column, value]) => row[column as keyof Row] === value),
            ),
            error: null,
          });
        },
      };
      return builder;
    },
  });

  return { from } as unknown as SupabaseClient;
}

const COMET: Row = {
  game: null,
  unit_name: null,
  map_name: "Comet Catcher",
  variant: "minimap",
  tier: "static",
  path: "maps/comet.webp",
  width: 512,
  height: 512,
  moderation: "approved",
};

test("only a scenario or a preset that names a map is looked up at all", async () => {
  const queries: string[] = [];
  const pictures = await cardMapPictures(fakeSupabase([], queries), [
    { kind: "setup-pack", map_name: "Comet Catcher" },
    { kind: "blueprint", map_name: null },
    { kind: "scenario", map_name: null },
  ]);

  expect(pictures.size).toBe(0);
  expect(queries).toHaveLength(0);
});

test("a picture the hub holds resolves for the scenario's map", async () => {
  const pictures = await cardMapPictures(fakeSupabase([COMET]), [
    { kind: "scenario", map_name: "Comet Catcher" },
  ]);

  expect(pictures.get("Comet Catcher")?.from).toBe("static");
});

test("a map the hub holds nothing for still gets an entry, the placeholder", async () => {
  const pictures = await cardMapPictures(fakeSupabase([]), [
    { kind: "preset", map_name: "Unknown Map" },
  ]);

  expect(pictures.get("Unknown Map")?.from).toBe("placeholder");
});

test("two rows naming the same map cost one lookup, not two", async () => {
  const queries: string[] = [];
  const pictures = await cardMapPictures(fakeSupabase([COMET], queries), [
    { kind: "scenario", map_name: "Comet Catcher" },
    { kind: "preset", map_name: "Comet Catcher" },
  ]);

  expect(pictures.size).toBe(1);
  expect(queries).toHaveLength(1);
});
