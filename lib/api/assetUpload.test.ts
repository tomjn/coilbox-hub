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
  hash: "enc-abc",
  encode_profile: "buildpic-q80",
  origin: "extracted",
  mime: "image/webp",
  bytes: 4096,
  width: 128,
  height: 128,
  source_archive: "byar_1.2.sdz",
};

const MAP = {
  keyed_on: "map",
  map_name: "Comet Catcher Remake 1.8",
  variant: "minimap",
  map_width: 8192,
  map_height: 8192,
  source_hash: "raw-def",
  hash: "enc-def",
  encode_profile: "minimap-q80",
  origin: "extracted",
  mime: "image/webp",
  bytes: 40000,
  width: 512,
  height: 512,
  source_archive: "comet_catcher_remake_1.8.sd7",
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
      hash: "enc-abc",
      encodeProfile: "buildpic-q80",
      origin: "extracted",
      mime: "image/webp",
      bytes: 4096,
      width: 128,
      height: 128,
      sourceArchive: "byar_1.2.sdz",
      mapWidth: null,
      mapHeight: null,
    },
  });
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
  expect(error({ ...UNIT, width: "128" })).toBe(
    "`width` is required and must be a positive integer.",
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
