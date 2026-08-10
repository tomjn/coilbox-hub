import { expect, test } from "bun:test";
import type { BarMap } from "./maps";
import { pickBoxSet, startLayout, startPosLabel } from "./startLayout";
import testMaps from "./testMaps.json";

const maps = testMaps as BarMap[];
const map = (springName: string) =>
  maps.find((m) => m.springName === springName) as BarMap;

/** Three sets: four boxes of one, two of two, three of one. */
const acidicQuarry = map("AcidicQuarry 5.17");
/** Two boxes of eight, and the only fixture with named spawn points. */
const glitters = map("All That Glitters v2.2.3");
/** Two boxes of four and four boxes of one, so nothing seats a big team. */
const comet = map("Comet Catcher Remake 1.8");

test("the box set matches the number of teams", () => {
  expect(pickBoxSet(acidicQuarry, 3, 1)?.startboxes).toHaveLength(3);
  expect(pickBoxSet(acidicQuarry, 4, 1)?.startboxes).toHaveLength(4);
});

test("a team count the map has no set for draws nothing", () => {
  expect(pickBoxSet(acidicQuarry, 5, 1)).toBeNull();
  expect(startLayout(acidicQuarry, 5, 1).boxes).toEqual([]);
});

test("the tightest set that seats the biggest team wins", () => {
  expect(pickBoxSet(acidicQuarry, 2, 2)?.maxPlayersPerStartbox).toBe(2);
});

test("when no set seats the team, the roomiest is the closest thing", () => {
  expect(pickBoxSet(comet, 2, 8)?.maxPlayersPerStartbox).toBe(4);
});

test("boxes come out as fractions of the map, corners in either order", () => {
  const [first] = startLayout(acidicQuarry, 4, 1).boxes;
  // The first box of the four is 15,15 to 60,60 on BAR's 0..200 grid.
  expect(first.left).toBeCloseTo(0.075, 6);
  expect(first.top).toBeCloseTo(0.075, 6);
  expect(first.width).toBeCloseTo(0.225, 6);
  expect(first.height).toBeCloseTo(0.225, 6);
});

test("a two sided battle of the size BAR described gets its spawn points", () => {
  const { dots } = startLayout(glitters, 2, 8);
  expect(dots).toHaveLength(16);
  expect(dots.filter((d) => d.side === 0)).toHaveLength(8);
  // P1 is 668,755 on a map 12 by 20 units of 512 elmos.
  expect(dots[0].x).toBeCloseTo(668 / 6144, 5);
  expect(dots[0].y).toBeCloseTo(755 / 10240, 5);
  expect(dots[0].role).toBe("front");
});

test("a team size BAR never described gets no spawn points", () => {
  expect(startLayout(glitters, 2, 4).dots).toEqual([]);
  expect(startLayout(glitters, 3, 8).dots).toEqual([]);
});

test("a map with no spawn points still gets its boxes", () => {
  const { boxes, dots } = startLayout(comet, 2, 4);
  expect(boxes).toHaveLength(2);
  expect(dots).toEqual([]);
});

test("the start mode reads back in coilbox's own words", () => {
  expect(startPosLabel(0)).toBe("Fixed map start positions");
  expect(startPosLabel(1)).toBe("Random start positions");
  expect(startPosLabel(2)).toBe("Players choose in game");
  // A mode from a newer coilbox is not described rather than guessed at.
  expect(startPosLabel(4)).toBeNull();
  expect(startPosLabel(undefined)).toBeNull();
});
