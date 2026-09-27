import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import {
  addedMapsMessage,
  fetchMapPacks,
  parsePastedMapNames,
  readPackText,
} from "./packs";

test("a pasted list is split into trimmed lines with blanks dropped", () => {
  expect(parsePastedMapNames("Isis 1.3\r\n\n  comet_catcher_remake_1.8.sd7  \n")).toEqual([
    "Isis 1.3",
    "comet_catcher_remake_1.8.sd7",
  ]);
});

test("a comma is part of a name, not a separator", () => {
  expect(parsePastedMapNames("Red Comet, Remake 2")).toEqual(["Red Comet, Remake 2"]);
});

test("pack text is trimmed, a blank blurb is none, and a blank title is refused", () => {
  expect(readPackText("  BAR maps ", "  ")).toEqual({ title: "BAR maps", blurb: null });
  expect(readPackText("BAR maps", " Every map ")).toEqual({ title: "BAR maps", blurb: "Every map" });
  expect(readPackText("   ", "")).toBeNull();
  expect(readPackText("x".repeat(129), "")).toBeNull();
  expect(readPackText("BAR maps", "x".repeat(2001))).toBeNull();
});

test("adding says how many lines matched and names the ones that did not", () => {
  expect(
    addedMapsMessage([
      { wanted: "isis_1.3.sd7", map_name: "Isis 1.3" },
      { wanted: "Nowhere 9", map_name: null },
      { wanted: "Elsewhere", map_name: null },
    ]),
  ).toBe("1 line matched a map. No map is called: Nowhere 9, Elsewhere.");
  expect(addedMapsMessage([{ wanted: "Isis 1.3", map_name: "Isis 1.3" }])).toBe(
    "1 line matched a map. Maps already in the pack stay where they were.",
  );
});

/** A stand in for the two reads fetchMapPacks makes. Every builder method
 *  returns the same chain, and awaiting it answers the table's rows. */
function fakeClient(tables: Record<string, { data: unknown[]; error: null | { message: string } }>) {
  return {
    from(table: string) {
      const answer = tables[table];
      const result = { data: answer.data, count: answer.data.length, error: answer.error };
      const chain = {
        select: () => chain,
        eq: () => chain,
        order: () => chain,
        range: async () => result,
        then: (resolve: (value: typeof result) => unknown) => resolve(result),
      };
      return chain;
    },
  } as unknown as SupabaseClient;
}

test("packs come back featured first, newest feature first, then by title, each with its maps", async () => {
  const { packs, error } = await fetchMapPacks(
    fakeClient({
      map_pack: {
        data: [
          { id: "b", title: "Beta", blurb: null, featured_at: null },
          { id: "old", title: "Old", blurb: null, featured_at: "2026-01-01T00:00:00Z" },
          { id: "a", title: "Alpha", blurb: null, featured_at: null },
          { id: "new", title: "New", blurb: "x", featured_at: "2026-09-01T00:00:00Z" },
        ],
        error: null,
      },
      map_pack_entry: {
        data: [
          { pack_id: "new", map_name: "Isis 1.3", slug: "isis-1-3", display_name: null, archive_filename: "isis_1.3.sd7" },
          { pack_id: "new", map_name: "Gone 1", slug: null, display_name: null, archive_filename: null },
        ],
        error: null,
      },
    }),
  );

  expect(error).toBeNull();
  expect(packs.map((pack) => pack.id)).toEqual(["new", "old", "a", "b"]);
  expect(packs[0].maps).toEqual([
    { mapName: "Isis 1.3", slug: "isis-1-3", displayName: null, archiveFilename: "isis_1.3.sd7" },
    { mapName: "Gone 1", slug: null, displayName: null, archiveFilename: null },
  ]);
  expect(packs[1].maps).toEqual([]);
});

test("a failed read is an error, not an empty list", async () => {
  const { packs, error } = await fetchMapPacks(
    fakeClient({
      map_pack: { data: [], error: null },
      map_pack_entry: { data: [], error: { message: "down" } },
    }),
  );

  expect(error).toBe("down");
  expect(packs).toEqual([]);
});
