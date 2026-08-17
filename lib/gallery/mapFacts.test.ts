import { expect, test } from "bun:test";
import type { BarMap } from "@/lib/bar/maps";
import { mapFactsLabel, mapSizeLabel, mapTeamShapes } from "./mapFacts";

/** BAR's own entry for this map, cut down to what the caption reads. */
const GLITTERS = {
  springName: "All That Glitters v2.2.3",
  displayName: "All That Glitters",
  mapWidth: 12,
  mapHeight: 20,
  startboxesSet: [{ maxPlayersPerStartbox: 8, startboxes: [{ poly: [] }, { poly: [] }] }],
} as BarMap;

/** A map BAR lays out two ways: four a side, or a free for all of four. */
const COMET = {
  springName: "Comet Catcher Remake 1.8",
  displayName: "Comet Catcher Remake",
  mapWidth: 16,
  mapHeight: 12,
  startboxesSet: [
    { maxPlayersPerStartbox: 4, startboxes: [{ poly: [] }, { poly: [] }] },
    {
      maxPlayersPerStartbox: 1,
      startboxes: [{ poly: [] }, { poly: [] }, { poly: [] }, { poly: [] }],
    },
  ],
} as BarMap;

test("size is counted in the 512 elmo squares players quote", () => {
  expect(mapSizeLabel(GLITTERS)).toBe("12 x 20");
});

test("a box set is named by how many boxes it has and how many fit in one", () => {
  expect(mapTeamShapes(GLITTERS)).toEqual(["8v8"]);
  expect(mapTeamShapes(COMET)).toEqual(["4v4", "1v1v1v1"]);
});

test("a map BAR does not list says nothing rather than guessing", () => {
  expect(mapSizeLabel(null)).toBeNull();
  expect(mapTeamShapes(null)).toEqual([]);
  expect(mapFactsLabel(null)).toBeNull();
});

test("a map BAR lists without boxes or a size still says what it can", () => {
  const bare = { springName: "x", displayName: "x" } as BarMap;
  expect(mapFactsLabel(bare)).toBeNull();
  expect(mapFactsLabel({ ...bare, mapWidth: 8, mapHeight: 8 })).toBe("8 x 8");
});

test("the caption is size and every shape, in BAR's order", () => {
  expect(mapFactsLabel(COMET)).toBe("16 x 12 · 4v4 · 1v1v1v1");
});
