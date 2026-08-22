import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { loadUnitGrid, parseUnitGridFilters } from "./units";

/**
 * Hiding retired units is a null check, and PostgREST spells that `is.null`
 * (#255). Asking it for `removed_at=eq.null` is refused, which took out the
 * whole encyclopedia rather than a single unit, and looked the same from the
 * page as a game with nothing in it.
 */

interface Row {
  unit_name: string;
  full_name: string | null;
  faction_key: string | null;
  removed_at: string | null;
}

const ROWS: Row[] = [
  { unit_name: "armcom", full_name: "Commander", faction_key: "arm", removed_at: null },
  { unit_name: "armsolar", full_name: "Solar Collector", faction_key: "arm", removed_at: null },
  { unit_name: "armbrawl", full_name: "Brawler", faction_key: "arm", removed_at: "2026-01-01" },
];

/**
 * A `game_unit` that answers about null the way PostgREST does: `is` is the
 * null check, and a null handed to `eq` is refused rather than matching
 * anything. That refusal is the whole of what this proves, so a fake that
 * quietly accepted it would pass while the site stayed broken.
 */
function fakeUnits(rows: Row[]): SupabaseClient {
  const build = (held: Row[], refused: string | null) => {
    const answer = (data: Row[] | null, count: number | null) => ({
      data: refused ? null : data,
      count: refused ? null : count,
      error: refused ? { message: refused } : null,
    });

    const builder = {
      select: () => build(held, refused),
      eq(column: string, value: unknown) {
        if (value === null) {
          return build(held, `"failed to parse filter (eq.null)" on ${column}`);
        }
        // The join filter narrows to one game, which this table already is.
        if (column === "game.shortname") return build(held, refused);
        return build(
          held.filter((row) => row[column as keyof Row] === value),
          refused,
        );
      },
      is(column: string, value: unknown) {
        return build(
          held.filter((row) => row[column as keyof Row] === value),
          refused,
        );
      },
      or: () => build(held, refused),
      order: () => build(held, refused),
      range: (from: number, to: number) =>
        Promise.resolve(answer(held.slice(from, to + 1), held.length)),
      then: (resolve: (value: unknown) => unknown) =>
        Promise.resolve(answer(held, held.length)).then(resolve),
    };
    return builder;
  };

  return { from: () => build(rows, null) } as unknown as SupabaseClient;
}

test("the grid hides retired units without being refused for it", async () => {
  const grid = await loadUnitGrid(
    fakeUnits(ROWS),
    "BA",
    parseUnitGridFilters({}),
  );

  expect(grid.error).toBeNull();
  expect(grid.units.map((u) => u.unit_name)).toEqual(["armcom", "armsolar"]);
});

test("asking for retired units shows the one that was removed", async () => {
  const grid = await loadUnitGrid(
    fakeUnits(ROWS),
    "BA",
    parseUnitGridFilters({ retired: "1" }),
  );

  expect(grid.error).toBeNull();
  expect(grid.units.map((u) => u.unit_name)).toEqual([
    "armcom",
    "armsolar",
    "armbrawl",
  ]);
});

test("a faction filter narrows the grid to that side (#258)", async () => {
  const rows: Row[] = [
    ...ROWS,
    { unit_name: "corcom", full_name: "Commander", faction_key: "core", removed_at: null },
    { unit_name: "corsolar", full_name: "Solar Collector", faction_key: "core", removed_at: null },
  ];
  const grid = await loadUnitGrid(fakeUnits(rows), "BA", parseUnitGridFilters({ faction: "core" }));

  expect(grid.error).toBeNull();
  expect(grid.units.map((u) => u.unit_name)).toEqual(["corcom", "corsolar"]);
});

test("the faction filter parses to null when nothing is chosen", () => {
  expect(parseUnitGridFilters({}).faction).toBeNull();
  expect(parseUnitGridFilters({ faction: "" }).faction).toBeNull();
  expect(parseUnitGridFilters({ faction: ["core"] }).faction).toBe("core");
});
