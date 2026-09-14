import { expect, test } from "bun:test";
import { describeConversion, fitWithinBox, IMAGE_TARGETS, planImageUpload, QUALITY_STEPS, targetFormat } from "./imageResize";

// The pure maths and messages behind #356 and #359. `convertImageForUpload`
// itself needs a real canvas and decoder, so it is exercised by hand in a
// browser, not here - see the PR for that record.

test("a picture already inside its box is left at its own size", () => {
  expect(fitWithinBox(64, 64, 128, 128)).toEqual({ width: 64, height: 64 });
  expect(fitWithinBox(128, 128, 128, 128)).toEqual({ width: 128, height: 128 });
});

test("a picture wider than its box is scaled down, preserving aspect ratio", () => {
  expect(fitWithinBox(4000, 3000, 2048, 448)).toEqual({ width: Math.round(448 * (4000 / 3000)), height: 448 });
});

test("a picture taller than it is wide fits by its own longer edge", () => {
  expect(fitWithinBox(3000, 4000, 128, 128)).toEqual({ width: 96, height: 128 });
});

test("a banner wider than its box but already short enough fits by width", () => {
  expect(fitWithinBox(5000, 300, 2048, 448)).toEqual({ width: 2048, height: Math.round(300 * (2048 / 5000)) });
});

test("logo and banner boxes are doubled for a 2x screen from the CSS sizes on the pages that draw them", () => {
  // Logo: 64px (`h-16 w-16`, app/games/[shortname]/page.tsx) doubled is 128,
  // matching the 128px the link preview draws flat
  // (app/games/[shortname]/opengraph-image.tsx).
  expect(IMAGE_TARGETS.logo).toEqual({ maxWidth: 128, maxHeight: 128 });
  // Banner: sm:h-56 (224px, app/games/[shortname]/page.tsx) doubled is 448.
  // Width has no CSS cap, so it is 2x the page's own max-w-5xl (1024px)
  // content column.
  expect(IMAGE_TARGETS.banner).toEqual({ maxWidth: 2048, maxHeight: 448 });
});

test("a PNG or WebP inside its box and under the byte limit needs no work", () => {
  const header = { mime: "image/png" as const, width: 64, height: 64, lossless: true, bitDepth: 8, grayscale: false };
  expect(planImageUpload(header, 100_000, 512 * 1024, IMAGE_TARGETS.logo)).toBe("unchanged");
});

test("a file the header parser cannot read - including every JPEG - always converts", () => {
  expect(planImageUpload(null, 100, 512 * 1024, IMAGE_TARGETS.logo)).toBe("convert");
});

test("a PNG or WebP over the byte limit converts even though the header reads fine", () => {
  const header = { mime: "image/webp" as const, width: 64, height: 64, lossless: false, bitDepth: 8, grayscale: false };
  expect(planImageUpload(header, 600 * 1024, 512 * 1024, IMAGE_TARGETS.logo)).toBe("convert");
});

test("a PNG or WebP taller or wider than its box converts even though it is small", () => {
  const header = { mime: "image/png" as const, width: 4000, height: 3000, lossless: true, bitDepth: 8, grayscale: false };
  expect(planImageUpload(header, 1000, 512 * 1024, IMAGE_TARGETS.banner)).toBe("convert");
});

test("the quality ladder steps down and stays within canvas.toBlob's 0 to 1 range", () => {
  expect(QUALITY_STEPS.length).toBeGreaterThan(1);
  for (const [index, quality] of QUALITY_STEPS.entries()) {
    expect(quality).toBeGreaterThan(0);
    expect(quality).toBeLessThanOrEqual(1);
    if (index > 0) expect(quality).toBeLessThan(QUALITY_STEPS[index - 1] as number);
  }
});

test("a WebP that only needed shrinking is told so, with its before and after size", () => {
  expect(describeConversion("image/webp", 720_583, 12_000, "image/webp")).toBe("Shrunk from 704 KB to 12 KB.");
});

test("a JPEG names the format it came from as well as the sizes", () => {
  expect(describeConversion("image/jpeg", 5_242_880, 320_000, "image/webp")).toBe("Converted from JPEG (5120 KB) to WebP (313 KB).");
});

test("a PNG that needed converting, not just shrinking, is named too", () => {
  expect(describeConversion("image/png", 900_000, 400_000, "image/webp")).toBe("Converted from PNG (879 KB) to WebP (391 KB).");
});

test("a WebP logo converts to PNG, not just shrinks, and says so (#366)", () => {
  expect(describeConversion("image/webp", 200_000, 150_000, "image/png")).toBe("Converted from WebP (196 KB) to PNG (147 KB).");
});

test("a logo always targets PNG, since the link preview renderer cannot decode WebP (#366)", () => {
  expect(targetFormat("logo")).toBe("image/png");
});

test("a banner targets WebP, since it never reaches that renderer", () => {
  expect(targetFormat("banner")).toBe("image/webp");
});
