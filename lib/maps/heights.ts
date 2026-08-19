/**
 * Turning a height overlay's pixels back into elmos (#194).
 *
 * Arithmetic and nothing else. No database, no three.js and no browser API, so
 * the browser chunk that draws the terrain can import it without dragging the
 * resolver in behind it, and a test can check the numbers without a canvas.
 *
 * ## The range is the picture's, not the map's
 *
 * `public.asset.world_height_min` and `world_height_max` are documented as the
 * elmo height this picture's darkest and brightest samples mean. #181 rescales
 * each stored overlay into the window that map's own samples occupy, so the pair
 * on the row is what decodes that particular file and nothing else does.
 *
 * `public.map.world_height_min` and `world_height_max` are the terrain's own
 * span. They are different numbers, they are right there on the facts the page
 * already has, and decoding with them gives terrain that looks plausible and is
 * wrong. Whoever comes to this next will reach for the catalog's pair, which is
 * why this is said twice.
 *
 * ## Eight bits is what there is
 *
 * A browser flattens any image to eight bits a channel on decode, so 256 steps
 * across the whole vertical range is what a preview reading through an `<img>`
 * was always going to get. A map with two thousand elmos of relief lands about
 * eight elmos to the step, which terraces visibly on bare terrain, so
 * {@link smoothHeights} is part of decoding rather than a polish pass. Anything
 * wanting real heights reads the map archive locally, where they have always
 * been.
 */

/** What a picture's darkest and brightest samples mean, in elmos. */
export interface HeightRange {
  min: number;
  max: number;
}

/** The largest value an eight bit sample takes, which is what a sample is a
 *  fraction of. Named because 256 is the count and 255 is the divisor, and
 *  writing the count is the off by one this file exists to avoid. */
export const MAX_SAMPLE = 255;

/**
 * What one sample means, in elmos.
 *
 * Written as a weighted sum of the two ends rather than as
 * `min + t * (max - min)`, which is the same value in real arithmetic and not in
 * floating point. The subtracting form leaves the brightest sample at
 * `min + (max - min)`, which for a range like 0.1 to 0.3 is not 0.3, so the
 * highest ground on the map comes out slightly under the height the archive
 * declared. This form gives exactly `min` at 0 and exactly `max` at 255, because
 * one weight is exactly zero at each end.
 */
export function sampleToElmos(sample: number, range: HeightRange): number {
  const t = sample / MAX_SAMPLE;
  return range.min * (1 - t) + range.max * t;
}

/**
 * The red channel of decoded image data, as elmos, row by row from the top.
 *
 * Red rather than a luminance of the three channels. The overlay is grey, and
 * WebP has no greyscale mode, so #181 ships it as RGB with the three channels
 * equal. Averaging them would be arithmetic that can only lose, and a file that
 * somehow arrived with unequal channels is not one this can rescue.
 */
export function decodeHeights(
  rgba: Uint8ClampedArray,
  range: HeightRange,
): Float32Array {
  const out = new Float32Array(rgba.length / 4);
  for (let i = 0; i < out.length; i++) out[i] = sampleToElmos(rgba[i * 4], range);
  return out;
}

/** A binomial blur of radius two, which is close enough to a gaussian of about
 *  one sample and is four adds and a shift per tap. */
const KERNEL = [1, 4, 6, 4, 1];
const KERNEL_SUM = 16;

/** One separable pass along a row or a column, clamping at the edges so the
 *  border of the map keeps its own height rather than being pulled towards
 *  nothing. */
function blurAxis(
  source: Float32Array,
  width: number,
  height: number,
  along: "x" | "y",
): Float32Array {
  const out = new Float32Array(source.length);
  const limit = along === "x" ? width : height;

  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      let total = 0;
      for (let k = 0; k < KERNEL.length; k++) {
        const offset = k - 2;
        const at = Math.min(limit - 1, Math.max(0, (along === "x" ? x : y) + offset));
        total += KERNEL[k] * source[along === "x" ? y * width + at : at * width + x];
      }
      out[y * width + x] = total / KERNEL_SUM;
    }
  }

  return out;
}

/**
 * The same field with the eight bit steps taken out of it.
 *
 * Quantisation is what terraces, and no amount of filtering between samples
 * fixes it: a gentle slope arrives as runs of identical samples, and
 * interpolating between two equal neighbours gives a flat plateau with a hard
 * edge where the run changes. Shading turns those edges into contour rings. A
 * low pass over the decoded field turns each run into a ramp, which is what the
 * terrain was before it was rounded to a byte.
 *
 * Two separable passes rather than one 5 x 5, which is the same result for a
 * tenth of the multiplies. The blur is about one sample wide, and the overlay is
 * capped at 512 pixels on the long edge, so it costs detail the mesh could not
 * draw anyway.
 */
export function smoothHeights(
  heights: Float32Array,
  width: number,
  height: number,
): Float32Array {
  return blurAxis(blurAxis(heights, width, height, "x"), width, height, "y");
}
