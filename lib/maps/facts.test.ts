import { expect, test } from "bun:test";
import { canonicalEntry, canonicalJson, factsDigest, type MapEntry } from "./facts";

const ENTRY: MapEntry = {
  map_name: "Comet Catcher Remake 1.8",
  display_name: "Comet Catcher Remake",
  description: "A remake",
  map_version: "1.8",
  author: "Beherith & Icexuick",
  archive_filename: "comet_catcher_remake_1.8.sd7",
  source_archive: "Comet Catcher Remake 1.8",
  source_hash: "src-comet",
  catalog_version: 3,
  width_elmos: 6144,
  height_elmos: 10240,
  world_height_min: -120.5,
  world_height_max: 890,
  min_wind: 5,
  max_wind: 40,
  tidal_strength: 18,
  void_water: false,
  void_ground: false,
  water_coverage: 0.31,
  appearance: { water: { colour: [0, 0.2, 0.4] }, sun: { dir: [1, 1, 0] } },
  points: {
    start: [
      { x: 512, z: 512, y: null, meta: null },
      { x: 5632, z: 9728, y: null, meta: null },
    ],
    metal: [
      { x: 1024, z: 2048, y: null, meta: { amount: 2, radius: 48 } },
      { x: 3000, z: 400, y: 12.5, meta: { amount: 1.5, radius: 48 } },
    ],
    geo: [{ x: 3072, z: 4096, y: null, meta: { feature: "geovent" } }],
  },
};

/**
 * The whole point of the digest. Two clients reading one archive walk its Lua
 * tables in whatever order they please, and the key order in the JSON they send
 * says nothing about the map.
 */
test("the order an object's keys arrive in does not change the digest", async () => {
  const reordered: MapEntry = {
    ...ENTRY,
    appearance: { sun: { dir: [1, 1, 0] }, water: { colour: [0, 0.2, 0.4] } },
  };

  expect(await factsDigest(reordered)).toBe(await factsDigest(ENTRY));
});

/**
 * `map_point.ordinal` on a metal spot is "the order the archive listed them in,
 * which is stable but arbitrary", in the migration's own words, so an arbitrary
 * order is normalised away rather than treated as a fact.
 */
test("reordering the metal spots is the same map and the same digest", async () => {
  const reordered: MapEntry = {
    ...ENTRY,
    points: { ...ENTRY.points, metal: [...ENTRY.points.metal].reverse() },
  };

  expect(await factsDigest(reordered)).toBe(await factsDigest(ENTRY));
  expect(canonicalEntry(reordered).points.metal).toEqual(canonicalEntry(ENTRY).points.metal);
});

/**
 * The other half of the same rule, and the reason the sort is per kind rather
 * than over every point. A start position's ordinal is the team index and
 * carries meaning, so two clients listing them differently are describing
 * different spawns. Calling that the same facts would store one client's team
 * layout and refuse the other's forever.
 */
test("reordering the start positions is a different map, because the order is the fact", async () => {
  const swapped: MapEntry = {
    ...ENTRY,
    points: { ...ENTRY.points, start: [...ENTRY.points.start].reverse() },
  };

  expect(await factsDigest(swapped)).not.toBe(await factsDigest(ENTRY));
});

test("a moved metal spot is a different map", async () => {
  const moved: MapEntry = {
    ...ENTRY,
    points: {
      ...ENTRY.points,
      metal: [{ ...ENTRY.points.metal[0], x: 1025 }, ENTRY.points.metal[1]],
    },
  };

  expect(await factsDigest(moved)).not.toBe(await factsDigest(ENTRY));
});

test("a metal spot that keeps its place and loses its amount is a different map", async () => {
  const thinner: MapEntry = {
    ...ENTRY,
    points: {
      ...ENTRY.points,
      metal: [{ ...ENTRY.points.metal[0], meta: { amount: 1, radius: 48 } }, ENTRY.points.metal[1]],
    },
  };

  expect(await factsDigest(thinner)).not.toBe(await factsDigest(ENTRY));
});

/** Two spots at one coordinate differ only in their meta, so the tie break has
 * to be the rest of the point or the two would swap places between runs. */
test("two points at one coordinate sort by the rest of the point", () => {
  const stacked: MapEntry = {
    ...ENTRY,
    points: {
      ...ENTRY.points,
      metal: [
        { x: 100, z: 100, y: null, meta: { amount: 2 } },
        { x: 100, z: 100, y: null, meta: { amount: 1 } },
      ],
    },
  };

  const once = canonicalJson(canonicalEntry(stacked));
  const again = canonicalJson(
    canonicalEntry({
      ...stacked,
      points: { ...stacked.points, metal: [...stacked.points.metal].reverse() },
    }),
  );

  expect(once).toBe(again);
});

/** The same digest for the same map twice, which is the whole contract. */
test("the same entry twice is the same digest", async () => {
  expect(await factsDigest(ENTRY)).toBe(await factsDigest(ENTRY));
});

test("a digest is 64 lowercase hex characters, the shape the column holds", async () => {
  expect(await factsDigest(ENTRY)).toMatch(/^[0-9a-f]{64}$/);
});

test("a changed measurement is a changed digest", async () => {
  expect(await factsDigest({ ...ENTRY, water_coverage: 0.32 })).not.toBe(
    await factsDigest(ENTRY),
  );
});

/**
 * `890` and `890.0` are one double and one measurement, so a client that writes
 * its floats out with a trailing zero must not be told its map has changed.
 * `JSON.stringify` is what settles that, and it settles negative zero too.
 */
test("two spellings of one number are one digest", () => {
  expect(canonicalJson({ height: 890.0 })).toBe(canonicalJson({ height: 890 }));
  expect(canonicalJson({ height: -0 })).toBe(canonicalJson({ height: 0 }));
});

test("a null is written as null, so an absent field and an explicit one agree", () => {
  expect(canonicalJson({ min_wind: null })).toBe('{"min_wind":null}');
});

test("keys sort by code unit and arrays keep their order", () => {
  expect(canonicalJson({ b: 1, a: 2, A: 3 })).toBe('{"A":3,"a":2,"b":1}');
  expect(canonicalJson([3, 1, 2])).toBe("[3,1,2]");
});

test("nested objects are canonical too, since appearance is a blob of them", () => {
  expect(canonicalJson({ sun: { z: 1, a: 2 } })).toBe('{"sun":{"a":2,"z":1}}');
});
