import { expect, test } from "bun:test";
import {
  checkSeedBytes,
  planSeed,
  readSeedManifest,
  type SeedManifest,
  type SeedManifestAsset,
  seedIdentityKey,
} from "./seed";

/**
 * Built rather than described, the same way `./caps.test.ts` does it and for the
 * same reason: nothing in this path decodes, so a valid header over nothing is
 * as good as a picture and the checks under test are about agreement between the
 * manifest and the bytes.
 */
function webp(width: number, height: number, lossless = true): Uint8Array {
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

const HASH = "a".repeat(64);

function unit(over: Partial<SeedManifestAsset> = {}): SeedManifestAsset {
  return {
    kind: "unit",
    game: "BA",
    unitName: "aafus",
    variant: "buildpic",
    origin: "extracted",
    batch: 5,
    file: `batch-0005/${HASH}.webp`,
    hash: HASH,
    sourceHash: "b".repeat(64),
    encodeProfile: "webp-lossless-256",
    mime: "image/webp",
    width: 96,
    height: 96,
    bytes: 15540,
    sourceArchive: "Balanced Annihilation V15.9.8",
    ...over,
  };
}

function map(over: Partial<SeedManifestAsset> = {}): SeedManifestAsset {
  return {
    kind: "map",
    mapName: "1 Pass Greenland Redux v3",
    variant: "minimap",
    origin: "extracted",
    batch: 1,
    file: `batch-0001/${HASH}.webp`,
    hash: HASH,
    sourceHash: "c".repeat(64),
    encodeProfile: "webp-q80-512",
    mime: "image/webp",
    width: 512,
    height: 512,
    bytes: 35458,
    mapWidth: 6144,
    mapHeight: 10240,
    sourceArchive: "1 Pass Greenland Redux v3",
    ...over,
  };
}

function manifest(...assets: SeedManifestAsset[]): SeedManifest {
  return { manifestVersion: 1, assets };
}

function refusal(asset: SeedManifestAsset): string {
  const plan = planSeed(manifest(asset));
  expect(plan.entries).toHaveLength(0);
  return plan.refused[0].reason;
}

test("a manifest from another version is refused rather than read hopefully", () => {
  expect(() => readSeedManifest(JSON.stringify({ manifestVersion: 2, assets: [] }))).toThrow(
    /manifest version 2/,
  );
  expect(() => readSeedManifest(JSON.stringify({ manifestVersion: 1 }))).toThrow(/no `assets`/);
  expect(readSeedManifest(JSON.stringify(manifest(unit()))).assets).toHaveLength(1);
});

test("a unit asset becomes a row under its game and a path under units", () => {
  const [entry] = planSeed(manifest(unit())).entries;

  expect(entry.object).toEqual({
    batch: 5,
    from: `batch-0005/${HASH}.webp`,
    to: `units/BA/buildpic/${HASH}.webp`,
  });

  expect(entry.row).toEqual({
    game: "BA",
    unit_name: "aafus",
    map_name: null,
    variant: "buildpic",
    source_hash: "b".repeat(64),
    hash: HASH,
    encode_profile: "webp-lossless-256",
    path: `units/BA/buildpic/${HASH}.webp`,
    origin: "extracted",
    tier: "static",
    mime: "image/webp",
    bytes: 15540,
    width: 96,
    height: 96,
    map_width: null,
    map_height: null,
    world_height_min: null,
    world_height_max: null,
    source_archive: "Balanced Annihilation V15.9.8",
    moderation: "approved",
    approval_source: "seed",
  });
});

test("a seeded row is approved on the durable tier and nobody uploaded it", () => {
  const [entry] = planSeed(manifest(map())).entries;

  expect(entry.row.tier).toBe("static");
  expect(entry.row.moderation).toBe("approved");
  expect(entry.row.approval_source).toBe("seed");
  expect(entry.row).not.toHaveProperty("promoted_at");
  expect(entry.row).not.toHaveProperty("uploaded_by");
});

test("a map asset is keyed with no game at all, and carries the map size", () => {
  const [entry] = planSeed(manifest(map())).entries;

  expect(entry.object.to).toBe(`maps/minimap/${HASH}.webp`);
  expect(entry.row.game).toBeNull();
  expect(entry.row.map_name).toBe("1 Pass Greenland Redux v3");
  expect(entry.row.map_width).toBe(6144);
  expect(entry.row.map_height).toBe(10240);
});

test("a height overlay carries its bounds, and one without them is refused", () => {
  const bounded = map({
    variant: "overlay:height",
    mime: "image/png",
    file: `batch-0001/${HASH}.png`,
    encodeProfile: "png16-lossless-source",
    minHeight: 90,
    maxHeight: 485,
  });

  const [entry] = planSeed(manifest(bounded)).entries;
  expect(entry.object.to).toBe(`maps/overlay/height/${HASH}.png`);
  expect(entry.row.world_height_min).toBe(90);
  expect(entry.row.world_height_max).toBe(485);

  expect(refusal({ ...bounded, minHeight: undefined, maxHeight: undefined })).toMatch(
    /height bounds/,
  );
});

test("an identity the export did not finish is refused, on either side", () => {
  expect(refusal(unit({ game: " " }))).toMatch(/no whole identity/);
  expect(refusal(unit({ unitName: undefined }))).toMatch(/no whole identity/);
  expect(refusal(map({ mapName: undefined }))).toMatch(/no whole identity/);
  expect(refusal(unit({ kind: "campaign" }))).toMatch(/no whole identity/);
});

test("a map row with no map size is refused here rather than by the table", () => {
  expect(refusal(map({ mapWidth: undefined }))).toMatch(/map size/);
  expect(refusal(map({ mapHeight: undefined }))).toMatch(/map size/);
});

test("a seed may not claim somebody uploaded it, or a variant with no class", () => {
  expect(refusal(unit({ origin: "uploaded" }))).toMatch(/origin is "uploaded"/);
  expect(refusal(map({ variant: "overlay:rainfall" }))).toMatch(/not a variant/);
});

test("an identity that cannot be a path is refused", () => {
  expect(refusal(unit({ game: "../etc" }))).toMatch(/do not make a path/);
});

test("the second asset offered for one identity is refused, not preferred", () => {
  const plan = planSeed(manifest(unit(), unit({ hash: "d".repeat(64) })));

  expect(plan.entries).toHaveLength(1);
  expect(plan.entries[0].row.hash).toBe(HASH);
  expect(plan.refused[0].reason).toMatch(new RegExp(`already offered ${HASH}`));
});

test("two assets differing only in variant are two identities", () => {
  const plan = planSeed(manifest(map(), map({ variant: "overlay:metal", encodeProfile: "webp-lossless-source" })));

  expect(plan.entries).toHaveLength(2);
  expect(plan.refused).toHaveLength(0);
  expect(new Set(plan.entries.map((entry) => entry.key)).size).toBe(2);
});

test("a held back variant is neither published nor counted as a fault", () => {
  const plan = planSeed(manifest(unit(), map({ variant: "overlay:height", mime: "image/png" })), {
    skipVariants: ["overlay:height"],
  });

  expect(plan.entries).toHaveLength(1);
  expect(plan.entries[0].row.variant).toBe("buildpic");
  expect(plan.heldBack.map((asset) => asset.variant)).toEqual(["overlay:height"]);
  expect(plan.refused).toHaveLength(0);
});

test("the identity key separates the two shapes", () => {
  expect(
    seedIdentityKey({ keyedOn: "unit", game: "BA", unitName: "aafus", variant: "buildpic" }),
  ).toBe(["unit", "BA", "aafus", "buildpic"].join("\0"));
  expect(seedIdentityKey({ keyedOn: "map", mapName: "Comet Catcher", variant: "minimap" })).toBe(
    ["map", "Comet Catcher", "minimap"].join("\0"),
  );
});

test("a map name with a space in it cannot be read as another map's key", () => {
  const spaced = seedIdentityKey({ keyedOn: "map", mapName: "All That Simmers", variant: "minimap" });
  const shorter = seedIdentityKey({ keyedOn: "map", mapName: "All", variant: "minimap" });

  expect(spaced).not.toBe(shorter);
  expect(spaced.split("\0")).toEqual(["map", "All That Simmers", "minimap"]);
});

test("bytes that agree with the manifest and with their class pass", () => {
  expect(checkSeedBytes(unit(({ bytes: 40 })), webp(96, 96), HASH)).toEqual({ ok: true });
});

test("a manifest that disagrees with its own file is refused either way round", () => {
  const asset = unit({ bytes: 40 });

  expect(checkSeedBytes(asset, webp(96, 96).slice(0, 39), HASH)).toEqual({
    ok: false,
    error: "the manifest says 40 bytes and the file is 39.",
  });

  expect(checkSeedBytes(asset, webp(96, 96), "e".repeat(64))).toEqual({
    ok: false,
    error: `the manifest says ${HASH} and the file hashes to ${"e".repeat(64)}.`,
  });

  const measured = checkSeedBytes(unit({ bytes: 40, width: 128, height: 128 }), webp(96, 96), HASH);
  expect(measured).toEqual({
    ok: false,
    error: "the manifest says 128x128 and the bytes measure 96x96.",
  });
});

test("a file not named after its own hash is refused, since the path is the hash", () => {
  const wrong = checkSeedBytes(
    unit({ bytes: 40, file: "batch-0005/armsolar.webp" }),
    webp(96, 96),
    HASH,
  );

  expect(wrong).toEqual({ ok: false, error: `the file should be called ${HASH}.webp and is called armsolar.webp.` });
});

test("the class still decides, on the bytes, exactly as an upload would", () => {
  const oversize = checkSeedBytes(unit({ bytes: 40, width: 300, height: 300 }), webp(300, 300), HASH);
  expect(oversize).toEqual({
    ok: false,
    error: 'A "buildpic" may be at most 256px on its longest edge, and that one is 300x300.',
  });

  const lossy = checkSeedBytes(unit({ bytes: 40 }), webp(96, 96, false), HASH);
  expect(lossy).toEqual({
    ok: false,
    error: 'A "buildpic" must be losslessly encoded, and that one is not.',
  });
});
