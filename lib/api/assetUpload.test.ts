import { expect, test } from "bun:test";
import {
  ASSET_UPLOAD_FORMAT,
  ASSET_UPLOAD_VERSION,
  buildAssetUploadBody,
  parseAssetUpload,
} from "./assetUpload";

const UNIT = {
  keyed_on: "unit",
  game: "BYAR",
  unit_name: "armsolar",
  variant: "buildpic",
  source_hash: "raw-abc",
  encode_profile: "webp-lossless-256",
  origin: "extracted",
  mime: "image/webp",
  bytes: 4096,
  source_archive: "byar_1.2.sdz",
};

const MAP = {
  keyed_on: "map",
  map_name: "Comet Catcher Remake 1.8",
  variant: "minimap",
  map_width: 8192,
  map_height: 8192,
  source_hash: "raw-def",
  encode_profile: "webp-q80-512",
  origin: "extracted",
  mime: "image/webp",
  bytes: 40000,
  source_archive: "comet_catcher_remake_1.8.sd7",
};

const HEIGHT = {
  ...MAP,
  variant: "overlay:height",
  mime: "image/png",
  encode_profile: "png16-lossless-source",
  world_height_min: -40.5,
  world_height_max: 320.25,
};

function error(value: unknown): string {
  const parsed = parseAssetUpload(value);
  if (parsed.ok) throw new Error("expected a refusal");
  return parsed.error;
}

test("a unit declaration becomes a unit identity", () => {
  const parsed = parseAssetUpload(UNIT);

  expect(parsed).toEqual({
    ok: true,
    declaration: {
      identity: { keyedOn: "unit", game: "BYAR", unitName: "armsolar", variant: "buildpic" },
      sourceHash: "raw-abc",
      encodeProfile: "webp-lossless-256",
      origin: "extracted",
      mime: "image/webp",
      bytes: 4096,
      sourceArchive: "byar_1.2.sdz",
      mapWidth: null,
      mapHeight: null,
      worldHeightMin: null,
      worldHeightMax: null,
    },
  });
});

/**
 * The client's claim is gone rather than overridden (#105). The hub measures
 * the header, so a declared pair can only agree with the bytes or be wrong, and
 * the unknown field rule turns a client that still sends one into a 400 naming
 * the field rather than a claim that is quietly ignored.
 */
test("a declaration cannot say how big the picture is", () => {
  expect(error({ ...UNIT, width: 128 })).toBe("Unknown field: width");
  expect(error({ ...UNIT, height: 128 })).toBe("Unknown field: height");
});

/**
 * The same removal for a sharper reason (#154). `hash` is the leaf of the path
 * promotion commits the bytes under, and a map path is nothing but the hash, so
 * a declared one is an uploader choosing which existing picture to overwrite in
 * a permanent public history. The hub has the bytes, so it computes it.
 *
 * `source_hash` stays and is asserted alongside, because the two look alike and
 * are not: the archive never reaches the hub, so there is nothing to compute
 * that one from, and it names no object.
 */
test("a declaration cannot say where the bytes will land", () => {
  expect(error({ ...UNIT, hash: "enc-abc" })).toBe("Unknown field: hash");
  expect(parseAssetUpload(UNIT).ok).toBe(true);
});

test("a map declaration carries the world size and no game", () => {
  const parsed = parseAssetUpload(MAP);

  expect(parsed.ok).toBe(true);
  if (!parsed.ok) return;
  expect(parsed.declaration.identity).toEqual({
    keyedOn: "map",
    mapName: "Comet Catcher Remake 1.8",
    variant: "minimap",
  });
  expect(parsed.declaration.mapWidth).toBe(8192);
  expect(parsed.declaration.mapHeight).toBe(8192);
});

/**
 * The same strictness as `parseAssetHaveBody`, and for a sharper reason: a
 * client that spelled `source_hash` as `sourceHash` and had it ignored would
 * write a row that dedupes against nothing and re-uploads on every run, out of
 * 2,000 advanced operations a month.
 */
test("an unknown field is refused rather than ignored", () => {
  expect(error({ ...UNIT, sourceHash: "raw-abc" })).toBe("Unknown field: sourceHash");
});

test("a unit may not name a map, and a map may not name a game", () => {
  expect(error({ ...UNIT, map_name: "Tangerine 1.1" })).toBe("Unknown field: map_name");
  expect(error({ ...MAP, game: "BYAR" })).toBe("Unknown field: game");
});

test("a map has to say how big the world is, because nothing downstream can recover it", () => {
  const { map_width, ...withoutWidth } = MAP;
  void map_width;

  expect(error(withoutWidth)).toBe("`map_width` is required and must be a positive integer.");
});

test("a unit variant is a buildpic or a render, the same rule the table has", () => {
  expect(error({ ...UNIT, variant: "minimap" })).toBe(
    '`variant` on a unit must be "buildpic" or "render:<angle>".',
  );
  expect(parseAssetUpload({ ...UNIT, variant: "render:270" }).ok).toBe(true);
});

test("origin is one of the three the table knows about", () => {
  expect(error({ ...UNIT, origin: "seeded" })).toBe(
    "`origin` must be one of extracted, rendered, uploaded.",
  );
});

test("a size is a positive integer and not a float, a zero or a string", () => {
  expect(error({ ...UNIT, bytes: 0 })).toBe("`bytes` is required and must be a positive integer.");
  expect(error({ ...UNIT, bytes: 40.5 })).toBe(
    "`bytes` is required and must be a positive integer.",
  );
  expect(error({ ...MAP, map_width: "8192" })).toBe(
    "`map_width` is required and must be a positive integer.",
  );
});

test("a map variant is one of the four, the same rule the table has", () => {
  expect(error({ ...MAP, variant: "metal" })).toBe(
    "`variant` on a map must be one of minimap, overlay:metal, overlay:type, overlay:height.",
  );
  expect(parseAssetUpload({ ...MAP, variant: "overlay:type" }).ok).toBe(true);
});

/**
 * A height overlay is a linear ramp between two world heights, and the archive
 * is the only thing that has them. Everything else is a picture with no range
 * to record, so carrying one is a client that has confused two variants.
 */
test("a height overlay carries its world range and nothing else may", () => {
  const parsed = parseAssetUpload(HEIGHT);
  expect(parsed.ok).toBe(true);
  if (!parsed.ok) return;
  expect(parsed.declaration.worldHeightMin).toBe(-40.5);
  expect(parsed.declaration.worldHeightMax).toBe(320.25);

  const { world_height_min, ...withoutMin } = HEIGHT;
  void world_height_min;
  expect(error(withoutMin)).toBe("`world_height_min` is required and must be a number.");

  expect(error({ ...MAP, world_height_min: 0, world_height_max: 320 })).toBe(
    '`world_height_min` and `world_height_max` belong to "overlay:height" and to nothing else.',
  );
});

/** Sea level is zero and the sea floor is below it, so a range that refused a
 * negative end would refuse most maps. A reversed one reads every sample upside
 * down, and nothing about the result looks wrong. */
test("a world height may be negative but the range may not run backwards", () => {
  expect(parseAssetUpload({ ...HEIGHT, world_height_min: -200, world_height_max: -10 }).ok).toBe(
    true,
  );
  expect(error({ ...HEIGHT, world_height_min: 320, world_height_max: 0 })).toBe(
    "`world_height_max` cannot be below `world_height_min`.",
  );
});

test("a field longer than the column is refused here rather than by the table", () => {
  expect(error({ ...UNIT, game: "g".repeat(65) })).toBe(
    "`game` is required and must be a string of 1 to 64 characters.",
  );
  expect(error({ ...UNIT, source_archive: "a".repeat(257) })).toBe(
    "`source_archive` is required and must be a string of 1 to 256 characters.",
  );
});

test("a declaration that is not an object, or names neither key, is refused", () => {
  expect(error([UNIT])).toBe("The asset declaration must be a JSON object.");
  expect(error("unit")).toBe("The asset declaration must be a JSON object.");
  expect(error({ ...UNIT, keyed_on: "blueprint" })).toBe('`keyed_on` must be "unit" or "map".');
});

test("the reply carries the envelope a shipped build reads first, and no path", () => {
  // No `path` and no `url`, deliberately: the store is public, so either one
  // is a way to see a picture nobody has reviewed yet (#131).
  expect(buildAssetUploadBody()).toEqual({
    format: ASSET_UPLOAD_FORMAT,
    version: ASSET_UPLOAD_VERSION,
    moderation: "pending",
  });
});
