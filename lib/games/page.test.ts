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
  game_faction: [
    { key: "armada", name: "Armada", logo_path: null },
    { key: "cortex", name: "Cortex", logo_path: "factions/cortex.webp" },
  ],
  game_version: [{ version: "2.0.0", last_seen_at: "2026-08-21T00:00:00Z" }],
};

function fakeSupabase(row: unknown, counts: unknown): SupabaseClient {
  const answer = (data: unknown) => ({
    select: () => ({
      eq: () => ({
        maybeSingle: () => Promise.resolve({ data, error: null }),
        order: () => ({
          order: () => ({
            limit: () => ({ maybeSingle: () => Promise.resolve({ data, error: null }) }),
          }),
        }),
      }),
      maybeSingle: () => Promise.resolve({ data, error: null }),
    }),
  });
  return {
    from: (table: string) => (table === "game_browse" ? answer(counts) : answer(row)),
  } as unknown as SupabaseClient;
}

test("a page carries the row's facts with the junk dropped", async () => {
  const page = await loadGamePage(
    fakeSupabase(GAME_ROW, { faction_count: 2, unit_count: 340 }),
    "BA",
  );
  expect(page).not.toBeNull();
  if (!page) return;
  expect(page.links).toEqual([{ label: "Forum", url: "https://example.test" }]);
  expect(page.factions.map((faction) => faction.name)).toEqual(["Armada", "Cortex"]);
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
