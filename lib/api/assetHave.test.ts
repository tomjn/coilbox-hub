import { expect, test } from "bun:test";
import {
  ASSET_HAVE_FORMAT,
  ASSET_HAVE_MAX_KEYS,
  ASSET_HAVE_VERSION,
  buildAssetHaveBody,
  parseAssetHaveBody,
} from "./assetHave";
import { MAP_VARIANTS } from "@/lib/assets/asset";
import { identityKey } from "@/lib/assets/have";

const UNIT_KEY = {
  keyed_on: "unit",
  game: "bar",
  unit_name: "armsolar",
  variant: "buildpic",
  source_hash: "src-a",
};

const MAP_KEY = {
  keyed_on: "map",
  map_name: "Comet Catcher Remake 1.8",
  variant: "minimap",
  source_hash: "src-b",
};

function keysOf(body: unknown) {
  const parsed = parseAssetHaveBody(body);
  if (!parsed.ok) throw new Error(parsed.error);
  return parsed.keys;
}

test("both identity shapes parse into the discriminated union without collapsing", () => {
  const keys = keysOf({ keys: [UNIT_KEY, MAP_KEY] });

  expect(keys[0].identity).toEqual({
    keyedOn: "unit",
    game: "bar",
    unitName: "armsolar",
    variant: "buildpic",
  });
  expect(keys[1].identity).toEqual({
    keyedOn: "map",
    mapName: "Comet Catcher Remake 1.8",
    variant: "minimap",
  });
});

test("a body that is not an object is rejected", () => {
  for (const body of [[], null, "keys", 3]) {
    const parsed = parseAssetHaveBody(body);
    expect(parsed.ok).toBe(false);
  }
});

test("an unknown field on the body is rejected rather than ignored", () => {
  const parsed = parseAssetHaveBody({ keys: [UNIT_KEY], since: "yesterday" });

  expect(parsed).toEqual({ ok: false, error: "Unknown field: since", status: 400 });
});

test("an unknown field on a key is rejected, naming which key", () => {
  const parsed = parseAssetHaveBody({ keys: [UNIT_KEY, { ...MAP_KEY, hash: "enc-b" }] });

  expect(parsed).toEqual({
    ok: false,
    error: "keys[1] unknown field: hash",
    status: 400,
  });
});

test("a unit field on a map key is an unknown field, so the two shapes cannot be mixed", () => {
  const parsed = parseAssetHaveBody({ keys: [{ ...MAP_KEY, game: "bar" }] });

  expect(parsed).toEqual({ ok: false, error: "keys[0] unknown field: game", status: 400 });
});

test("a key without keyed_on cannot be guessed at", () => {
  const parsed = parseAssetHaveBody({
    keys: [{ game: "bar", unit_name: "armsolar", variant: "buildpic", source_hash: "src-a" }],
  });

  expect(parsed).toEqual({
    ok: false,
    error: 'keys[0] `keyed_on` must be "unit" or "map".',
    status: 400,
  });
});

test("a missing or empty field is rejected with the length the table accepts", () => {
  const parsed = parseAssetHaveBody({ keys: [{ ...UNIT_KEY, unit_name: "  " }] });

  expect(parsed).toEqual({
    ok: false,
    error: "keys[0] `unit_name` is required and must be a string of 1 to 128 characters.",
    status: 400,
  });
});

test("a value longer than the column accepts is refused before it reaches the database", () => {
  const parsed = parseAssetHaveBody({ keys: [{ ...MAP_KEY, map_name: "m".repeat(257) }] });

  expect(parsed).toEqual({
    ok: false,
    error: "keys[0] `map_name` is required and must be a string of 1 to 256 characters.",
    status: 400,
  });
});

test("a unit variant outside the table's vocabulary is refused", () => {
  const parsed = parseAssetHaveBody({ keys: [{ ...UNIT_KEY, variant: "minimap" }] });

  expect(parsed).toEqual({
    ok: false,
    error: 'keys[0] `variant` on a unit must be "buildpic" or "render:<angle>".',
    status: 400,
  });
});

test("a render variant is accepted whatever the angle, matching the check constraint", () => {
  const keys = keysOf({ keys: [{ ...UNIT_KEY, variant: "render:front-left" }] });

  expect(keys[0].identity.variant).toBe("render:front-left");
});

test("a map variant is held to the map vocabulary and not the unit one", () => {
  expect(keysOf({ keys: [{ ...MAP_KEY, variant: "overlay:height" }] })[0].identity.variant).toBe(
    "overlay:height",
  );

  // The unit list would have taken neither of these. The point is that the map
  // list is its own, and that it is closed.
  expect(parseAssetHaveBody({ keys: [{ ...MAP_KEY, variant: "buildpic" }] }).ok).toBe(false);
});

/**
 * #137. The upload parser and `asset_map_variant_check` both refuse anything
 * outside the four, and this took any string, so `overlay:metel` was answered
 * `missing` and refused a round trip later by the upload it had just told the
 * caller to make.
 */
test("a map variant outside the table's vocabulary is refused", () => {
  const parsed = parseAssetHaveBody({ keys: [{ ...MAP_KEY, variant: "overlay:metel" }] });

  expect(parsed).toEqual({
    ok: false,
    error:
      "keys[0] `variant` on a map must be one of minimap, overlay:metal, overlay:type, overlay:height.",
    status: 400,
  });
});

test("every variant the table accepts on a map is accepted here", () => {
  for (const variant of MAP_VARIANTS) {
    expect(keysOf({ keys: [{ ...MAP_KEY, variant }] })[0].identity.variant).toBe(variant);
  }
});

test("an empty batch is rejected, since it asks nothing", () => {
  const parsed = parseAssetHaveBody({ keys: [] });

  expect(parsed).toEqual({ ok: false, error: "`keys` must not be empty.", status: 400 });
});

test("a batch at the limit is accepted and one over it is refused whole", () => {
  const key = (index: number) => ({ ...UNIT_KEY, unit_name: `unit${index}` });
  const atLimit = Array.from({ length: ASSET_HAVE_MAX_KEYS }, (_, index) => key(index));

  expect(parseAssetHaveBody({ keys: atLimit }).ok).toBe(true);

  const overLimit = parseAssetHaveBody({ keys: [...atLimit, key(ASSET_HAVE_MAX_KEYS)] });
  expect(overLimit).toEqual({
    ok: false,
    error: "A batch may carry at most 500 keys. That request carried 501. Split it.",
    status: 413,
  });
});

test("a repeated key is rejected rather than answered twice", () => {
  const parsed = parseAssetHaveBody({ keys: [UNIT_KEY, { ...UNIT_KEY, source_hash: "src-z" }] });

  expect(parsed).toEqual({
    ok: false,
    error: "keys[1] repeats a key already in the batch.",
    status: 400,
  });
});

test("the same name under the two shapes is not a repeat", () => {
  const keys = keysOf({
    keys: [UNIT_KEY, { keyed_on: "map", map_name: "armsolar", variant: "minimap", source_hash: "src-a" }],
  });

  expect(keys).toHaveLength(2);
});

test("a body carries the format marker, the version and one result per key in request order", () => {
  const keys = keysOf({ keys: [UNIT_KEY, MAP_KEY] });
  const body = buildAssetHaveBody(keys, new Map());

  expect(body.format).toBe(ASSET_HAVE_FORMAT);
  expect(body.version).toBe(ASSET_HAVE_VERSION);
  expect(body.version).toBe(1);
  expect(body.results).toEqual([
    { keyed_on: "unit", game: "bar", unit_name: "armsolar", variant: "buildpic", status: "missing" },
    {
      keyed_on: "map",
      map_name: "Comet Catcher Remake 1.8",
      variant: "minimap",
      status: "missing",
    },
  ]);
});

test("a matching source hash is have, a different one is changed, and no row is missing", () => {
  const keys = keysOf({ keys: [UNIT_KEY, MAP_KEY, { ...UNIT_KEY, unit_name: "armcom" }] });
  const stored = new Map([
    [identityKey(keys[0].identity), "src-a"],
    [identityKey(keys[1].identity), "src-older"],
  ]);

  expect(buildAssetHaveBody(keys, stored).results.map((result) => result.status)).toEqual([
    "have",
    "changed",
    "missing",
  ]);
});

test("nothing in a result says what the moderation queue holds", () => {
  const keys = keysOf({ keys: [UNIT_KEY] });
  const body = buildAssetHaveBody(keys, new Map([[identityKey(keys[0].identity), "src-a"]]));

  expect(JSON.stringify(body)).not.toContain("moderation");
  expect(Object.keys(body.results[0]).sort()).toEqual([
    "game",
    "keyed_on",
    "status",
    "unit_name",
    "variant",
  ]);
});
