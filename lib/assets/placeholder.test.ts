import { expect, test } from "bun:test";
import type { AssetIdentity } from "./asset";
import {
  type MissingPicture,
  missingPicture,
  placeholderBox,
  placeholderLabel,
  placeholderMeasure,
} from "./placeholder";

const UNIT: AssetIdentity = {
  keyedOn: "unit",
  game: "bar",
  unitName: "armsolar",
  variant: "buildpic",
};

const MAP: AssetIdentity = {
  keyedOn: "map",
  mapName: "Comet Catcher Remake 1.8",
  variant: "minimap",
};

test("a unit placeholder is named for the unit and a map one for the map", () => {
  expect(missingPicture(UNIT, { width: 4, height: 4 })).toEqual({
    name: "armsolar",
    keyedOn: "unit",
    footprint: { width: 4, height: 4 },
  });

  expect(missingPicture(MAP, null)).toEqual({
    name: "Comet Catcher Remake 1.8",
    keyedOn: "map",
    footprint: null,
  });
});

test("a footprint becomes a box whose longer side is 100", () => {
  expect(placeholderBox({ width: 4, height: 2 })).toEqual({ width: 100, height: 50 });
  expect(placeholderBox({ width: 2, height: 4 })).toEqual({ width: 50, height: 100 });
  expect(placeholderBox({ width: 3, height: 3 })).toEqual({ width: 100, height: 100 });
});

/** The same shape whatever the numbers are counted in, so one set of strokes and
 * corners draws a 4 by 4 building and a 24 by 24 map. */
test("two footprints of the same proportions produce the same box", () => {
  expect(placeholderBox({ width: 4, height: 4 })).toEqual(
    placeholderBox({ width: 24, height: 24 }),
  );
});

test("no footprint draws a square rather than refusing to draw", () => {
  expect(placeholderBox(null)).toEqual({ width: 100, height: 100 });
});

/**
 * The footprint comes from a payload or a map list rather than from the hub, so
 * a zero, a negative or a NaN is reachable, and every one of them puts a broken
 * `viewBox` on the page.
 */
test("a footprint no box can be made from falls back to a square", () => {
  expect(placeholderBox({ width: 0, height: 4 })).toEqual({ width: 100, height: 100 });
  expect(placeholderBox({ width: 4, height: -1 })).toEqual({ width: 100, height: 100 });
  expect(placeholderBox({ width: Number.NaN, height: 4 })).toEqual({ width: 100, height: 100 });
  expect(placeholderBox({ width: 4, height: Number.POSITIVE_INFINITY })).toEqual({
    width: 100,
    height: 100,
  });
});

/** Nothing real reaches eight to one, so this only ever fires on a value that
 * would otherwise draw a line one pixel tall. */
test("an absurd proportion is held to something still visible", () => {
  expect(placeholderBox({ width: 1000, height: 1 })).toEqual({ width: 100, height: 12.5 });
  expect(placeholderBox({ width: 1, height: 1000 })).toEqual({ width: 12.5, height: 100 });
});

test("a unit names its units, since build squares mean nothing unsaid", () => {
  expect(placeholderMeasure(missingPicture(UNIT, { width: 4, height: 4 }))).toBe(
    "4 by 4 build squares",
  );
});

/** "12 by 12" is how BAR, the lobby and every player names a map size, and a
 * noun appended to it would be one the hub invented. */
test("a map is measured the way BAR names a map", () => {
  expect(placeholderMeasure(missingPicture(MAP, { width: 12, height: 12 }))).toBe("12 by 12");
});

test("no footprint means nothing to measure", () => {
  expect(placeholderMeasure(missingPicture(UNIT, null))).toBeNull();
  expect(placeholderMeasure(missingPicture(UNIT, { width: 0, height: 4 }))).toBeNull();
});

test("a fraction off a payload is rounded rather than reported", () => {
  expect(placeholderMeasure(missingPicture(UNIT, { width: 4.04, height: 2.96 }))).toBe(
    "4 by 3 build squares",
  );
});

/** The whole point of the label: somebody who cannot see the drawing learns the
 * hub has no picture, not that there is a dashed rectangle. */
test("the label says there is no picture, and then what is known instead", () => {
  expect(placeholderLabel(missingPicture(UNIT, { width: 4, height: 4 }))).toBe(
    "No picture of armsolar yet, which stands on 4 by 4 build squares",
  );

  expect(placeholderLabel(missingPicture(MAP, { width: 12, height: 12 }))).toBe(
    "No picture of Comet Catcher Remake 1.8 yet, a 12 by 12 map",
  );
});

test("the label still says it with nothing else to say", () => {
  const picture: MissingPicture = missingPicture(MAP, null);

  expect(placeholderLabel(picture)).toBe("No picture of Comet Catcher Remake 1.8 yet");
});
