import {
  MAP_HEIGHT_OVERLAY_VARIANT,
  UNIT_BUILDPIC_VARIANT,
  UNIT_RENDER_VARIANT_PREFIX,
} from "./asset";
import { type ImageHeader, readImageHeader } from "./imageHeader";

/**
 * What each class of picture is allowed to be, checked against the bytes rather
 * than against what the client said about them (issue #105).
 *
 * ## Why the caps are split by class and not unified
 *
 * A minimap is a spatial reference somebody reads detail off, so it gets 512px
 * on its longest edge. A buildpic is an icon, so it gets 256px, which is right
 * for display up to 128 CSS px on a 2x screen and covers grid icons and small
 * panels. The archives hold them at 128x128, so 256 costs nothing. If a unit
 * detail view ever wants a hero image, this is the number that changes.
 *
 * Aspect is a property of the class too, and only of the class. A buildpic must
 * be square. A minimap's aspect is unconstrained, because maps are not all
 * square and a square rule there would refuse valid uploads.
 *
 * A render is checked on its longest edge and not against a pinned size. The
 * reason renders exist is the blueprint preview, a top down orthographic view
 * scaled to the building's footprint, and footprints are not square, so a 3 by 2
 * building renders 3 by 2. Whether a render's aspect matches its footprint is
 * not checkable here, because the hub does not hold footprints. That correctness
 * lives in coilbox, at tomjn/coilbox#1631.
 *
 * The overlays have no pixel cap at all. They are sampled from the map's own
 * grids and have to stay at the resolution they were extracted at, so a cap
 * would silently make them wrong rather than refuse them.
 *
 * ## Why `overlay:height` is a PNG
 *
 * Coilbox extracts height as 16 bit grayscale with a linear mapping, and WebP's
 * lossless mode is 8 bit ARGB. Encoding one as the other halves the precision
 * and nothing looks broken, which is exactly the quiet corruption the lossless
 * rule exists to prevent. PNG carries 16 bits, so height is a PNG and the 8 bit
 * layers stay on lossless WebP.
 *
 * The allowlist is per class rather than global for the same reason. Both types
 * exist for the hub as a whole, in `ASSET_MIME_EXTENSIONS`, and a global list
 * that admits PNG everywhere admits a 16 bit PNG as a buildpic. Each class here
 * names the one type it may be.
 *
 * ## This is validation, not quota
 *
 * None of these numbers is a budget. An image that is not square and is larger
 * than 256px is not a buildpic whatever the client labelled it, and refusing it
 * deterministically takes a whole class of "this is not a game asset" out of the
 * moderation queue before a person sees it. The allowances are #107's and are
 * elsewhere.
 *
 * `maxBytes` is here for the same reason and on the same terms. It is not a
 * budget either: it is derived from `maxEdge` rather than chosen, and #107 reads
 * it in `./upload` where the rest of the refusals live.
 */

export interface AssetCap {
  /** The one type this class may be declared and encoded as. */
  mime: "image/png" | "image/webp";
  /** The largest either edge may be, or null when the source decides. */
  maxEdge: number | null;
  /**
   * The largest the encoded object may be, or null when the class has no number
   * of its own and the global backstop in `./upload` decides (issue #107).
   *
   * Derived rather than picked: it is the uncompressed size of the largest image
   * `maxEdge` permits, four bytes a pixel. So no encoding of a picture this
   * class allows can reach it, and anything that does is carrying something
   * other than the picture. A capped edge does not cap bytes on its own, since
   * metadata chunks are unbounded and a decoder never reads them, which is how a
   * 256px buildpic arrives as two megabytes.
   *
   * The overlays have no number here because they have no `maxEdge` to derive
   * one from: they are sampled from the map's own grids at whatever resolution
   * the map has. `overlay:height` gets one per upload instead, out of the
   * declared map size, which is {@link heightOverlayMaxBytes} and issue #142.
   */
  maxBytes: number | null;
  square: boolean;
  /** Whether the encoding has to preserve every sample. */
  lossless: boolean;
  /** Bits per channel the samples must carry, or null for no requirement. */
  minBitDepth: number | null;
  grayscale: boolean;
}

/** The uncompressed size of a square image of `edge` pixels, four bytes each. */
function rawBytes(edge: number): number {
  return edge * edge * 4;
}

const DEFAULTS = {
  maxEdge: null,
  maxBytes: null,
  square: false,
  lossless: false,
  minBitDepth: null,
  grayscale: false,
} satisfies Omit<AssetCap, "mime">;

/**
 * The caps, keyed on class. `render` covers every `render:<angle>`, since the
 * angle is part of the identity and changes nothing about what the picture may
 * be.
 */
export const ASSET_CAPS: Record<string, AssetCap> = {
  [UNIT_BUILDPIC_VARIANT]: {
    ...DEFAULTS,
    mime: "image/webp",
    maxEdge: 256,
    maxBytes: rawBytes(256),
    square: true,
  },
  render: { ...DEFAULTS, mime: "image/webp", maxEdge: 256, maxBytes: rawBytes(256) },
  minimap: { ...DEFAULTS, mime: "image/webp", maxEdge: 512, maxBytes: rawBytes(512) },
  "overlay:metal": { ...DEFAULTS, mime: "image/webp", lossless: true },
  "overlay:type": { ...DEFAULTS, mime: "image/webp", lossless: true },
  "overlay:height": {
    ...DEFAULTS,
    mime: "image/png",
    lossless: true,
    minBitDepth: 16,
    grayscale: true,
  },
};

/** The cap for one variant, or null when the variant is not one the hub has a
 * class for. Every variant either side of the identity has one, so a null here
 * is a variant that should already have been refused as unspellable. */
export function capForVariant(variant: string): AssetCap | null {
  if (variant.startsWith(UNIT_RENDER_VARIANT_PREFIX)) return ASSET_CAPS.render;
  return ASSET_CAPS[variant] ?? null;
}

/**
 * How many elmos one heightmap sample spans.
 *
 * The engine's `squareSize`, and it is 8 in all 98 map archives in the
 * maintainer's collection with no exceptions, so it is a constant of the format
 * rather than a per map field the hub would have to be told.
 */
export const ELMOS_PER_HEIGHT_SAMPLE = 8;

/** What one 16 bit grayscale sample takes before compression. */
const HEIGHT_SAMPLE_BYTES = 2;

/**
 * How many samples a height overlay carries along an edge that many elmos long.
 *
 * One per heightmap vertex, so there is a fencepost more than there are squares:
 * a 16384 elmo edge is 2048 squares and 2049 samples. Measured, not assumed. The
 * SMF header gives `mapx` in squares and `squareSize` in elmos, `mapx` is always
 * a multiple of 64 across the collection, and `map_width` on the row is
 * `mapx * squareSize`.
 */
export function heightOverlaySamples(elmos: number): number {
  return Math.floor(elmos / ELMOS_PER_HEIGHT_SAMPLE) + 1;
}

/**
 * The largest a height overlay for a map this size may be, or null for every
 * other class (issue #142).
 *
 * ## Why the overlays could not take the derivation the other classes take
 *
 * `maxBytes` above is the uncompressed size of the largest image `maxEdge`
 * permits. An overlay has no `maxEdge`, because it is the map's own grid at the
 * map's own resolution, so all three fell through to the 2 MB backstop in
 * `./upload`. That made the backstop the operative cap for the one class nobody
 * had measured.
 *
 * ## What the measurement said
 *
 * Every `.sd7` and `.sdz` in the maintainer's `~/.spring/maps` was opened
 * through `bsdtar`, its SMF header read, and its heightmap encoded as the 16 bit
 * grayscale lossless PNG this class is. 97 archives, largest first:
 *
 * - `mediterraneum_v1`, a 32x32 map, 2049 by 2049 samples: 4,511,410 bytes
 * - `special_hotstepper_1.1.1`, also 32x32: 3,549,982 bytes
 * - median across the 97: 1,010,809 bytes
 *
 * Seven of the 97 are over the 2 MB backstop, so it was not a backstop. It was
 * refusing about seven per cent of the real corpus, with a 413 naming a number
 * chosen for classes nobody expected to reach it.
 *
 * 32x32 is the ceiling: BAR's published catalogue carries 225 maps with a size
 * and none is larger in either dimension.
 *
 * ## The number, and the same rule as the rest of the file
 *
 * The uncompressed size of the picture the declared map size implies, which for
 * 16 bit grayscale is two bytes a sample rather than the four a colour image
 * takes. So it is derived exactly the way `maxBytes` is, and it moves with the
 * map instead of being one number for every map.
 *
 * It has room. The worst compression across the 97 was 1.5405 bytes a sample, on
 * a small noisy map, and the two 32x32 maps came in at 1.07 and 0.85. Nothing
 * measured is within a quarter of its cap.
 *
 * ## Two things this does not fix, and one it gives away
 *
 * The largest maps are over the platform's own 4.5 MB limit on a function body
 * once the derivation is done, and 4,511,410 bytes is close enough to that limit
 * to be a problem in itself. That is a real ceiling on the class rather than
 * something a cap here can lift, and it is #162.
 *
 * `overlay:metal` and `overlay:type` keep the backstop. They are 8 bit and
 * heavily quantised, so nothing suggests they are near it, but this measurement
 * says nothing about them and inventing a grid size for a class nobody has
 * measured is how a cap starts refusing valid uploads.
 *
 * And the map size is declared rather than measured, so a client willing to
 * overstate it raises its own ceiling. What that buys is the difference between
 * 2 MB and the 4.5 MB the platform refuses a body at, under an account storage
 * quota and a monthly upload budget that are unaffected. That is the price of
 * keeping this check where every other byte check is, in front of the round trip
 * and off the declaration alone.
 */
export function heightOverlayMaxBytes(
  variant: string,
  mapWidth: number | null,
  mapHeight: number | null,
): number | null {
  if (variant !== MAP_HEIGHT_OVERLAY_VARIANT) return null;
  if (mapWidth === null || mapHeight === null) return null;

  return (
    heightOverlaySamples(mapWidth) * heightOverlaySamples(mapHeight) * HEIGHT_SAMPLE_BYTES
  );
}

export type AssetImageCheck =
  | { ok: true; width: number; height: number }
  | { ok: false; error: string; status: number };

/**
 * Measure the bytes and hold them to their class, returning the dimensions that
 * belong on the row.
 *
 * Pure and cheap by construction. It reads a header out of a buffer the request
 * already holds, so it costs no round trip and no advanced operation, which is
 * what lets it run before the hub does any work at all. A rejection here has
 * cost nothing.
 *
 * `declared` is the MIME the client said, and it is checked twice: against the
 * class, and against what the bytes turned out to be. The second one matters
 * because the declared type picks the stored file's extension, so a PNG stored
 * as `.webp` is a picture that never decodes for anybody.
 */
export function checkAssetImage(
  variant: string,
  declared: string,
  bytes: Uint8Array,
): AssetImageCheck {
  const cap = capForVariant(variant);
  if (!cap) {
    return {
      ok: false,
      error: `\`${variant}\` is not a variant the hub stores pictures for.`,
      status: 400,
    };
  }

  if (declared !== cap.mime) {
    return {
      ok: false,
      error: `A "${variant}" must be ${cap.mime}, and that one declares ${declared}.`,
      status: 415,
    };
  }

  const header = readImageHeader(bytes);
  if (!header) {
    return {
      ok: false,
      error: "Those bytes do not start with a PNG or WebP header the hub can measure.",
      status: 400,
    };
  }

  if (header.mime !== declared) {
    return {
      ok: false,
      error: `The declaration says ${declared} and the bytes are ${header.mime}.`,
      status: 415,
    };
  }

  return refusal(variant, cap, header) ?? { ok: true, width: header.width, height: header.height };
}

/** The first cap this picture misses, or null when it misses none. */
function refusal(
  variant: string,
  cap: AssetCap,
  header: ImageHeader,
): { ok: false; error: string; status: number } | null {
  const size = `${header.width}x${header.height}`;

  if (cap.maxEdge !== null && Math.max(header.width, header.height) > cap.maxEdge) {
    return {
      ok: false,
      error: `A "${variant}" may be at most ${cap.maxEdge}px on its longest edge, and that one is ${size}.`,
      status: 413,
    };
  }

  if (cap.square && header.width !== header.height) {
    return {
      ok: false,
      error: `A "${variant}" must be square, and that one is ${size}.`,
      status: 400,
    };
  }

  if (cap.lossless && !header.lossless) {
    return {
      ok: false,
      error: `A "${variant}" must be losslessly encoded, and that one is not.`,
      status: 400,
    };
  }

  if (cap.minBitDepth !== null && header.bitDepth < cap.minBitDepth) {
    return {
      ok: false,
      error: `A "${variant}" must carry ${cap.minBitDepth} bits a channel, and that one carries ${header.bitDepth}.`,
      status: 400,
    };
  }

  if (cap.grayscale && !header.grayscale) {
    return {
      ok: false,
      error: `A "${variant}" must be grayscale, and that one is not.`,
      status: 400,
    };
  }

  return null;
}
