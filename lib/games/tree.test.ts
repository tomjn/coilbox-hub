import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { buildTree, loadTree } from "./tree";

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

test("units no root reaches stay visible as ungrouped, unless nothing builds them either", () => {
  const tree = buildTree(UNITS, ["corcom"]);
  // armsolar is reached from corcom, so it belongs to cortex. armmex is
  // unreachable but armcom builds it, so it stays visible. armcom itself and
  // armfark have no incoming edges at all - archive ghosts (#280) - so they
  // hide rather than head an ungrouped block.
  expect(tree.ungrouped.map((unit) => unit.name)).toEqual(["armmex"]);
});

test("a reference kept only by an unreachable unit still counts as built", () => {
  // An orphaned cluster that points at each other stays visible as a cluster;
  // hiding chain by chain would take the whole island out one walk at a time.
  const island = [
    { unit_name: "root", full_name: "Root", build_options: ["a"] },
    { unit_name: "a", full_name: "A", build_options: ["b"] },
    { unit_name: "b", full_name: "B", build_options: ["a"] },
    { unit_name: "ghost", full_name: "Ghost", build_options: [] },
  ];
  const tree = buildTree(island, ["missing"]);
  // root heads no faction and nobody builds it either, so it is a ghost like
  // ghost is; the pair that points at each other survives.
  expect(tree.ungrouped.map((unit) => unit.name)).toEqual(["a", "b"]);
});

test("keys match case-insensitively, because def keys arrive however they like", () => {
  const tree = buildTree(UNITS, ["ARMCOM"]);
  expect(tree.factions).toHaveLength(1);
  expect(tree.factions[0].root).toBe("armcom");
});

/**
 * Armed-ness rides the walk too (#278): a weapons summary is a stat holding an
 * array of records, the same shape #261 draws as a table. Nothing measured is
 * not armed, whatever the shape around it says.
 */

const ARMED_UNITS = [
  { unit_name: "armbase", full_name: "Base", build_options: ["armllt", "armemp", "armck"], stats: {} },
  {
    unit_name: "armllt",
    full_name: "Light Laser Tower",
    build_options: [],
    stats: { weapons: [{ range: 210 }] },
  },
  { unit_name: "armsolar", full_name: "Solar Collector", build_options: [], stats: {} },
  {
    unit_name: "armemp",
    full_name: "EMP Missile",
    build_options: [],
    stats: { weapons: [] },
  },
  {
    unit_name: "armck",
    full_name: "Constructor Kbot",
    build_options: ["armsolar"],
    stats: { health: 200 },
  },
];

test("a weapons summary makes a unit armed", () => {
  const tree = buildTree(ARMED_UNITS, ["armbase"]);
  const armed = new Map(tree.factions[0].units.map((u) => [u.name, u.armed]));
  expect(armed.get("armllt")).toBe(true);
});

test("no summary, an empty one, or none at all leaves a unit unarmed", () => {
  const tree = buildTree(ARMED_UNITS, ["armbase"]);
  const armed = new Map(tree.factions[0].units.map((u) => [u.name, u.armed]));
  expect(armed.get("armsolar")).toBe(false);
  expect(armed.get("armemp")).toBe(false);
  expect(armed.get("armck")).toBe(false);
});

/**
 * A morph chain is one node (#295). Five rows for a five level commander are
 * one unit at five stages of its life, and a level that unlocks a factory folds
 * that factory under the same node rather than starting a second subtree.
 */

const MORPH_UNITS = [
  {
    unit_name: "armcom1",
    full_name: "Commander",
    build_options: ["armsolar"],
    morph_targets: [{ into: "armcom2" }],
  },
  {
    unit_name: "armcom2",
    full_name: "Commander, level 2",
    build_options: ["armsolar", "armvp"],
    morph_targets: [{ into: "armcom3" }],
    stats: { weapons: [{ range: 300 }] },
  },
  {
    unit_name: "armcom3",
    full_name: "Commander, level 3",
    build_options: ["armfus"],
    morph_targets: [],
  },
  { unit_name: "armsolar", full_name: "Solar Collector", build_options: [], morph_targets: [] },
  { unit_name: "armfus", full_name: "Fusion Plant", build_options: [], morph_targets: [] },
  {
    unit_name: "armvp",
    full_name: "Vehicle Plant",
    build_options: ["armcom3"],
    morph_targets: [],
  },
];

test("a morph chain is one node, and the levels are not nodes of their own", () => {
  const tree = buildTree(MORPH_UNITS, ["armcom1"]);
  const named = tree.factions[0].units.map((unit) => unit.name);

  expect(named).toContain("armcom1");
  expect(named).not.toContain("armcom2");
  expect(named).not.toContain("armcom3");
  expect(JSON.stringify(tree.ungrouped)).not.toContain("armcom2");
});

test("what the levels build folds into the one node", () => {
  const tree = buildTree(MORPH_UNITS, ["armcom1"]);
  const commander = tree.factions[0].units.find((unit) => unit.name === "armcom1");

  // armsolar from level one, armvp from level two, armfus from level three.
  expect(commander?.builds).toEqual(["armfus", "armsolar", "armvp"]);
});

test("a node says how many stages it stands for, and an ordinary unit says one", () => {
  const tree = buildTree(MORPH_UNITS, ["armcom1"]);
  const stages = new Map(tree.factions[0].units.map((unit) => [unit.name, unit.stages]));

  expect(stages.get("armcom1")).toBe(3);
  expect(stages.get("armsolar")).toBe(1);
});

test("a build option naming a level points at the level's base", () => {
  const tree = buildTree(MORPH_UNITS, ["armcom1"]);
  const plant = tree.factions[0].units.find((unit) => unit.name === "armvp");

  // The vehicle plant reports building armcom3. The reader sees it pointing at
  // the commander, which is the unit armcom3 is a stage of.
  expect(plant?.builds).toEqual(["armcom1"]);
});

test("a node is armed when any of its stages is", () => {
  const tree = buildTree(MORPH_UNITS, ["armcom1"]);
  const commander = tree.factions[0].units.find((unit) => unit.name === "armcom1");

  // Only level two reports weapons. The node stands for the whole life of the
  // unit, so the node shoots.
  expect(commander?.armed).toBe(true);
});

test("a start unit naming a level heads the group its level belongs to", () => {
  const tree = buildTree(MORPH_UNITS, ["armcom2"]);

  expect(tree.factions.map((faction) => faction.root)).toEqual(["armcom1"]);
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
  faction_key: string | null;
  removed_at: string | null;
}

const STORED: UnitRow[] = [
  { unit_name: "armcom", full_name: "Commander", build_options: ["armsolar"], faction_key: "arm", removed_at: null },
  { unit_name: "armsolar", full_name: "Solar Collector", build_options: [], faction_key: "arm", removed_at: null },
  { unit_name: "corcom", full_name: "Commander", build_options: [], faction_key: "core", removed_at: null },
  { unit_name: "armbrawl", full_name: "Brawler", build_options: [], faction_key: "arm", removed_at: "2026-01-01" },
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

test("a faction scope keeps the whole walk to that side's units", async () => {
  const tree = await loadTree(fakeHub(STORED, ["armcom", "corcom"]), "BA", undefined, "core");

  expect(tree?.factions.map((faction) => faction.root)).toEqual(["corcom"]);
  // The other side's units do not appear anywhere in the answer, not even as
  // build options the walk could have followed across.
  expect(JSON.stringify(tree)).not.toContain("armcom");
});
