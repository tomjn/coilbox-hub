import { expect, test } from "bun:test";
import {
  type BlueprintShape,
  BUILDING_GAP,
  blueprintShape,
  blueprintSheet,
} from "./blueprintPreview";

/** A payload as coilbox writes one, with only the fields the shape reads. */
function payload(fields: Record<string, unknown>) {
  return { name: "A layout", buildings: [], footprints: {}, ...fields };
}

const AT_ORIGIN = { offset: { x: 0, z: 0 }, facing: 0 };

test("a building stands on the ground its footprint says, not a uniform square", () => {
  const shape = blueprintShape(
    payload({
      buildings: [
        { def: "armsolar", ...AT_ORIGIN },
        { def: "armlab", offset: { x: 160, z: 0 }, facing: 0 },
      ],
      footprints: { armsolar: { x: 1, z: 1 }, armlab: { x: 2, z: 3 } },
    }),
  );

  const [solar, lab] = shape!.squares;
  expect(solar.width).toBeCloseTo(1 - BUILDING_GAP * 2);
  expect(solar.height).toBeCloseTo(1 - BUILDING_GAP * 2);
  expect(lab.width).toBeCloseTo(2 - BUILDING_GAP * 2);
  expect(lab.height).toBeCloseTo(3 - BUILDING_GAP * 2);
});

test("a def the payload says nothing about stands on one square", () => {
  // An export from an older or odd client carries what footprints it could
  // read. A building with none is still drawn, at the size the engine floors
  // every footprint to.
  const shape = blueprintShape(
    payload({ buildings: [{ def: "armsolar", ...AT_ORIGIN }] }),
  );

  expect(shape!.squares[0].width).toBeCloseTo(1 - BUILDING_GAP * 2);
  expect(shape!.width).toBeCloseTo(1);
});

test("east and west stand a building on its side, so its sides swap", () => {
  const shape = blueprintShape(
    payload({
      buildings: [{ def: "armlab", offset: { x: 0, z: 0 }, facing: 1 }],
      footprints: { armlab: { x: 2, z: 4 } },
    }),
  );

  expect(shape!.width).toBeCloseTo(4);
  expect(shape!.height).toBeCloseTo(2);
});

test("a layout laid out around its origin fits inside the box", () => {
  // Offsets are from the layout's origin, so half of them are negative. A box
  // measured from zero would put those buildings outside the picture.
  const shape = blueprintShape(
    payload({
      buildings: [
        { def: "armsolar", offset: { x: -320, z: -160 }, facing: 0 },
        { def: "armsolar", offset: { x: 320, z: 160 }, facing: 0 },
      ],
      footprints: { armsolar: { x: 2, z: 2 } },
    }),
  );

  for (const square of shape!.squares) {
    expect(square.x).toBeGreaterThanOrEqual(0);
    expect(square.y).toBeGreaterThanOrEqual(0);
    expect(square.x + square.width).toBeLessThanOrEqual(shape!.width);
    expect(square.y + square.height).toBeLessThanOrEqual(shape!.height);
  }
});

test("buildings keep the distance between them", () => {
  // 160 elmos is ten build squares, and two one-square buildings that far
  // apart leave nine squares of ground between them.
  const shape = blueprintShape(
    payload({
      buildings: [
        { def: "armsolar", ...AT_ORIGIN },
        { def: "armsolar", offset: { x: 160, z: 0 }, facing: 0 },
      ],
      footprints: { armsolar: { x: 1, z: 1 } },
    }),
  );

  const [near, far] = shape!.squares;
  expect(far.x - near.x).toBeCloseTo(10);
  expect(shape!.width).toBeCloseTo(11);
});

test("a gap is left around every building, so neighbours read as two", () => {
  // Two buildings side by side with no ground between them are what a base
  // actually looks like, and drawn true to size they would touch.
  const shape = blueprintShape(
    payload({
      buildings: [
        { def: "armsolar", ...AT_ORIGIN },
        { def: "armsolar", offset: { x: 16, z: 0 }, facing: 0 },
      ],
      footprints: { armsolar: { x: 1, z: 1 } },
    }),
  );

  const [left, right] = shape!.squares;
  expect(right.x - (left.x + left.width)).toBeCloseTo(BUILDING_GAP * 2);
});

test("the build order is only claimed when the layout says it means one", () => {
  const buildings = [{ def: "armsolar", ...AT_ORIGIN }];
  expect(blueprintShape(payload({ buildings }))!.ordered).toBe(false);
  expect(blueprintShape(payload({ buildings, ordered: true }))!.ordered).toBe(
    true,
  );
});

test("an empty or unreadable layout has no shape to draw", () => {
  expect(blueprintShape(payload({}))).toBeNull();
  expect(blueprintShape({ buildings: [] })).toBeNull();
  expect(blueprintShape(null)).toBeNull();
});

test("a building the payload never sized says so, rather than passing for one", () => {
  const shape = blueprintShape(
    payload({
      buildings: [
        { def: "armsolar", ...AT_ORIGIN },
        { def: "whatisthis", offset: { x: 32, z: 0 }, facing: 0 },
      ],
      footprints: { armsolar: { x: 1, z: 1 } },
    }),
  );

  expect(shape!.squares.map((s) => s.sized)).toEqual([true, false]);
});

/** A layout of one building, as many build squares across as asked for. */
function sized(width: number, height: number): BlueprintShape {
  return {
    width,
    height,
    ordered: false,
    squares: [{ def: "armlab", sized: true, x: 0, y: 0, width, height }],
  };
}

test("the sheet leaves a build square of clear ground on every side", () => {
  const sheet = blueprintSheet(sized(6, 4));

  expect([sheet.left, sheet.top]).toEqual([-1, -1]);
  expect([sheet.width, sheet.height]).toEqual([8, 6]);
});

test("the sheet is ruled in build squares while a base is small enough", () => {
  const sheet = blueprintSheet(sized(6, 4));

  expect(sheet.pitch).toBe(1);
  expect(sheet.verticals).toEqual([-1, 0, 1, 2, 3, 4, 5, 6, 7]);
  expect(sheet.horizontals).toEqual([-1, 0, 1, 2, 3, 4, 5]);
});

test("a base too big to rule singly coarsens the grid rather than crowding it", () => {
  // Seventeen squares across is one too many to rule singly, and sixty five is
  // one too many to rule in pairs.
  expect(blueprintSheet(sized(16, 3)).pitch).toBe(1);
  expect(blueprintSheet(sized(17, 3)).pitch).toBe(2);
  expect(blueprintSheet(sized(3, 30)).pitch).toBe(2);
  expect(blueprintSheet(sized(65, 3)).pitch).toBe(8);
});

test("the rules stay on build square boundaries at every pitch", () => {
  const sheet = blueprintSheet(sized(30, 30));

  expect(sheet.pitch).toBe(2);
  expect(sheet.verticals).toContain(0);
  for (const at of [...sheet.verticals, ...sheet.horizontals]) {
    expect(at % sheet.pitch).toBe(0);
    expect(at).toBeGreaterThanOrEqual(sheet.left);
    expect(at).toBeLessThanOrEqual(sheet.left + sheet.width);
  }
});
