import { expect, test } from "bun:test";
import { generateRun } from "@/lib/runlite/generate";
import { warpathRun } from "./warpathRun";

const payload = (settings: Record<string, unknown>) => ({
  mode: "warpath",
  settings: {
    seed: 4242,
    game: { shortname: "ba" },
    factionId: "arm",
    side: "Arm",
    length: "standard",
    difficulty: 3,
    ascension: 0,
    skin: "galaxy",
    ...settings,
  },
});

const buildGraph = {
  startUnit: "commander",
  edges: new Map<string, string[]>([
    ["commander", ["botlab", "vehiclelab", "solar"]],
    ["botlab", ["peewee", "rocko"]],
    ["vehiclelab", ["flash", "stumpy"]],
    ["solar", []],
    ["peewee", []],
    ["rocko", []],
    ["flash", []],
    ["stumpy", []],
  ]),
  names: new Map<string, string>(
    [
      "commander",
      "botlab",
      "vehiclelab",
      "solar",
      "peewee",
      "rocko",
      "flash",
      "stumpy",
    ].map((n) => [n, n]),
  ),
};

test("the same seed gives the same run map on every call", () => {
  expect(warpathRun(payload({}))).toEqual(warpathRun(payload({})));
});

test("a different seed gives a different run map", () => {
  const a = warpathRun(payload({}))!;
  const b = warpathRun(payload({ seed: 777 }))!;
  expect(a.steps).not.toEqual(b.steps);
});

test("installed content does not move the run map", () => {
  // The same load-bearing claim as the conquest preview. Maps and a build
  // graph change what is inside a node, never how many nodes there are or how
  // they join up, so the hub can draw the map with neither.
  const withContent = generateRun({
    seed: 4242,
    length: "standard",
    difficulty: 3,
    ascension: 0,
    game: { shortname: "ba" },
    factionId: "arm",
    side: "Arm",
    skin: "galaxy",
    maps: Array.from({ length: 40 }, (_, i) => ({
      name: `Map ${i}`,
      size: 100 + i * 50,
    })),
    build: buildGraph,
    enemyAiKey: "skirmish:BARb",
    loadoutBranch: 1,
    now: "",
  });

  const drawn = warpathRun(payload({}))!;
  expect(drawn.steps).toHaveLength(withContent.nodes.length);
  expect(drawn.steps.map((s) => s.type)).toEqual(
    withContent.nodes.map((n) => n.type),
  );
  // Route for route, not just route count.
  const at = new Map(withContent.nodes.map((n, i) => [n.id, i]));
  expect(drawn.routes).toEqual(
    withContent.edges.map(([a, b]) => [at.get(a)!, at.get(b)!]),
  );
});

test("length sets the column count, matching coilbox's own table", () => {
  expect(warpathRun(payload({ length: "quick" }))?.columns).toBe(6);
  expect(warpathRun(payload({ length: "standard" }))?.columns).toBe(9);
  expect(warpathRun(payload({ length: "long" }))?.columns).toBe(13);
});

test("an unknown length falls back to standard rather than failing", () => {
  // Matches what coilbox does, so a challenge from a newer build still draws
  // the run that build will generate.
  expect(warpathRun(payload({ length: "marathon" }))).toEqual(
    warpathRun(payload({ length: "standard" }))!,
  );
});

test("a run starts at one node and ends at one boss", () => {
  const shape = warpathRun(payload({ length: "long" }))!;
  expect(shape.steps.filter((s) => s.type === "start")).toHaveLength(1);
  expect(shape.steps.filter((s) => s.type === "boss")).toHaveLength(1);
  // The start is hard left and the boss hard right, since routes only go
  // forward and the layout is keyed on the column.
  expect(shape.steps.find((s) => s.type === "start")!.x).toBe(0);
  expect(shape.steps.find((s) => s.type === "boss")!.x).toBe(1);
});

test("every node is reachable and leads somewhere, so there are no dead ends", () => {
  const shape = warpathRun(payload({ length: "long" }))!;
  const boss = shape.steps.findIndex((s) => s.type === "boss");
  const start = shape.steps.findIndex((s) => s.type === "start");
  const outgoing = new Set(shape.routes.map(([a]) => a));
  const incoming = new Set(shape.routes.map(([, b]) => b));
  shape.steps.forEach((_, i) => {
    if (i !== boss) expect(outgoing.has(i)).toBe(true);
    if (i !== start) expect(incoming.has(i)).toBe(true);
  });
});

test("routes only ever go forward", () => {
  const shape = warpathRun(payload({ length: "long" }))!;
  for (const [a, b] of shape.routes) {
    expect(shape.steps[a].x).toBeLessThan(shape.steps[b].x);
  }
});

test("nodes are laid out inside the unit square", () => {
  for (const length of ["quick", "standard", "long"]) {
    const shape = warpathRun(payload({ length }))!;
    for (const s of shape.steps) {
      expect(s.x).toBeGreaterThanOrEqual(0);
      expect(s.x).toBeLessThanOrEqual(1);
      expect(s.y).toBeGreaterThanOrEqual(0);
      expect(s.y).toBeLessThanOrEqual(1);
    }
  }
});

test("a conquest challenge has no run map to draw", () => {
  expect(
    warpathRun({
      mode: "conquest",
      settings: { seed: 1, nodeCount: 20, factionCount: 2 },
    }),
  ).toBeNull();
});

test("a payload coilbox itself would reject degrades to nothing", () => {
  expect(warpathRun({})).toBeNull();
  expect(warpathRun({ mode: "warpath" })).toBeNull();
  expect(warpathRun({ mode: "warpath", settings: null })).toBeNull();
  expect(warpathRun(payload({ game: undefined }))).toBeNull();
  expect(warpathRun(payload({ game: { shortname: "" } }))).toBeNull();
  expect(warpathRun(payload({ factionId: "" }))).toBeNull();
});

test("a missing seed reads as zero, the way coilbox reads it", () => {
  expect(warpathRun(payload({ seed: undefined }))).toEqual(
    warpathRun(payload({ seed: 0 }))!,
  );
});
