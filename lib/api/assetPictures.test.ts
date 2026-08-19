import { expect, test } from "bun:test";
import { parseAssetHaveBody } from "./assetHave";
import {
  ASSET_PICTURES_FORMAT,
  ASSET_PICTURES_MAX_KEYS,
  ASSET_PICTURES_VERSION,
  buildAssetPicturesBody,
  parseAssetPicturesBody,
} from "./assetPictures";
import { type AssetIdentity, MAP_VARIANTS } from "@/lib/assets/asset";
import { BLOB_TIER_BASE } from "@/lib/assets/blob";
import { DEFAULT_ASSET_CDN_BASE } from "@/lib/assets/cdn";
import { identityKey } from "@/lib/assets/have";
import type { HeldRow } from "@/lib/assets/resolve";

const UNIT_KEY = {
  keyed_on: "unit",
  game: "bar",
  unit_name: "armsolar",
  variant: "buildpic",
};

const MAP_KEY = {
  keyed_on: "map",
  map_name: "Comet Catcher Remake 1.8",
  variant: "minimap",
};

function identitiesOf(body: unknown) {
  const parsed = parseAssetPicturesBody(body);
  if (!parsed.ok) throw new Error(parsed.error);
  return parsed.identities;
}

function row(overrides: Partial<HeldRow> = {}): HeldRow {
  return {
    tier: "static",
    path: "units/bar/buildpic/abc.webp",
    width: 256,
    height: 256,
    moderation: "approved",
    world_height_min: null,
    world_height_max: null,
    ...overrides,
  };
}

function heldFor(entries: [AssetIdentity, HeldRow][]) {
  return new Map(entries.map(([identity, held]) => [identityKey(identity), held]));
}

test("both identity shapes parse into the discriminated union without collapsing", () => {
  const identities = identitiesOf({ keys: [UNIT_KEY, MAP_KEY] });

  expect(identities).toEqual([
    { keyedOn: "unit", game: "bar", unitName: "armsolar", variant: "buildpic" },
    { keyedOn: "map", mapName: "Comet Catcher Remake 1.8", variant: "minimap" },
  ]);
});

/**
 * The difference from `/api/v1/assets/have` that the route exists for. A caller
 * asking about a map it has not installed does not hold the archive, so it
 * cannot hash it, and a key that carries one is a client that sent the wrong
 * route's body.
 */
test("a key carrying a source hash is refused, because this route never asks for one", () => {
  const parsed = parseAssetPicturesBody({ keys: [{ ...MAP_KEY, source_hash: "src-b" }] });

  expect(parsed).toEqual({
    ok: false,
    error: "keys[0] unknown field: source_hash",
    status: 400,
  });
});

test("a body that is not an object is rejected", () => {
  for (const body of [[], null, "keys", 3]) {
    expect(parseAssetPicturesBody(body).ok).toBe(false);
  }
});

test("an unknown field on the body is rejected rather than ignored", () => {
  const parsed = parseAssetPicturesBody({ keys: [UNIT_KEY], since: "yesterday" });

  expect(parsed).toEqual({ ok: false, error: "Unknown field: since", status: 400 });
});

test("a unit field on a map key is an unknown field, so the two shapes cannot be mixed", () => {
  const parsed = parseAssetPicturesBody({ keys: [{ ...MAP_KEY, game: "bar" }] });

  expect(parsed).toEqual({ ok: false, error: "keys[0] unknown field: game", status: 400 });
});

test("a key without keyed_on cannot be guessed at", () => {
  const parsed = parseAssetPicturesBody({
    keys: [{ game: "bar", unit_name: "armsolar", variant: "buildpic" }],
  });

  expect(parsed).toEqual({
    ok: false,
    error: 'keys[0] `keyed_on` must be "unit" or "map".',
    status: 400,
  });
});

test("a value longer than the column accepts is refused before it reaches the database", () => {
  const parsed = parseAssetPicturesBody({ keys: [{ ...MAP_KEY, map_name: "m".repeat(257) }] });

  expect(parsed).toEqual({
    ok: false,
    error: "keys[0] `map_name` is required and must be a string of 1 to 256 characters.",
    status: 400,
  });
});

test("every variant the table accepts on a map is accepted, and nothing else is", () => {
  for (const variant of MAP_VARIANTS) {
    expect(identitiesOf({ keys: [{ ...MAP_KEY, variant }] })[0].variant).toBe(variant);
  }

  expect(parseAssetPicturesBody({ keys: [{ ...MAP_KEY, variant: "overlay:metel" }] })).toEqual({
    ok: false,
    error:
      "keys[0] `variant` on a map must be one of minimap, overlay:metal, overlay:type, overlay:height.",
    status: 400,
  });
});

test("a unit takes a buildpic or a render at any angle, and nothing else", () => {
  expect(identitiesOf({ keys: [{ ...UNIT_KEY, variant: "render:front-left" }] })[0].variant).toBe(
    "render:front-left",
  );

  expect(parseAssetPicturesBody({ keys: [{ ...UNIT_KEY, variant: "minimap" }] })).toEqual({
    ok: false,
    error: 'keys[0] `variant` on a unit must be "buildpic" or "render:<angle>".',
    status: 400,
  });
});

test("an empty batch is rejected, since it asks nothing", () => {
  expect(parseAssetPicturesBody({ keys: [] })).toEqual({
    ok: false,
    error: "`keys` must not be empty.",
    status: 400,
  });
});

/**
 * The same cap and the same 413 as the have check, which is what lets coilbox
 * split a batch by one rule rather than discovering each route's limit by being
 * refused.
 */
test("a batch at the limit is accepted and one over it is refused whole", () => {
  const key = (index: number) => ({ ...UNIT_KEY, unit_name: `unit${index}` });
  const atLimit = Array.from({ length: ASSET_PICTURES_MAX_KEYS }, (_, index) => key(index));

  expect(parseAssetPicturesBody({ keys: atLimit }).ok).toBe(true);

  expect(parseAssetPicturesBody({ keys: [...atLimit, key(ASSET_PICTURES_MAX_KEYS)] })).toEqual({
    ok: false,
    error: "A batch may carry at most 500 keys. That request carried 501. Split it.",
    status: 413,
  });
});

test("a repeated key is rejected rather than answered twice", () => {
  const parsed = parseAssetPicturesBody({ keys: [MAP_KEY, { ...MAP_KEY }] });

  expect(parsed).toEqual({
    ok: false,
    error: "keys[1] repeats a key already in the batch.",
    status: 400,
  });
});

/**
 * Two clients speaking one vocabulary. The parsers are separate files, so this
 * is what stops them drifting: a change to what one route accepts as a key has
 * to be a change to what both accept, or this fails.
 */
test("every key the have check accepts is a key this accepts, and every one it refuses is refused", () => {
  const cases = [
    UNIT_KEY,
    { ...UNIT_KEY, variant: "render:270" },
    MAP_KEY,
    { ...MAP_KEY, variant: "overlay:height" },
    { ...UNIT_KEY, variant: "minimap" },
    { ...MAP_KEY, variant: "buildpic" },
    { ...MAP_KEY, variant: "overlay:metel" },
    { ...MAP_KEY, map_name: "m".repeat(257) },
    { ...UNIT_KEY, unit_name: "  " },
    { ...UNIT_KEY, keyed_on: "archive" },
    { ...MAP_KEY, game: "bar" },
  ];

  for (const key of cases) {
    const pictures = parseAssetPicturesBody({ keys: [key] });
    const have = parseAssetHaveBody({ keys: [{ ...key, source_hash: "src-a" }] });

    expect([key, pictures.ok]).toEqual([key, have.ok]);
    if (pictures.ok && have.ok) {
      expect(pictures.identities[0]).toEqual(have.keys[0].identity);
    }
  }
});

test("a body carries the format marker, the version and one result per key in request order", () => {
  const body = buildAssetPicturesBody(identitiesOf({ keys: [UNIT_KEY, MAP_KEY] }), new Map());

  expect(body.format).toBe(ASSET_PICTURES_FORMAT);
  expect(body.version).toBe(ASSET_PICTURES_VERSION);
  expect(body.version).toBe(1);
  expect(body.results).toEqual([
    { keyed_on: "unit", game: "bar", unit_name: "armsolar", variant: "buildpic", picture: null },
    {
      keyed_on: "map",
      map_name: "Comet Catcher Remake 1.8",
      variant: "minimap",
      picture: null,
    },
  ]);
});

test("a durable tier row answers with its tier, its tier relative path and the pixels", () => {
  const [identity] = identitiesOf({ keys: [MAP_KEY] });
  const held = heldFor([
    [identity, row({ tier: "static", path: "maps/minimap/def.webp", width: 1024, height: 512 })],
  ]);

  expect(buildAssetPicturesBody([identity], held).results[0].picture).toEqual({
    tier: "static",
    path: "maps/minimap/def.webp",
    url: `${DEFAULT_ASSET_CDN_BASE}maps/minimap/def.webp`,
    width: 1024,
    height: 512,
    served_variant: "minimap",
    substituted: false,
  });
});

/**
 * The suffix is the reason the route exists: `lib/assets/blob.ts` adds one
 * nobody can compute, so a caller that does not hold the bytes cannot derive
 * this path however much of the identity it knows.
 */
test("a staging tier row answers with the path Blob chose, suffix and all", () => {
  const [identity] = identitiesOf({ keys: [MAP_KEY] });
  const held = heldFor([
    [identity, row({ tier: "blob", path: "maps/minimap/def-Xy9tR2.webp" })],
  ]);

  expect(buildAssetPicturesBody([identity], held).results[0].picture).toMatchObject({
    tier: "blob",
    path: "maps/minimap/def-Xy9tR2.webp",
    url: `${BLOB_TIER_BASE}maps/minimap/def-Xy9tR2.webp`,
  });
});

test("an identity the hub holds nothing for is null rather than an error", () => {
  const [minimap, buildpic] = identitiesOf({ keys: [MAP_KEY, UNIT_KEY] });
  const held = heldFor([[buildpic, row()]]);

  const results = buildAssetPicturesBody([minimap, buildpic], held).results;
  expect(results[0].picture).toBeNull();
  expect(results[1].picture).not.toBeNull();
});

/**
 * `resolveAsset` drops anything unapproved before it looks at a tier, and this
 * is the assertion that the public route inherits that rather than reading the
 * row itself. Nothing here should ever see a pending row anyway: the route reads
 * with the anonymous client and `asset_read_approved` hides it.
 */
test("a pending row is not a picture, so its path never leaves the hub", () => {
  const [identity] = identitiesOf({ keys: [MAP_KEY] });
  const held = heldFor([
    [identity, row({ moderation: "pending", path: "maps/minimap/secret-Ab12.webp" })],
  ]);

  const body = buildAssetPicturesBody([identity], held);
  expect(body.results[0].picture).toBeNull();
  expect(JSON.stringify(body)).not.toContain("secret");
});

test("a missing render angle is served the buildpic, and the answer says so", () => {
  const [render] = identitiesOf({ keys: [{ ...UNIT_KEY, variant: "render:270" }] });
  const buildpic: AssetIdentity = {
    keyedOn: "unit",
    game: "bar",
    unitName: "armsolar",
    variant: "buildpic",
  };
  const held = heldFor([[buildpic, row()]]);

  expect(buildAssetPicturesBody([render], held).results[0]).toEqual({
    keyed_on: "unit",
    game: "bar",
    unit_name: "armsolar",
    variant: "render:270",
    picture: {
      tier: "static",
      path: "units/bar/buildpic/abc.webp",
      url: `${DEFAULT_ASSET_CDN_BASE}units/bar/buildpic/abc.webp`,
      width: 256,
      height: 256,
      served_variant: "buildpic",
      substituted: true,
    },
  });
});

test("nothing in a result says what the moderation queue holds", () => {
  const [identity] = identitiesOf({ keys: [MAP_KEY] });
  const body = buildAssetPicturesBody([identity], heldFor([[identity, row()]]));

  expect(JSON.stringify(body)).not.toContain("moderation");
  expect(Object.keys(body.results[0]).sort()).toEqual([
    "keyed_on",
    "map_name",
    "picture",
    "variant",
  ]);
});
