import { expect, test } from "bun:test";
import {
  ASSET_ORIGINS,
  type AssetOrigin,
  MAP_HEIGHT_OVERLAY_VARIANT,
  MAP_MINIMAP_VARIANT,
  MAP_VARIANTS,
  UNIT_BUILDPIC_VARIANT,
  UNIT_RENDER_VARIANT_PREFIX,
  UNIT_TOP_RENDER_VARIANT,
} from "./asset";
import { ASSET_CAPS, capForVariant, heightOverlayMaxBytes, heightOverlaySamples } from "./caps";
import vocabulary from "./vendor/asset-vocabulary.json";

/**
 * The hub enforces this vocabulary and coilbox encodes to it, so a disagreement
 * is a 400 or a 413 on somebody else's machine months later rather than
 * anything either repo can see (#165). The file is vendored, so upstream moving
 * turns `bun run check:vendor` red, and these say the hub is reading it rather
 * than carrying its own copy of the same numbers.
 */

test("the hub caps every class the vocabulary names, and no others", () => {
  expect(Object.keys(ASSET_CAPS).sort()).toEqual(Object.keys(vocabulary.classes).sort());
});

/** The cap under its class name, so a mismatch says which class it was. The
 * return type is loose because the vocabulary's `mime` is a JSON string and the
 * cap's is the two the hub can store, and this compares values not types. */
function capOf(variant: string): Record<string, unknown> {
  return { [variant]: ASSET_CAPS[variant] };
}

test("each class is capped at what the vocabulary says it is", () => {
  for (const [name, agreed] of Object.entries(vocabulary.classes)) {
    expect(capOf(name)).toEqual({
      [name]: {
        mime: agreed.mime,
        maxEdge: agreed.maxEdgePx,
        maxBytes: agreed.maxBytes,
        square: agreed.square,
        lossless: agreed.lossless,
        minBitDepth: agreed.minBitDepth,
        grayscale: agreed.grayscale,
      },
    });
  }
});

/**
 * `isMapVariant` reads the vocabulary's `mapVariants`, and the caps are keyed on
 * its class names, so a layer that reached one list and not the other would be
 * a variant the hub admits and then has no cap for.
 */
test("every map variant is a class the vocabulary caps", () => {
  expect(MAP_VARIANTS.length).toBeGreaterThan(0);
  for (const variant of MAP_VARIANTS) {
    expect(Object.keys(vocabulary.classes)).toContain(variant);
  }
  expect(MAP_VARIANTS).toContain(MAP_HEIGHT_OVERLAY_VARIANT);
  expect(MAP_VARIANTS).toContain(MAP_MINIMAP_VARIANT);
});

/**
 * The unit side is not in `mapVariants`, because a render's angle is open ended
 * and a class name cannot carry it. So the two names it does have are checked
 * through the lookup that has to resolve them.
 */
test("the unit variants resolve to a cap", () => {
  expect(capForVariant(UNIT_BUILDPIC_VARIANT)).not.toBeNull();
  expect(capForVariant(UNIT_TOP_RENDER_VARIANT)).not.toBeNull();
});

/**
 * A blueprint plan asks for the top render by name (issue #93), so an upstream
 * rename of that angle has to be red here. Without this the page would ask for
 * a variant nothing ever holds, and every building would quietly fall back to
 * its buildpic with no failure to see.
 */
test("the top render a plan asks for is an angle the vocabulary names", () => {
  expect(
    vocabulary.unit.renderAngles.map((angle) => `${UNIT_RENDER_VARIANT_PREFIX}${angle}`),
  ).toContain(UNIT_TOP_RENDER_VARIANT);
});

/**
 * `AssetOrigin` is written out in `./asset` rather than derived, because a JSON
 * array widens to `string[]`. This is what stops the two parting company.
 */
test("the origins union is exactly what the vocabulary lists", () => {
  const declared: Record<AssetOrigin, true> = {
    extracted: true,
    rendered: true,
    uploaded: true,
  };

  expect(Object.keys(declared).sort()).toEqual([...ASSET_ORIGINS].sort());
});

/**
 * Both of these are derived from the vocabulary rather than read out of it, so
 * the file carrying a number the derivation does not produce is drift that
 * nothing else would catch.
 */
test("a height overlay is measured on the vocabulary's sample geometry", () => {
  const samples = heightOverlaySamples(1024);
  expect(samples).toBe(1024 / vocabulary.heightOverlay.elmosPerSample + 1);
  expect(heightOverlayMaxBytes(MAP_HEIGHT_OVERLAY_VARIANT, 1024, 1024)).toBe(
    samples * samples * vocabulary.heightOverlay.bytesPerSample,
  );
});
