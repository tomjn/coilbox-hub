import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { cardShape, cardShapes, itemHasCardShape } from "./cardShapes";

/** One row as PostgREST hands the JSON paths back: every field present, and
 *  null wherever the payload has no such path. */
interface Row {
  id: string;
  settings: unknown;
  name: unknown;
  ordered: unknown;
  buildings: unknown;
  footprints: unknown;
}

const empty = {
  settings: null,
  name: null,
  ordered: null,
  buildings: null,
  footprints: null,
};

/** Answers from a list of rows and records every set of ids it was asked for,
 *  the same shape `lib/gallery/cardPictures.test.ts` fakes with. */
function fakeSupabase(
  rows: Row[],
  asked: string[][] = [],
  units: Record<string, unknown>[] = [],
  unitGames: string[] = [],
): SupabaseClient {
  // `readAll` reads until a page comes back empty, so a read is counted on
  // its first page rather than on every page.
  let game = "";
  const catalog = {
    select: () => catalog,
    eq: (_column: string, value: string) => {
      game = value;
      return catalog;
    },
    order: () => catalog,
    range: (from: number, to: number) => {
      if (from === 0) unitGames.push(game);
      return Promise.resolve({ data: units.slice(from, to + 1), error: null });
    },
  };
  const from = (table: string) => table === "game_unit" ? catalog : ({
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

const conquestSettings = {
  seed: 12345,
  game: { shortname: "ba" },
  nodeCount: 24,
  factionCount: 2,
  layout: "spiral",
};

const warpathSettings = {
  seed: 4242,
  game: { shortname: "ba" },
  factionId: "arm",
  side: "Arm",
  length: "long",
  difficulty: 3,
  ascension: 0,
};

const blueprintParts = {
  name: "SF Double Cold Fusion",
  ordered: true,
  buildings: [
    { def: "armsolar", offset: { x: 0, z: 0 }, facing: 0 },
    { def: "armmex", offset: { x: 96, z: 0 }, facing: 1 },
  ],
  footprints: { armsolar: { x: 5, z: 5 }, armmex: { x: 3, z: 3 } },
};

test("only a challenge the hub can draw, or a blueprint, has a shape to fetch", () => {
  expect(itemHasCardShape({ kind: "challenge", mode: "conquest" })).toBe(true);
  expect(itemHasCardShape({ kind: "challenge", mode: "warpath" })).toBe(true);
  expect(itemHasCardShape({ kind: "blueprint", mode: null })).toBe(true);

  // A mode from a newer coilbox, and the kinds that draw their map instead.
  expect(itemHasCardShape({ kind: "challenge", mode: "siege" })).toBe(false);
  expect(itemHasCardShape({ kind: "challenge", mode: null })).toBe(false);
  expect(itemHasCardShape({ kind: "scenario", mode: null })).toBe(false);
  expect(itemHasCardShape({ kind: "preset", mode: null })).toBe(false);
  expect(itemHasCardShape({ kind: "setup-pack", mode: null })).toBe(false);
});

test("a conquest challenge rebuilds its galaxy from the settings alone", () => {
  const shape = cardShape(
    { kind: "challenge", mode: "conquest" },
    { ...empty, settings: conquestSettings },
  );

  expect(shape?.type).toBe("galaxy");
  if (shape?.type !== "galaxy") throw new Error("expected a galaxy");
  expect(shape.galaxy.systems).toHaveLength(24);
  expect(shape.galaxy.lanes.length).toBeGreaterThan(0);
});

test("a card's galaxy carries no names, since a card has nowhere to draw them", () => {
  const nodeNames = Object.fromEntries(
    Array.from({ length: 24 }, (_, i) => [`node-${i}`, `Star ${i}`]),
  );
  const shape = cardShape(
    { kind: "challenge", mode: "conquest" },
    {
      ...empty,
      settings: {
        ...conquestSettings,
        nodeNames,
        nodeMaps: { "node-0": "Comet Catcher" },
        factions: [{ name: "Arm", color: "#22aa44" }],
      },
    },
  );

  expect(shape?.type).toBe("galaxy");
  if (shape?.type !== "galaxy") throw new Error("expected a galaxy");
  expect(shape.galaxy.systems.every((s) => s.name === undefined)).toBe(true);
  expect(shape.galaxy.systems.every((s) => s.map === undefined)).toBe(true);
  expect(shape.galaxy.factions.every((f) => f.name === undefined)).toBe(true);
  // The payload's own faction colour survives, because a card does draw that.
  expect(shape.galaxy.factions[0].color).toBe("#22aa44");
  // The names were still read: they are what says whether this rebuild is the
  // galaxy that was shared, and a card must answer that the way the item page
  // answers it.
  expect(
    cardShape(
      { kind: "challenge", mode: "conquest" },
      { ...empty, settings: { ...conquestSettings, nodeNames: { "node-0": "Sol" } } },
    ),
  ).toBeNull();
});

test("a warpath challenge rebuilds its run from the settings alone", () => {
  const shape = cardShape(
    { kind: "challenge", mode: "warpath" },
    { ...empty, settings: warpathSettings },
  );

  expect(shape?.type).toBe("run");
  if (shape?.type !== "run") throw new Error("expected a run");
  expect(shape.run.columns).toBeGreaterThan(1);
  expect(shape.run.steps.length).toBeGreaterThan(shape.run.columns);
});

test("a blueprint rebuilds its layout from the buildings and footprints", () => {
  const shape = cardShape({ kind: "blueprint", mode: null }, { ...empty, ...blueprintParts });

  expect(shape?.type).toBe("blueprint");
  if (shape?.type !== "blueprint") throw new Error("expected a layout");
  expect(shape.layout.squares).toHaveLength(2);
  expect(shape.layout.ordered).toBe(true);
});

test("settings the generator refuses give no shape rather than an empty one", () => {
  expect(
    cardShape({ kind: "challenge", mode: "conquest" }, { ...empty, settings: null }),
  ).toBeNull();
  expect(
    cardShape(
      { kind: "challenge", mode: "warpath" },
      // No factionId, which is what coilbox itself would refuse.
      { ...empty, settings: { seed: 1, game: { shortname: "ba" } } },
    ),
  ).toBeNull();
  expect(
    cardShape({ kind: "blueprint", mode: null }, { ...empty, name: "Empty", buildings: [] }),
  ).toBeNull();
});

test("a whole page costs one query, naming only the rows worth asking about", async () => {
  const asked: string[][] = [];
  const shapes = await cardShapes(
    fakeSupabase(
      [
        { id: "a", ...empty, settings: conquestSettings },
        { id: "b", ...empty, settings: warpathSettings },
        { id: "c", ...empty, ...blueprintParts },
      ],
      asked,
    ),
    [
      { id: "a", kind: "challenge", mode: "conquest" , game_key: null },
      { id: "b", kind: "challenge", mode: "warpath" , game_key: null },
      { id: "c", kind: "blueprint", mode: null , game_key: null },
      { id: "d", kind: "scenario", mode: null , game_key: null },
      { id: "e", kind: "setup-pack", mode: null , game_key: null },
    ],
  );

  expect(asked).toEqual([["a", "b", "c"]]);
  expect(shapes.get("a")?.type).toBe("galaxy");
  expect(shapes.get("b")?.type).toBe("run");
  expect(shapes.get("c")?.type).toBe("blueprint");
  expect(shapes.get("d")).toBeUndefined();
});

test("a page with nothing to draw makes no query at all", async () => {
  const asked: string[][] = [];
  const shapes = await cardShapes(fakeSupabase([], asked), [
    { id: "a", kind: "scenario", mode: null , game_key: null },
    { id: "b", kind: "preset", mode: null , game_key: null },
  ]);

  expect(asked).toHaveLength(0);
  expect(shapes.size).toBe(0);
});

test("a row the query answered nothing for is absent, not a null entry", async () => {
  const shapes = await cardShapes(fakeSupabase([]), [
    { id: "a", kind: "challenge", mode: "conquest" , game_key: null },
  ]);

  expect(shapes.size).toBe(0);
  expect(shapes.has("a")).toBe(false);
});

test("a blueprint card carries the roles of its own buildings, read once per game", async () => {
  const unitGames: string[] = [];
  const shapes = await cardShapes(
    fakeSupabase(
      [
        { id: "c", ...empty, ...blueprintParts },
        { id: "f", ...empty, ...blueprintParts },
      ],
      [],
      [
        unitRow("ArmSolar", { energyMake: null, energyUpkeep: -20 }),
        unitRow("armmex", {}),
        unitRow("armllt", { weapon: { range: 430 } }),
      ],
      unitGames,
    ),
    [
      { id: "c", kind: "blueprint", mode: null, game_key: "ba" },
      { id: "f", kind: "blueprint", mode: null, game_key: "ba" },
    ],
  );

  expect(unitGames).toEqual(["ba"]);
  const shape = shapes.get("c");
  if (shape?.type !== "blueprint") throw new Error("expected a layout");
  // Only this layout's defs: the turret is in the game but not the layout.
  expect(shape.roles).toEqual({ armsolar: "energy" });
});

test("a blueprint with no game is drawn without roles and asks the catalog nothing", async () => {
  const unitGames: string[] = [];
  const shapes = await cardShapes(
    fakeSupabase([{ id: "c", ...empty, ...blueprintParts }], [], [], unitGames),
    [{ id: "c", kind: "blueprint", mode: null, game_key: null }],
  );

  expect(unitGames).toHaveLength(0);
  const shape = shapes.get("c");
  if (shape?.type !== "blueprint") throw new Error("expected a layout");
  expect(shape.roles).toEqual({});
});

function unitRow(unit_name: string, stats: Record<string, unknown>) {
  return {
    unit_name,
    build_options: [],
    builder: null,
    weapon: null,
    energyMake: null,
    windGenerator: null,
    tidalGenerator: null,
    energyUpkeep: null,
    ...stats,
  };
}
