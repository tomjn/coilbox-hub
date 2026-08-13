import { expect, test } from "bun:test";
import {
  type BlueprintShape,
  BUILDING_GAP,
  blueprintShape,
  blueprintSheet,
  planLabel,
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

/** The box the item page draws a plan in. */
const PAGE = { width: 448, height: 336 };

test("the sheet covers the whole box it is drawn in, at one scale", () => {
  const sheet = blueprintSheet(sized(21, 18), PAGE);

  expect(sheet.width * sheet.scale).toBeCloseTo(PAGE.width);
  expect(sheet.height * sheet.scale).toBeCloseTo(PAGE.height);
});

test("the sheet centres the layout on it", () => {
  const sheet = blueprintSheet(sized(21, 18), PAGE);

  expect(sheet.left).toBeCloseTo(-(sheet.width - 21) / 2);
  expect(sheet.top).toBeCloseTo(-(sheet.height - 18) / 2);
});

test("the sheet keeps a build square of clear ground on every side", () => {
  for (const [across, down] of [
    [21, 18],
    [3, 3],
    [60, 8],
  ]) {
    const sheet = blueprintSheet(sized(across, down), PAGE);

    expect(sheet.left).toBeLessThanOrEqual(-1);
    expect(sheet.top).toBeLessThanOrEqual(-1);
    expect(sheet.left + sheet.width).toBeGreaterThanOrEqual(across + 1);
    expect(sheet.top + sheet.height).toBeGreaterThanOrEqual(down + 1);
  }
});

test("every build square is ruled, so every footprint edge lands on a rule", () => {
  // A grid of every second square left the five square solar collectors of
  // "Opening solars" straddling the rules (tomjn/coilbox#1508).
  const sheet = blueprintSheet(sized(21, 18), PAGE);

  expect(sheet.verticals).toEqual(
    sheet.verticals.map((_, i) => sheet.verticals[0] + i),
  );
  for (const edge of [0, 5, 16, 21]) expect(sheet.verticals).toContain(edge);
  for (const edge of [0, 5, 13, 18]) expect(sheet.horizontals).toContain(edge);
});

test("the rules are counted off the layout's own origin", () => {
  const sheet = blueprintSheet(sized(21, 18), PAGE);

  for (const at of [...sheet.verticals, ...sheet.horizontals]) {
    expect(Number.isInteger(at)).toBe(true);
  }
  expect(sheet.verticals[0]).toBeGreaterThanOrEqual(sheet.left);
  expect(sheet.verticals[0] - 1).toBeLessThan(sheet.left);
});

test("a base too big to draw a build square of is not ruled at all", () => {
  const sheet = blueprintSheet(sized(600, 400), PAGE);

  expect(sheet.verticals).toEqual([]);
  expect(sheet.horizontals).toEqual([]);
  expect(sheet.left).toBeCloseTo(-(sheet.width - 600) / 2);
});

test("a small layout is not blown up to fill the box", () => {
  const sheet = blueprintSheet(sized(1, 1), PAGE);

  expect(sheet.scale).toBe(16);
  expect(sheet.width).toBeCloseTo(448 / 16);
});

/** A layout of `buildings` single squares, over a sheet of the given size. */
function counted(
  buildings: number,
  width = 5,
  height = 5,
): BlueprintShape {
  return {
    width,
    height,
    ordered: false,
    squares: Array.from({ length: buildings }, (_, i) => ({
      def: "armsolar",
      sized: true,
      x: i,
      y: 0,
      width: 1,
      height: 1,
    })),
  };
}

test("the plan label counts one building in the singular", () => {
  expect(planLabel(counted(1))).toBe("1 building over 5 by 5 build squares");
});

test("the plan label counts more than one in the plural", () => {
  expect(planLabel(counted(4))).toBe("4 buildings over 5 by 5 build squares");
});

test("the plan label rounds sides a footprint gap leaves fractional", () => {
  expect(planLabel(counted(2, 5.76, 3.24))).toBe(
    "2 buildings over 6 by 3 build squares",
  );
});
