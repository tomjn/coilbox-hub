import { expect, test } from "bun:test";
import type { MapFacts } from "@/lib/api/mapLookup";
import type { AssetIdentity } from "@/lib/assets/asset";
import { BLOB_TIER_BASE } from "@/lib/assets/blob";
import { DEFAULT_ASSET_CDN_BASE } from "@/lib/assets/cdn";
import { identityKey } from "@/lib/assets/have";
import type { HeldAssets, HeldRow, ResolvedAsset } from "@/lib/assets/resolve";
import { mapPreview, readAppearance } from "./preview";

const COMET = "Comet Catcher Remake 1.8";

const MINIMAP: AssetIdentity = { keyedOn: "map", mapName: COMET, variant: "minimap" };
const OVERLAY: AssetIdentity = { keyedOn: "map", mapName: COMET, variant: "overlay:height" };

function facts(overrides: Partial<MapFacts> = {}): MapFacts {
  return {
    slug: "comet-catcher-remake-1-8",
    display_name: null,
    description: null,
    authors: [],
    width_elmos: 6144,
    height_elmos: 10240,
    // The catalog's own span, which is not the pair that decodes the picture and
    // is here so a test can tell the two apart.
    world_height_min: -400,
    world_height_max: 1200,
    min_wind: null,
    max_wind: null,
    tidal_strength: null,
    void_water: null,
    water_coverage: null,
    tags: [],
    points: { start: [], metal: [], geo: [] },
    appearance: {},
    ...overrides,
  };
}

function row(overrides: Partial<HeldRow> = {}): HeldRow {
  return {
    tier: "static",
    path: "maps/minimap/def.webp",
    width: 512,
    height: 512,
    moderation: "approved",
    world_height_min: null,
    world_height_max: null,
    ...overrides,
  };
}

/** The overlay as #181 stores one: rescaled into the window this map's samples
 *  occupy, so its range is narrower than the catalog's own span. */
function overlayRow(overrides: Partial<HeldRow> = {}): HeldRow {
  return row({
    path: "maps/overlay/height/ghi.webp",
    world_height_min: -120.5,
    world_height_max: 890,
    ...overrides,
  });
}

function heldOf(...rows: [AssetIdentity, HeldRow][]): HeldAssets {
  return new Map(rows.map(([identity, held]) => [identityKey(identity), held]));
}

const SERVED: ResolvedAsset = {
  from: "static",
  url: `${DEFAULT_ASSET_CDN_BASE}maps/minimap/def.webp`,
  served: MINIMAP,
  substituted: false,
  width: 512,
  height: 512,
};

const DRAWN: ResolvedAsset = {
  from: "placeholder",
  name: COMET,
  keyedOn: "map",
  footprint: { width: 12, height: 20 },
};

test("a map the hub holds both pictures for gets a preview", () => {
  const preview = mapPreview(
    COMET,
    facts(),
    heldOf([MINIMAP, row()], [OVERLAY, overlayRow()]),
    SERVED,
  );

  expect(preview).toMatchObject({
    heightUrl: `${DEFAULT_ASSET_CDN_BASE}maps/overlay/height/ghi.webp`,
    textureUrl: `${DEFAULT_ASSET_CDN_BASE}maps/minimap/def.webp`,
    widthElmos: 6144,
    heightElmos: 10240,
  });
});

/**
 * The whole point of #194's dependency on #181. The picture was rescaled into
 * the window this map's own samples occupy, so the pair that decodes it is the
 * asset's. The catalog's pair is right there on the facts and using it would
 * draw terrain that looks plausible and is wrong.
 */
test("the range comes off the asset row and not off the catalog", () => {
  const preview = mapPreview(
    COMET,
    facts({ world_height_min: -400, world_height_max: 1200 }),
    heldOf([MINIMAP, row()], [OVERLAY, overlayRow()]),
    SERVED,
  );

  expect(preview?.range).toEqual({ min: -120.5, max: 890 });
});

/** The ordinary answer for most maps, for a long while. The flat minimap is
 *  still the page and nothing says a preview was withheld. */
test("a map with no height overlay has no preview", () => {
  expect(mapPreview(COMET, facts(), heldOf([MINIMAP, row()]), SERVED)).toBeNull();
});

/** A pending overlay is indistinguishable from no overlay, which is the rule the
 *  resolver holds everywhere. Its Blob path is a working public URL and a
 *  preview must not become the second way to reach one. */
test("an overlay that is not approved is no overlay at all", () => {
  const pending = heldOf(
    [MINIMAP, row()],
    [
      OVERLAY,
      overlayRow({
        tier: "blob",
        path: "maps/overlay/height/ghi-Xy9.webp",
        moderation: "pending",
      }),
    ],
  );
  const preview = mapPreview(COMET, facts(), pending, SERVED);

  expect(preview).toBeNull();
  expect(JSON.stringify(preview)).not.toContain("ghi-Xy9");
});

test("a rejected overlay is refused the same way", () => {
  const rejected = heldOf([MINIMAP, row()], [OVERLAY, overlayRow({ moderation: "rejected" })]);

  expect(mapPreview(COMET, facts(), rejected, SERVED)).toBeNull();
});

/** Nothing to drape over the relief, and the page is already drawing a
 *  placeholder rather than a picture. */
test("a map with an overlay but no minimap has no preview", () => {
  expect(mapPreview(COMET, facts(), heldOf([OVERLAY, overlayRow()]), DRAWN)).toBeNull();
});

/** The constraint refuses a row like this, so the guard is for a row that
 *  reached here some other way rather than for one the table would hold. */
test("an overlay stored without a range decodes to nothing rather than to NaN", () => {
  const ranged = heldOf([MINIMAP, row()], [OVERLAY, overlayRow({ world_height_max: null })]);

  expect(mapPreview(COMET, facts(), ranged, SERVED)).toBeNull();
});

test("a row still in Blob is previewed from the staging store", () => {
  const staged = heldOf(
    [MINIMAP, row()],
    [OVERLAY, overlayRow({ tier: "blob", path: "maps/overlay/height/ghi-Xy9.webp" })],
  );

  expect(mapPreview(COMET, facts(), staged, SERVED)?.heightUrl).toBe(
    `${BLOB_TIER_BASE}maps/overlay/height/ghi-Xy9.webp`,
  );
});

/** Off the catalog column, which is the archive's own declaration, rather than
 *  guessed from the appearance blob. */
test("a void water map says so", () => {
  const held = heldOf([MINIMAP, row()], [OVERLAY, overlayRow()]);

  expect(mapPreview(COMET, facts({ void_water: true }), held, SERVED)?.voidWater).toBe(true);
  expect(mapPreview(COMET, facts({ void_water: null }), held, SERVED)?.voidWater).toBe(false);
});

// The appearance blob, which has no schema and which nothing validates on the
// way in.

test("an empty appearance reads as nothing declared rather than as black", () => {
  expect(readAppearance({})).toEqual({
    water: null,
    waterAlpha: null,
    sky: null,
    fog: null,
    sunDirection: null,
    sunColour: null,
  });
});

test("the colours a map does declare come through", () => {
  const read = readAppearance({
    waterColor: [0.1, 0.35, 0.5],
    waterAlpha: 0.4,
    skyColor: [0, 0, 0.05],
    sunDir: [0.4, 0.8, -0.2],
  });

  expect(read.water).toEqual([0.1, 0.35, 0.5]);
  expect(read.waterAlpha).toBe(0.4);
  expect(read.sky).toEqual([0, 0, 0.05]);
  expect(read.sunDirection).toEqual([0.4, 0.8, -0.2]);
});

/** Every one of these is a shape somebody's jsonb can be, and every one of them
 *  is otherwise a way to render a black scene or no scene. */
test("a malformed appearance is ignored rather than fatal", () => {
  for (const blob of [null, undefined, "blue", 7, [1, 2, 3]]) {
    expect(readAppearance(blob).water).toBeNull();
  }

  expect(readAppearance({ waterColor: [0.1, 0.2] }).water).toBeNull();
  expect(readAppearance({ waterColor: [0.1, 0.2, "0.3"] }).water).toBeNull();
  expect(readAppearance({ waterColor: "rgb(0,0,255)" }).water).toBeNull();
  expect(readAppearance({ skyColor: [0, 0, Number.NaN] }).sky).toBeNull();
  expect(readAppearance({ sunDir: [0, Number.POSITIVE_INFINITY, 0] }).sunDirection).toBeNull();
  expect(readAppearance({ waterAlpha: Number.NaN }).waterAlpha).toBeNull();
});

/** Out of range is a map asking for something the renderer has no meaning for.
 *  Clamping keeps the hue it asked for, where discarding the triple loses it. */
test("a colour outside 0 to 1 is clamped rather than dropped", () => {
  expect(readAppearance({ waterColor: [-1, 0.5, 255] }).water).toEqual([0, 0.5, 1]);
  expect(readAppearance({ waterAlpha: 4 }).waterAlpha).toBe(1);
});

/** A direction is not a colour, so it keeps its sign and its length. Clamping
 *  one would put every map's sun in the same quarter of the sky. */
test("the sun's direction is not clamped", () => {
  expect(readAppearance({ sunDir: [-0.6, 2.4, 0.3] }).sunDirection).toEqual([-0.6, 2.4, 0.3]);
});
