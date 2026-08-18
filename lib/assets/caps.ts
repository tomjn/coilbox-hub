import { UNIT_RENDER_VARIANT_PREFIX } from "./asset";
import { type ImageHeader, readImageHeader } from "./imageHeader";
import { type AssetMime, isAssetMime } from "./path";
import vocabulary from "./vendor/asset-vocabulary.json";

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
 * The metal and type overlays have no pixel cap at all. They are sampled from
 * the map's own grids and have to stay at the resolution they were extracted at,
 * so a cap would silently make them wrong rather than refuse them.
 *
 * ## Why `overlay:height` stopped being a PNG
 *
 * It was 16 bit grayscale PNG at the map's own resolution, because WebP's
 * lossless mode is 8 bit and encoding one as the other halves the precision
 * without looking broken. That reasoning held, and the premise under it did not:
 * nothing ever read the second byte. A browser flattens a 16 bit image to its
 * high byte on the way in, so every reader of this layer was already getting
 * eight bits.
 *
 * So coilbox now sends a picture rather than a measurement, at
 * tomjn/coilbox#1730: 8 bit grey lossless WebP, rescaled into the window the
 * map's own samples occupy and capped at 512px like a minimap. Rescaling is
 * strictly better than the truncation a browser was doing, and the layer went
 * from 63 per cent of the corpus to a twentieth of what it was. Anything that
 * needs the exact heights reads them out of the map archive locally, which is
 * where they always were.
 *
 * The type allowlist is still per class rather than global. Both types exist for
 * the hub as a whole, in `ASSET_MIME_EXTENSIONS`, and a global list that admits
 * PNG everywhere admits a 16 bit PNG as a buildpic. Each class here names the
 * one type it may be.
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
  mime: AssetMime;
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
   * The metal and type overlays have no number here because they have no
   * `maxEdge` to derive one from: they are sampled from the map's own grids at
   * whatever resolution the map has.
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

/**
 * The caps, keyed on class, read from the vocabulary coilbox encodes to
 * (`./vendor/asset-vocabulary.json`, issue #165). `render` covers every
 * `render:<angle>`, since the angle is part of the identity and changes nothing
 * about what the picture may be.
 *
 * `maxBytes` is derived here rather than read, because the derivation is the
 * rule this file states and a number that arrived without it would be one
 * nobody here could account for. The vocabulary carries the same number and
 * `vocabulary.test.ts` holds the two together, so the two ways of getting it
 * cannot part company quietly.
 */
export const ASSET_CAPS: Record<string, AssetCap> = Object.fromEntries(
  Object.entries(vocabulary.classes).map(([variant, agreed]) => {
    // A type the hub cannot name a file after is one it cannot store, so this
    // is a refusal to start rather than a path that writes an object called
    // `<hash>.undefined`.
    if (!isAssetMime(agreed.mime)) {
      throw new Error(
        `The asset vocabulary encodes "${variant}" as ${agreed.mime}, which the hub has no extension for.`,
      );
    }

    return [
      variant,
      {
        mime: agreed.mime,
        maxEdge: agreed.maxEdgePx,
        maxBytes: agreed.maxEdgePx === null ? null : rawBytes(agreed.maxEdgePx),
        square: agreed.square,
        lossless: agreed.lossless,
        minBitDepth: agreed.minBitDepth,
        grayscale: agreed.grayscale,
      },
    ];
  }),
);

/** The cap for one variant, or null when the variant is not one the hub has a
 * class for. Every variant either side of the identity has one, so a null here
 * is a variant that should already have been refused as unspellable. */
export function capForVariant(variant: string): AssetCap | null {
  if (variant.startsWith(UNIT_RENDER_VARIANT_PREFIX)) return ASSET_CAPS.render;
  return ASSET_CAPS[variant] ?? null;
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
