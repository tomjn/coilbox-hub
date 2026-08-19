import { expect, test } from "bun:test";
import { decodeHeights, MAX_SAMPLE, sampleToElmos, smoothHeights } from "./heights";

/** Comet Catcher's own pair, which straddles sea level, because a range that
 *  starts at zero hides a sign mistake. */
const COMET = { min: -120.5, max: 890 };

/**
 * The two ends are where an off by one in the 0 to 255 mapping shows, and only
 * there: dividing by 256 puts the middle of the range within a fifth of a
 * percent of right and leaves the brightest sample four elmos short of the
 * summit.
 */
test("the darkest sample is the bottom of the range and the brightest is the top", () => {
  expect(sampleToElmos(0, COMET)).toBe(-120.5);
  expect(sampleToElmos(MAX_SAMPLE, COMET)).toBe(890);
});

/** Exactly, not nearly. `min + (max - min)` is 0.29999999999999993 on this pair,
 *  so the form the arithmetic is written in is the thing under test. */
test("both ends are exact on a range floating point cannot round trip", () => {
  expect(sampleToElmos(0, { min: 0.1, max: 0.3 })).toBe(0.1);
  expect(sampleToElmos(MAX_SAMPLE, { min: 0.1, max: 0.3 })).toBe(0.3);
});

test("a sample in between is the fraction of the range it stands for", () => {
  expect(sampleToElmos(51, { min: 0, max: 1000 })).toBeCloseTo(200, 6);
  expect(sampleToElmos(128, COMET)).toBeCloseTo(386.73, 2);
});

/** A flat map has one height, which the table allows, and every sample in it
 *  means that height rather than a division by zero. */
test("a range whose ends meet reads flat at every sample", () => {
  const flat = { min: 42, max: 42 };

  expect(sampleToElmos(0, flat)).toBe(42);
  expect(sampleToElmos(128, flat)).toBe(42);
  expect(sampleToElmos(MAX_SAMPLE, flat)).toBe(42);
});

/** The overlay is grey stored as RGB, so red is the sample and the alpha the
 *  canvas hands back is not part of it. */
test("the red channel is the height and the rest of the pixel is not", () => {
  const rgba = new Uint8ClampedArray([0, 9, 9, 255, MAX_SAMPLE, 9, 9, 255]);

  expect([...decodeHeights(rgba, COMET)]).toEqual([-120.5, 890]);
});

/**
 * The terracing test. A gentle slope quantised to bytes arrives as runs of
 * equal samples, and the step between two runs is the artefact. After the low
 * pass the field still climbs the same total but no longer does it in one jump.
 */
test("the step between two runs of equal samples becomes a ramp", () => {
  const width = 8;
  const step = new Float32Array(width);
  for (let i = 0; i < width; i++) step[i] = i < 4 ? 0 : 8;

  const smoothed = smoothHeights(step, width, 1);
  const jumps = [...smoothed].slice(1).map((value, i) => value - smoothed[i]);

  expect(Math.max(...jumps)).toBeLessThan(8);
  // Still a slope, and still in the same direction.
  expect(smoothed[0]).toBeLessThan(smoothed[width - 1]);
});

/** A field that is already flat must come back flat. A blur that leaked at the
 *  edges would tilt the border of every map towards nothing. */
test("flat ground stays flat, edges included", () => {
  const flat = new Float32Array(16).fill(-30);

  expect([...smoothHeights(flat, 4, 4)]).toEqual([...flat]);
});

/** Both axes, because a blur that only ran along rows would leave every
 *  horizontal contour edge exactly as it found it. */
test("the smoothing runs down the columns as well as along the rows", () => {
  const width = 1;
  const height = 8;
  const step = new Float32Array(height);
  for (let i = 0; i < height; i++) step[i] = i < 4 ? 0 : 8;

  const smoothed = smoothHeights(step, width, height);
  const jumps = [...smoothed].slice(1).map((value, i) => value - smoothed[i]);

  expect(Math.max(...jumps)).toBeLessThan(8);
});
