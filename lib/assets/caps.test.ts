import { expect, test } from "bun:test";
import { MAP_VARIANTS } from "./asset";
import { ASSET_CAPS, capForVariant, checkAssetImage } from "./caps";
import { ASSET_MIME_EXTENSIONS } from "./path";

/**
 * The caps are checked against bytes, so the bytes are built rather than
 * described. Nothing here decodes, so a valid header over nothing is a valid
 * picture as far as this module is concerned, which is the point: the parse is
 * shallow on purpose and `./imageHeader` says why.
 */
function png(width: number, height: number, bitDepth = 8, colourType = 2): Uint8Array {
  const bytes = Buffer.alloc(26);
  bytes.set([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
  bytes.write("IHDR", 12, "latin1");
  bytes.writeUInt32BE(width, 16);
  bytes.writeUInt32BE(height, 20);
  bytes[24] = bitDepth;
  bytes[25] = colourType;
  return bytes;
}

function webp(width: number, height: number, lossless = false): Uint8Array {
  const bytes = Buffer.alloc(40);
  bytes.write("RIFF", 0, "latin1");
  bytes.write("WEBP", 8, "latin1");
  bytes.write(lossless ? "VP8L" : "VP8 ", 12, "latin1");
  bytes.writeUInt32LE(20, 16);

  if (lossless) {
    bytes[20] = 0x2f;
    bytes.writeUInt32LE((width - 1) | ((height - 1) << 14), 21);
    return bytes;
  }

  bytes.set([0x9d, 0x01, 0x2a], 23);
  bytes.writeUInt16LE(width, 26);
  bytes.writeUInt16LE(height, 28);
  return bytes;
}

function refusal(variant: string, mime: string, bytes: Uint8Array): string {
  const checked = checkAssetImage(variant, mime, bytes);
  if (checked.ok) throw new Error("expected a refusal");
  return `${checked.status} ${checked.error}`;
}

test("a buildpic is square and small, and the row gets what the bytes measured", () => {
  expect(checkAssetImage("buildpic", "image/webp", webp(128, 128))).toEqual({
    ok: true,
    width: 128,
    height: 128,
  });

  expect(refusal("buildpic", "image/webp", webp(256, 192))).toBe(
    '400 A "buildpic" must be square, and that one is 256x192.',
  );
  expect(refusal("buildpic", "image/webp", webp(512, 512))).toBe(
    '413 A "buildpic" may be at most 256px on its longest edge, and that one is 512x512.',
  );
});

/**
 * A render is the blueprint preview, scaled to the building's footprint, and
 * footprints are not square. Whether the aspect matches the footprint is not
 * checkable here, because the hub does not hold footprints, and lives in
 * coilbox at tomjn/coilbox#1631.
 */
test("a render is checked on its longest edge and never on its aspect", () => {
  expect(checkAssetImage("render:315", "image/webp", webp(192, 128)).ok).toBe(true);
  expect(checkAssetImage("render:0", "image/webp", webp(256, 64)).ok).toBe(true);

  expect(refusal("render:315", "image/webp", webp(320, 128))).toBe(
    '413 A "render:315" may be at most 256px on its longest edge, and that one is 320x128.',
  );
});

/** Maps are not all square and a minimap is read for detail, so it gets twice
 * the edge a unit icon does and no aspect rule at all. */
test("a minimap gets 512px and any aspect", () => {
  expect(checkAssetImage("minimap", "image/webp", webp(300, 300)).ok).toBe(true);
  expect(checkAssetImage("minimap", "image/webp", webp(512, 256)).ok).toBe(true);

  expect(refusal("minimap", "image/webp", webp(600, 600))).toBe(
    '413 A "minimap" may be at most 512px on its longest edge, and that one is 600x600.',
  );
});

/** Overlays are sampled from the map's own grids, so a cap would make them
 * wrong rather than refuse them. */
test("an overlay keeps the resolution it was extracted at, and has to be lossless", () => {
  expect(checkAssetImage("overlay:metal", "image/webp", webp(1024, 2048, true)).ok).toBe(true);

  expect(refusal("overlay:type", "image/webp", webp(512, 512))).toBe(
    '400 A "overlay:type" must be losslessly encoded, and that one is not.',
  );
});

/**
 * The one that the whole issue turns on. A lossless WebP of a 16 bit ramp is 8
 * bit ARGB, so it halves the precision, nothing looks broken and the overlay is
 * wrong.
 */
test("a height overlay is a 16 bit grayscale PNG and cannot be a WebP", () => {
  expect(checkAssetImage("overlay:height", "image/png", png(513, 513, 16, 0)).ok).toBe(true);

  expect(refusal("overlay:height", "image/webp", webp(513, 513, true))).toBe(
    '415 A "overlay:height" must be image/png, and that one declares image/webp.',
  );
  expect(refusal("overlay:height", "image/png", png(513, 513, 8, 0))).toBe(
    '400 A "overlay:height" must carry 16 bits a channel, and that one carries 8.',
  );
  expect(refusal("overlay:height", "image/png", png(513, 513, 16, 2))).toBe(
    '400 A "overlay:height" must be grayscale, and that one is not.',
  );
});

/**
 * The allowlist is per class rather than global. Both types exist for the hub as
 * a whole, and a global list that admits PNG everywhere admits a 16 bit PNG as a
 * buildpic.
 */
test("the type a class may be is the class's, not the hub's", () => {
  expect(Object.keys(ASSET_MIME_EXTENSIONS)).toEqual(["image/webp", "image/png"]);

  expect(refusal("buildpic", "image/png", png(128, 128))).toBe(
    '415 A "buildpic" must be image/webp, and that one declares image/png.',
  );
  expect(refusal("minimap", "image/png", png(512, 512))).toBe(
    '415 A "minimap" must be image/webp, and that one declares image/png.',
  );
});

/** The declared type picks the stored file's extension, so a PNG stored as
 * `.webp` is a picture that never decodes for anybody. */
test("the bytes have to be the type the declaration claims", () => {
  expect(refusal("buildpic", "image/webp", png(128, 128))).toBe(
    "415 The declaration says image/webp and the bytes are image/png.",
  );
});

test("bytes with no header the hub can measure are refused before anything is written", () => {
  expect(refusal("minimap", "image/webp", Buffer.from("not a picture at all"))).toBe(
    "400 Those bytes do not start with a PNG or WebP header the hub can measure.",
  );
});

test("every variant the hub stores has a cap, and nothing else does", () => {
  for (const variant of MAP_VARIANTS) {
    expect(capForVariant(variant)).toBeDefined();
  }
  expect(capForVariant("buildpic")).toBe(ASSET_CAPS.buildpic);
  expect(capForVariant("render:270")).toBe(ASSET_CAPS.render);
  expect(capForVariant("overlay:hight")).toBeNull();
  expect(refusal("overlay:hight", "image/webp", webp(64, 64))).toBe(
    "400 `overlay:hight` is not a variant the hub stores pictures for.",
  );
});
