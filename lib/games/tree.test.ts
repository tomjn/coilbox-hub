import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { buildTree, loadTree, matchesQuery, type TreeNode } from "./tree";

/**
 * The grouping the build tree shows (#228), which is coilbox's own walk and
 * carries upstream's rules: first root to reach a unit claims it, a unit two
 * builders make is listed by both, dangling edges are dropped, and units no
 * root reaches stay visible in an ungrouped block.
 */

const UNITS = [
  { unit_name: "armcom", full_name: "Commander", build_options: ["armsolar", "armmex", "ghost"] },
  { unit_name: "corcom", full_name: "Commander", build_options: ["armsolar"] },
  { unit_name: "armsolar", full_name: "Solar Collector", build_options: [] },
  { unit_name: "armmex", full_name: "Metal Extractor", build_options: ["ARMSOLAR"] },
  { unit_name: "armfark", full_name: "Fark", build_options: [] },
];

test("the first root to reach a unit claims it, and shared units are shared", () => {
  const tree = buildTree(UNITS, ["armcom", "corcom"]);

  expect(tree.factions.map((faction) => faction.root)).toEqual(["armcom", "corcom"]);
  const armada = tree.factions[0].units.map((unit) => unit.name);
  const cortex = tree.factions[1].units.map((unit) => unit.name);
  // armsolar is reached by both; armcom's walk got there first.
  expect(armada).toEqual(["armcom", "armmex", "armsolar"]);
  expect(cortex).toEqual(["corcom"]);
});

test("a unit two builders make is listed by both of them", () => {
  const tree = buildTree(UNITS, ["armcom", "corcom"]);
  const commander = tree.factions[0].units.find((unit) => unit.name === "armcom");
  const cortex = tree.factions[1].units.find((unit) => unit.name === "corcom");
  expect(commander?.builds).toContain("armsolar");
  expect(cortex?.builds).toContain("armsolar");
});

test("dangling edges are dropped from what a unit builds", () => {
  const tree = buildTree(UNITS, ["armcom"]);
  const commander = tree.factions[0].units.find((unit) => unit.name === "armcom");
  expect(commander?.builds).toEqual(["armmex", "armsolar"]);
});

test("a start unit nobody holds heads nothing", () => {
  const tree = buildTree(UNITS, ["armcom", "legcom"]);
  expect(tree.factions.map((faction) => faction.root)).toEqual(["armcom"]);
});

test("units no root reaches stay visible as ungrouped", () => {
  const tree = buildTree(UNITS, ["corcom"]);
  // armsolar is reached from corcom, so it belongs to cortex; the commander
  // line and what only it builds are the ones left outside.
  expect(tree.ungrouped.map((unit) => unit.name)).toEqual(["armcom", "armfark", "armmex"]);
});

test("keys match case-insensitively, because def keys arrive however they like", () => {
  const tree = buildTree(UNITS, ["ARMCOM"]);
  expect(tree.factions).toHaveLength(1);
  expect(tree.factions[0].root).toBe("armcom");
});

test("search matches either name a reader could know", () => {
  const node: TreeNode = { name: "armcom", label: "Commander", builds: [] };
  expect(matchesQuery(node, "command")).toBe(true);
  expect(matchesQuery(node, "ARM")).toBe(true);
  expect(matchesQuery(node, "kbot")).toBe(false);
  expect(matchesQuery(node, null)).toBe(true);
});

/**
 * Leaving retired units out is a null check, and PostgREST spells that
 * `is.null` (#255). Asking it for `removed_at=eq.null` is refused, and
 * `loadTree` answers null on a refused read, which the page turns into a 404.
 * So the whole tree went missing rather than one retired unit.
 */

interface UnitRow {
  unit_name: string;
  full_name: string | null;
  build_options: string[];
  removed_at: string | null;
}

const STORED: UnitRow[] = [
  { unit_name: "armcom", full_name: "Commander", build_options: ["armsolar"], removed_at: null },
  { unit_name: "armsolar", full_name: "Solar Collector", build_options: [], removed_at: null },
  { unit_name: "armbrawl", full_name: "Brawler", build_options: [], removed_at: "2026-01-01" },
];

/**
 * A hub holding `rows` for one game, answering about null the way PostgREST
 * does: `is` is the null check, and a null handed to `eq` is refused. A fake
 * that quietly accepted `eq` would pass while every tree stayed a 404.
 */
function fakeHub(rows: UnitRow[], startUnits: string[]): SupabaseClient {
  const units = (held: UnitRow[], refused: string | null) => ({
    select: () => units(held, refused),
    eq(column: string, value: unknown) {
      if (value === null) {
        return units(held, `"failed to parse filter (eq.null)" on ${column}`);
      }
      if (column === "game.shortname") return units(held, refused);
      return units(
        held.filter((row) => row[column as keyof UnitRow] === value),
        refused,
      );
    },
    is(column: string, value: unknown) {
      return units(
        held.filter((row) => row[column as keyof UnitRow] === value),
        refused,
      );
    },
    then: (resolve: (value: unknown) => unknown) =>
      Promise.resolve({
        data: refused ? null : held,
        error: refused ? { message: refused } : null,
      }).then(resolve),
  });

  const game = {
    select: () => game,
    eq: () => game,
    maybeSingle: () =>
      Promise.resolve({ data: { start_units: startUnits }, error: null }),
  };

  return {
    from: (table: string) => (table === "game" ? game : units(rows, null)),
  } as unknown as SupabaseClient;
}

test("the tree draws from the units the hub has not retired", async () => {
  const tree = await loadTree(fakeHub(STORED, ["armcom"]), "BA");

  expect(tree).not.toBeNull();
  expect(tree?.factions.map((faction) => faction.root)).toEqual(["armcom"]);
  expect(tree?.factions[0].units.map((unit) => unit.name)).toEqual([
    "armcom",
    "armsolar",
  ]);
});

test("a retired unit is left out of the tree rather than taking it down", async () => {
  const tree = await loadTree(fakeHub(STORED, ["armcom"]), "BA");

  const named = JSON.stringify(tree);
  expect(named).not.toContain("armbrawl");
});
