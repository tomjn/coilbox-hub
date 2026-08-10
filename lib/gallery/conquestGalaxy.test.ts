import { expect, test } from "bun:test";
import { generateGalaxy } from "@/lib/conquest/generate";
import { conquestGalaxy } from "./conquestGalaxy";

const payload = (settings: Record<string, unknown>) => ({
  mode: "conquest",
  settings: {
    seed: 12345,
    game: { shortname: "ba" },
    title: "A Conquest",
    nodeCount: 24,
    factionCount: 2,
    layout: "spiral",
    skin: "galaxy",
    ...settings,
  },
});

test("the same seed gives the same graph on every call", () => {
  const a = conquestGalaxy(payload({}));
  const b = conquestGalaxy(payload({}));
  expect(a).toEqual(b);
});

test("a different seed gives a different graph", () => {
  const a = conquestGalaxy(payload({}));
  const b = conquestGalaxy(payload({ seed: 999 }));
  expect(a?.systems).not.toEqual(b!.systems);
});

test("installed content does not move the graph", () => {
  // The whole preview rests on this. The generator settles positions, lanes,
  // capitals and ownership before it reads maps or naming pools, so a machine
  // with content installed builds the same graph the hub builds with none.
  // If this ever fails, the hub is drawing a galaxy nobody will play.
  const knobs = {
    seed: 12345,
    game: { shortname: "ba" },
    nodeCount: 24,
    factionCount: 2,
    layout: "spiral" as const,
  };
  const now = "2020-01-01T00:00:00.000Z";
  const withContent = generateGalaxy(
    {
      ...knobs,
      maps: Array.from({ length: 17 }, (_, i) => ({
        name: `Map ${i}`,
        width: 8 + i,
        height: 8 + (i % 5),
      })),
      names: {
        starNames: ["Aa", "Bb", "Cc"],
        factionNames: ["Alpha", "Beta"],
        factions: [{ name: "Arm", color: "#ff0000", side: "Arm" }],
      },
    },
    now,
  );

  const drawn = conquestGalaxy(payload({}))!;
  expect(drawn.systems).toHaveLength(withContent.nodes.length);
  // Lane for lane, not just lane count. Node ids are positional, so a lane
  // between the same two systems is the same pair of indices either side.
  const at = new Map(withContent.nodes.map((n, i) => [n.id, i]));
  expect(drawn.lanes).toEqual(
    withContent.links.map(([a, b]) => [at.get(a)!, at.get(b)!]),
  );
  expect(drawn.systems.map((s) => s.capital)).toEqual(
    withContent.nodes.map((n) => n.kind === "capital"),
  );
  // Ownership is compared by faction position, since a game's lore factions
  // rename and recolour them without moving anyone.
  const factionIds = withContent.factions.map((f) => f.id);
  expect(drawn.systems.map((s) => s.faction)).toEqual(
    withContent.nodes.map((n) => {
      const at = factionIds.indexOf(n.owner);
      return at === -1 ? null : at;
    }),
  );
});

test("every layout draws, including the real-star catalogue", () => {
  for (const layout of [
    "scatter",
    "spiral",
    "clusters",
    "ring",
    "random",
    "realstars",
  ]) {
    const shape = conquestGalaxy(payload({ layout }));
    expect(shape, layout).not.toBeNull();
    expect(shape!.systems.length, layout).toBeGreaterThan(0);
    expect(shape!.lanes.length, layout).toBeGreaterThan(0);
  }
});

test("an unknown layout falls back to scatter rather than failing", () => {
  // Matches what coilbox does with a layout it does not recognise, so a
  // challenge from a newer build still draws the galaxy that build will draw.
  expect(conquestGalaxy(payload({ layout: "hexagons" }))).toEqual(
    conquestGalaxy(payload({ layout: "scatter" }))!,
  );
});

test("the real-star radius is bounded the way coilbox bounds it", () => {
  // The generator does not clamp radiusLy itself, so an out-of-range value
  // would read more of the catalogue here than the app would ever read.
  expect(conquestGalaxy(payload({ layout: "realstars", radiusLy: 500 }))).toEqual(
    conquestGalaxy(payload({ layout: "realstars", radiusLy: 25 }))!,
  );
});

test("starting systems change who owns what, so they are not ignored", () => {
  const lean = conquestGalaxy(payload({ startingSystems: 1 }))!;
  const full = conquestGalaxy(payload({}))!;
  const owned = (s: typeof lean) =>
    s.systems.filter((n) => n.faction !== null).length;
  expect(owned(lean)).toBeLessThan(owned(full));
  // Only ownership moves. The graph underneath is the same one.
  expect(lean.lanes).toEqual(full.lanes);
  expect(lean.systems.map((s) => s.x)).toEqual(full.systems.map((s) => s.x));
});

test("each faction holds exactly one capital, the player's first", () => {
  const shape = conquestGalaxy(payload({ factionCount: 3 }))!;
  const capitals = shape.systems.filter((s) => s.capital);
  expect(capitals).toHaveLength(4);
  expect(capitals.map((c) => c.faction).sort()).toEqual([0, 1, 2, 3]);
  expect(shape.factionColors).toHaveLength(4);
});

test("systems are fitted into the unit square without being stretched", () => {
  const shape = conquestGalaxy(payload({}))!;
  for (const s of shape.systems) {
    expect(s.x).toBeGreaterThanOrEqual(0);
    expect(s.x).toBeLessThanOrEqual(1);
    expect(s.y).toBeGreaterThanOrEqual(0);
    expect(s.y).toBeLessThanOrEqual(1);
  }
  // The larger of the two spans fills the square, so nothing is squashed.
  const spanX = Math.max(...shape.systems.map((s) => s.x)) - Math.min(...shape.systems.map((s) => s.x));
  const spanY = Math.max(...shape.systems.map((s) => s.y)) - Math.min(...shape.systems.map((s) => s.y));
  expect(Math.max(spanX, spanY)).toBeCloseTo(1, 5);
});

test("lanes point at systems that exist", () => {
  const shape = conquestGalaxy(payload({}))!;
  for (const [a, b] of shape.lanes) {
    expect(shape.systems[a]).toBeDefined();
    expect(shape.systems[b]).toBeDefined();
    expect(a).not.toBe(b);
  }
});

test("a warpath challenge has no galaxy to draw", () => {
  expect(
    conquestGalaxy({
      mode: "warpath",
      settings: { seed: 1, difficulty: 2, length: 3, factionId: "arm" },
    }),
  ).toBeNull();
});

test("a payload missing the knobs the generator needs degrades to nothing", () => {
  expect(conquestGalaxy({})).toBeNull();
  expect(conquestGalaxy({ mode: "conquest" })).toBeNull();
  expect(conquestGalaxy({ mode: "conquest", settings: null })).toBeNull();
  expect(conquestGalaxy(payload({ seed: "not a number" }))).toBeNull();
  expect(conquestGalaxy(payload({ seed: Number.NaN }))).toBeNull();
  expect(conquestGalaxy(payload({ nodeCount: undefined }))).toBeNull();
  expect(conquestGalaxy(payload({ factionCount: undefined }))).toBeNull();
});

test("out-of-range counts are clamped rather than refused", () => {
  // A newer coilbox could widen these. Clamping keeps a drawing on screen and
  // matches what the app itself does with the same payload.
  expect(conquestGalaxy(payload({ nodeCount: 5000 }))?.systems).toHaveLength(80);
  expect(conquestGalaxy(payload({ nodeCount: -3 }))?.systems).toHaveLength(8);
  const many = conquestGalaxy(payload({ factionCount: 99 }))!;
  expect(many.factionColors).toHaveLength(4);
});
