import { expect, test } from "bun:test";
import { readImageHeader } from "./imageHeader";

/**
 * Real encoder output, not headers written by hand from the same reading of the
 * specification the parser was written from. Each of these came out of libwebp
 * or libpng through `sharp`, which is what the coilbox side encodes with, so a
 * misread offset shows up here rather than on a real upload.
 *
 * `sharp` itself is not a dependency and is deliberately not imported. These are
 * its output, frozen.
 */
const SAMPLES = {
  /** 3x2, lossy, no alpha. A plain `VP8 ` file. */
  lossyWebp: "UklGRjoAAABXRUJQVlA4IC4AAAAQAgCdASoDAAIAAUAmJaACdLoB+AH4AAPIAP7udn/+oLQ18vxov/U4MHPn4/wA",
  /** 4x4, lossless. A plain `VP8L` file. */
  losslessWebp: "UklGRh4AAABXRUJQVlA4TBEAAAAvA8AAAAdQvFIUuf+BiOh/AAA=",
  /** 5x3, lossy, with alpha. `VP8X`, then `ALPH`, then the image chunk, which
   * is the shape the dimensions and the lossless flag come from two different
   * places in. */
  extendedWebp:
    "UklGRl4AAABXRUJQVlA4WAoAAAAQAAAABAAAAgAAQUxQSAoAAAABB1DAiAhERP8DVlA4IC4AAAAQAgCdASoFAAMAAUAmJaACdLoB+AH4AAPIAP7udn/+oLQ18vxov/U4MHPn4/wA",
  /** 6x4, 16 bit grayscale. What a height overlay is. */
  gray16Png:
    "iVBORw0KGgoAAAANSUhEUgAAAAYAAAAEEAAAAADY/83cAAAACXBIWXMAAAPoAAAD6AG1e1JrAAAAPUlEQVQImWNgYGBguHCBnX3BAn5+hoICcXEHB3l5AQF1dYYHD/T0NmwwM2tosLNjCAhwc1NQ8PP78CE0FABbaQ89PRRsdwAAAABJRU5ErkJggg==",
  /** 2x2, 8 bit RGB. A PNG that is not a height overlay. */
  rgb8Png:
    "iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAAPoAAAD6AG1e1JrAAAAEklEQVQImWMQqTghUnGCAUIBACJuBVHwJsa1AAAAAElFTkSuQmCC",
} as const;

function header(sample: keyof typeof SAMPLES) {
  return readImageHeader(Buffer.from(SAMPLES[sample], "base64"));
}

test("a lossy WebP reports its size and says it is lossy", () => {
  expect(header("lossyWebp")).toEqual({
    mime: "image/webp",
    width: 3,
    height: 2,
    lossless: false,
    bitDepth: 8,
    grayscale: false,
  });
});

test("a lossless WebP is told apart from a lossy one by its chunk, not its size", () => {
  const parsed = header("losslessWebp");
  expect(parsed?.width).toBe(4);
  expect(parsed?.height).toBe(4);
  expect(parsed?.lossless).toBe(true);
});

/**
 * The shape that breaks a parser that only ever reads the first chunk: the size
 * is on VP8X and whether it is lossless is on an image chunk two chunks later.
 */
test("an extended WebP takes its size from the canvas and its encoding from the image chunk", () => {
  expect(header("extendedWebp")).toEqual({
    mime: "image/webp",
    width: 5,
    height: 3,
    lossless: false,
    bitDepth: 8,
    grayscale: false,
  });
});

test("a 16 bit grayscale PNG reports both the depth and the colour", () => {
  expect(header("gray16Png")).toEqual({
    mime: "image/png",
    width: 6,
    height: 4,
    lossless: true,
    bitDepth: 16,
    grayscale: true,
  });
});

test("an 8 bit colour PNG is neither deep nor gray", () => {
  const parsed = header("rgb8Png");
  expect(parsed?.bitDepth).toBe(8);
  expect(parsed?.grayscale).toBe(false);
  expect(parsed?.lossless).toBe(true);
});

/**
 * Null rather than a guess, because every cap is a statement about pixels and
 * none of them can be applied to a file nobody can measure. Truncation matters
 * as much as the wrong format: the route hands over a slice of the body, so a
 * file shorter than its own header has to come back null rather than read past
 * the end of the buffer.
 */
test("anything that is not a measurable PNG or WebP comes back null", () => {
  expect(readImageHeader(Buffer.from("GIF89a" + "x".repeat(60)))).toBeNull();
  expect(readImageHeader(Buffer.from(""))).toBeNull();
  expect(readImageHeader(Buffer.from(SAMPLES.gray16Png, "base64").subarray(0, 20))).toBeNull();
  expect(readImageHeader(Buffer.from(SAMPLES.lossyWebp, "base64").subarray(0, 24))).toBeNull();
});

/** A RIFF file with no still image chunk at all, which is what an animation is.
 * The canvas is readable and the picture is not, so measuring the canvas would
 * accept one. */
test("a WebP with no image chunk is refused rather than measured off its canvas", () => {
  const animation = Buffer.from(SAMPLES.extendedWebp, "base64");
  animation.write("ANMF", 48, "latin1");

  expect(readImageHeader(animation)).toBeNull();
});
