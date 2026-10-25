import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { loadGamePage } from "./page";

/**
 * What one game's page reads and assembles (#226), against a stand in for the
 * anonymous client. The rules behind the tables are proved in pgTAP; what is
 * under test here is the shape the page receives and the two decisions the
 * module makes on its way out: which release counts as current, and what a
 * malformed links blob contributes.
 */

const GAME_ROW = {
  shortname: "BA",
  display_name: "Balanced Annihilation",
  description: null,
  links: [
    { label: "Forum", url: "https://example.test" },
    { label: "", url: "https://example.test/empty" },
    "junk",
  ],
  conquest_factions: [
    { name: "Arm", color: "#2f7dff", side: "ARM" },
    { color: "#ff3524" },
    "junk",
  ],
  game_download_source: [
    { kind: "github", value: "springraaar/metal_factions", asset: "metal_factions", filename: null },
    { kind: "rapid", value: "metalfactions:stable", asset: null, filename: null },
    { kind: "torrent", value: "whatever", asset: null, filename: null },
  ],
  game_faction: [
    { key: "armada", name: "Armada", logo_path: null },
    { key: "cortex", name: "Cortex", logo_path: "factions/cortex.webp" },
    { key: "random", name: "Random", logo_path: null },
  ],
  game_version: [{ version: "2.0.0", last_seen_at: "2026-08-21T00:00:00Z" }],
};

/** `mutators` answers the read for games built on this one, and `base` the
 *  lookup of this game's own base game. */
function fakeSupabase(
  row: unknown,
  counts: unknown,
  related: { base?: unknown; mutators?: unknown[] } = {},
): SupabaseClient {
  // `order` and `limit` answer themselves, so the double does not have to be
  // rewritten every time the real query sorts one more embedded table.
  const answer = (data: unknown) => {
    const chain = {
      maybeSingle: () => Promise.resolve({ data, error: null }),
      order: () => chain,
      limit: () => chain,
    };
    return { select: () => ({ eq: () => chain, maybeSingle: chain.maybeSingle }) };
  };
  const browse = (columns: string) => ({
    eq: (column: string) => {
      if (column === "base_game") return Promise.resolve({ data: related.mutators ?? [], error: null });
      if (columns === "shortname,display_name") {
        return { maybeSingle: () => Promise.resolve({ data: related.base ?? null, error: null }) };
      }
      return answer(counts).select().eq();
    },
  });
  return {
    from: (table: string) =>
      table === "game_browse" ? { select: (columns: string) => browse(columns) } : answer(row),
  } as unknown as SupabaseClient;
}

test("a page carries the row's facts with the junk and the Random side dropped", async () => {
  const page = await loadGamePage(
    fakeSupabase(GAME_ROW, { faction_count: 2, unit_count: 340 }),
    "BA",
  );
  expect(page).not.toBeNull();
  if (!page) return;
  expect(page.links).toEqual([{ label: "Forum", url: "https://example.test" }]);
  expect(page.conquest_factions).toEqual([{ name: "Arm", color: "#2f7dff", side: "ARM" }]);
  expect(page.factions.map((faction) => faction.name)).toEqual(["Armada", "Cortex"]);
  // In the order the query asked for, with the kind nothing knows what to do
  // with left out.
  expect(page.downloads).toEqual([
    { kind: "github", value: "springraaar/metal_factions", asset: "metal_factions" },
    { kind: "rapid", value: "metalfactions:stable" },
  ]);
  expect(page.release).toBe("2.0.0");
  expect(page.unit_count).toBe(340);
});

test("a shortname nobody holds is not found", async () => {
  const page = await loadGamePage(fakeSupabase(null, null), "XX");
  expect(page).toBeNull();
});

test("a game whose counts cannot be read is treated as absent, not as empty", async () => {
  const page = await loadGamePage(fakeSupabase(GAME_ROW, null), "BA");
  expect(page).toBeNull();
});

test("no release reported yet means no claim about freshness", async () => {
  const page = await loadGamePage(
    fakeSupabase({ ...GAME_ROW, game_version: [] }, { faction_count: 0, unit_count: 0 }),
    "BA",
  );
  expect(page?.release).toBeNull();
});

const COUNTS = { faction_count: 0, unit_count: 0 };

test("a game that is not a mutator has no base game and no mutators", async () => {
  const page = await loadGamePage(fakeSupabase({ ...GAME_ROW, base_game: null }, COUNTS), "BA");
  expect(page?.base_game).toBeNull();
  expect(page?.base).toBeNull();
  expect(page?.mutators).toEqual([]);
});

test("a base game lists its mutators by the title a reader sees", async () => {
  const page = await loadGamePage(
    fakeSupabase(GAME_ROW, COUNTS, {
      mutators: [
        { shortname: "zz-rush", display_name: null },
        { shortname: "ba-wrapper", display_name: "Wrapper" },
        { shortname: "ba-arena", display_name: "Arena" },
      ],
    }),
    "BA",
  );
  expect(page?.mutators).toEqual([
    { shortname: "ba-arena", title: "Arena" },
    { shortname: "ba-wrapper", title: "Wrapper" },
    { shortname: "zz-rush", title: "zz-rush" },
  ]);
});

test("a mutator links to its base game when the hub holds it", async () => {
  const page = await loadGamePage(
    fakeSupabase({ ...GAME_ROW, shortname: "BA-Rush", base_game: "BA" }, COUNTS, {
      base: { shortname: "BA", display_name: "Balanced Annihilation" },
    }),
    "BA-Rush",
  );
  expect(page?.base_game).toBe("BA");
  expect(page?.base).toEqual({ shortname: "BA", title: "Balanced Annihilation" });
});

test("a mutator whose base game is not on the hub keeps the name and has no link target", async () => {
  const page = await loadGamePage(
    fakeSupabase({ ...GAME_ROW, shortname: "BA-Rush", base_game: "Elsewhere" }, COUNTS),
    "BA-Rush",
  );
  expect(page?.base_game).toBe("Elsewhere");
  expect(page?.base).toBeNull();
});
