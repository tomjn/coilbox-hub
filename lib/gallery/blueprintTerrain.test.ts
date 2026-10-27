import { expect, test } from "bun:test";
import {
  BUILDING_GAP,
  blueprintBuildOrder,
  blueprintRoster,
  blueprintShape,
  planLabel,
  WALL_THICKNESS,
} from "./blueprintPreview";

/** A payload as coilbox writes one, with only the fields the shape reads. */
function payload(fields: Record<string, unknown>) {
  return { name: "A layout", buildings: [], footprints: {}, ...fields };
}

const AT_ORIGIN = { offset: { x: 0, z: 0 }, facing: 0 };

const WALL = {
  kind: "raise",
  shape: "wall",
  points: [
    { x: 0, z: 0 },
    { x: 160, z: 0 },
  ],
  height: 16,
  before: 0,
};

test("a layout of walls and no buildings still has a shape", () => {
  const shape = blueprintShape(payload({ terrain: [WALL] }));

  expect(shape).not.toBeNull();
  expect(shape!.squares).toEqual([]);
  expect(shape!.terrain).toHaveLength(1);
  // 160 elmos is 10 build squares, plus the thickness of the wall at each end.
  expect(shape!.width).toBeCloseTo(10 + WALL_THICKNESS);
  expect(shape!.height).toBeCloseTo(WALL_THICKNESS);
});

test("a layout with neither buildings nor readable steps has no shape", () => {
  expect(blueprintShape(payload({}))).toBeNull();
  expect(blueprintShape(payload({ terrain: [{ kind: "melt" }] }))).toBeNull();
});

test("the frame covers steps as well as buildings", () => {
  const shape = blueprintShape(
    payload({
      buildings: [{ def: "armsolar", ...AT_ORIGIN }],
      terrain: [
        {
          kind: "level",
          shape: "area",
          points: [
            { x: -80, z: -80 },
            { x: 80, z: -80 },
            { x: 80, z: 80 },
          ],
          height: 0,
          before: 0,
        },
      ],
    }),
  );

  expect(shape!.width).toBeCloseTo(10);
  expect(shape!.height).toBeCloseTo(10);
  // The building stands in the middle of the pad, not in a corner of the box.
  expect(shape!.squares[0].x).toBeCloseTo(5 - 0.5 + BUILDING_GAP);
});

test("raised ground and dug ground take different tones", () => {
  const shape = blueprintShape(
    payload({
      terrain: [WALL, { ...WALL, height: -16 }, { ...WALL, kind: "smooth" }],
    }),
  );

  expect(shape!.terrain.map((step) => step.tone)).toEqual([
    "up",
    "down",
    "even",
  ]);
});

test("a ramp is a band with an arrow pointing uphill", () => {
  const ramp = (heights: [number, number]) =>
    blueprintShape(
      payload({
        terrain: [
          {
            kind: "ramp",
            shape: "wall",
            points: [
              { x: 0, z: 0 },
              { x: 80, z: 0 },
            ],
            heights,
            width: 32,
            before: 0,
          },
        ],
      }),
    )!.terrain[0];

  const up = ramp([0, 20]);
  expect(up.band).toHaveLength(4);
  // 32 elmos across is 2 build squares, so the band is 2 tall.
  const ys = up.band!.map((corner) => corner.y);
  expect(Math.max(...ys) - Math.min(...ys)).toBeCloseTo(2);
  // The tip is further along the ramp than the tail when the high end is last.
  expect(up.arrow![1].x).toBeGreaterThan(up.arrow![0].x);

  const down = ramp([20, 0]);
  expect(down.arrow![1].x).toBeLessThan(down.arrow![0].x);

  expect(ramp([5, 5]).arrow).toBeNull();
});

test("the plan label counts steps, and needs no buildings", () => {
  expect(planLabel(blueprintShape(payload({ terrain: [WALL, WALL] }))!)).toBe(
    "2 terrain steps over 11 by 1 build squares",
  );
  expect(planLabel(blueprintShape(payload({ terrain: [WALL] }))!)).toBe(
    "1 terrain step over 11 by 1 build squares",
  );
});

test("the plan label names buildings and steps together", () => {
  const shape = blueprintShape(
    payload({
      buildings: [{ def: "armsolar", ...AT_ORIGIN }],
      terrain: [WALL],
    }),
  )!;

  expect(planLabel(shape)).toContain("1 building and 1 terrain step over");
});

test("a layout with only steps has no roster and no build order", () => {
  const only = payload({ ordered: true, terrain: [WALL] });
  expect(blueprintRoster(only)).toEqual([]);
  expect(blueprintBuildOrder(only)).toBeNull();
});
