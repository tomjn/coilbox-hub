import type { GameImageKind } from "@/lib/api/gameBranding";
import { GAME_BRANDING_MAX_BYTES } from "@/lib/api/gameBranding";
import type { ImageHeader } from "@/lib/assets/imageHeader";

/**
 * Shrinking and converting a logo or banner in the browser before it uploads
 * (#356, #359).
 *
 * The server only ever stores PNG or WebP, under {@link GAME_BRANDING_MAX_BYTES}
 * (`lib/api/gameBranding.ts`), and it keeps refusing anything else, because a
 * request can skip the browser. Getting a photo under that limit, and getting a
 * JPEG accepted at all, is this file's job: decode whatever was chosen, draw it
 * no larger than the game page ever shows it, and encode it as
 * {@link targetFormat} says.
 *
 * ## Target dimensions
 *
 * Each box is the largest CSS size the picture is drawn at, doubled for a 2x
 * screen. A picture already inside its box, in a format the server already
 * accepts and under the byte limit, uploads unchanged - {@link planImageUpload}
 * is that check, and it is the only thing standing between a small WebP and a
 * pointless re-encode.
 *
 * - Logo: `h-16 w-16` (64px) on the game page (`app/games/[shortname]/page.tsx`),
 *   `h-12` (48px) on the game card (`components/GameCard.tsx`), and 128x128
 *   flat on the link preview (`app/games/[shortname]/opengraph-image.tsx`,
 *   which renders at a fixed pixel size with no notion of device pixels). The
 *   page's 64px doubled for 2x screens and the preview's 128px land on the same
 *   number, so 128 is the box.
 * - Banner: `h-40` (160px) below the `sm` breakpoint and `sm:h-56` (224px) at
 *   and above it, always `w-full` with no width cap of its own - it is the one
 *   element on the page wider than the `max-w-5xl` (1024px) column everything
 *   else sits in. There is no CSS width to double for 2x, so the box uses 2x
 *   that column's width, 2048px, as the widest a banner is ever worth storing
 *   at. Height is 224px doubled, 448px.
 */

export const IMAGE_TARGETS: Record<GameImageKind, { maxWidth: number; maxHeight: number }> = {
  logo: { maxWidth: 128, maxHeight: 128 },
  banner: { maxWidth: 2048, maxHeight: 448 },
};

/**
 * The WebP quality steps tried, in order, before the picture is shrunk again.
 * Starting point and floor are a chosen search order, not a measured number.
 * The only measured, enforced figure is {@link GAME_BRANDING_MAX_BYTES} itself,
 * which every step is checked against. 0.82 is close to visually lossless for a
 * photo. 0.35 is roughly where WebP photographic quality starts looking rough.
 * Four steps between them is enough tries without stalling the browser.
 */
export const QUALITY_STEPS: readonly number[] = [0.82, 0.68, 0.5, 0.35];

/** How much smaller each dimension round is than the last. */
export const DIMENSION_STEP_FACTOR = 0.75;

/** How many times dimensions are stepped down before giving up. */
export const MAX_DIMENSION_ROUNDS = 4;

/** Below this on either edge, shrinking further stops being worth it. */
export const MIN_DIMENSION = 32;

/** A byte count in the same units the rest of this form's messages use
 *  (`lib/games/imageUpload.ts`), so a resize note and a refusal read alike. */
function kilobytes(bytes: number): string {
  return `${Math.ceil(bytes / 1024)} KB`;
}

/**
 * Scale `width`x`height` down to fit inside the box, preserving aspect ratio.
 * Never scales up: a picture smaller than its box is drawn at its own size.
 */
export function fitWithinBox(
  width: number,
  height: number,
  maxWidth: number,
  maxHeight: number,
): { width: number; height: number } {
  if (width <= 0 || height <= 0) return { width, height };
  const scale = Math.min(1, maxWidth / width, maxHeight / height);
  if (scale >= 1) return { width, height };
  return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)) };
}

export type ImageUploadPlan = "unchanged" | "convert";

/**
 * Whether a chosen file may be sent as-is, or needs the browser to shrink or
 * convert it first.
 *
 * `header` is read from the real bytes (`readImageHeader`), not the file's
 * declared type, so a mislabelled file cannot claim the fast path. A JPEG
 * always reads as null here, because {@link readImageHeader} only recognises
 * PNG and WebP, so it always takes the convert path - which is exactly what
 * turns it into an upload the server will accept (#356).
 */
export function planImageUpload(
  header: ImageHeader | null,
  byteLength: number,
  maxBytes: number,
  box: { maxWidth: number; maxHeight: number },
): ImageUploadPlan {
  if (!header) return "convert";
  if (byteLength > maxBytes) return "convert";
  if (header.width > box.maxWidth || header.height > box.maxHeight) return "convert";
  return "unchanged";
}

/** What a resized or converted picture's answer says happened, with the
 *  before and after size (#359's ask). A picture that only needed shrinking,
 *  not a format change, says so. Anything else names the format it came from
 *  and the format it became. */
export function describeConversion(
  originalMime: string,
  originalBytes: number,
  resultBytes: number,
  resultMime: string,
): string {
  const before = kilobytes(originalBytes);
  const after = kilobytes(resultBytes);
  if (originalMime === resultMime) return `Shrunk from ${before} to ${after}.`;
  const label =
    originalMime === "image/jpeg"
      ? "JPEG"
      : originalMime === "image/png"
        ? "PNG"
        : originalMime === "image/webp"
          ? "WebP"
          : "the original file";
  const outputLabel = resultMime === "image/webp" ? "WebP" : "PNG";
  return `Converted from ${label} (${before}) to ${outputLabel} (${after}).`;
}

/**
 * The MIME type a converted upload encodes to.
 *
 * A logo is embedded straight into the link preview's renderer
 * (`app/games/[shortname]/opengraph-image.tsx`), which cannot decode WebP
 * (#366). So a logo always converts to PNG - itself small enough at the
 * 128x128 logo box to stay well under the byte limit uncompressed. A banner
 * never reaches that renderer, so it keeps WebP's smaller size.
 */
export function targetFormat(kind: GameImageKind): "image/png" | "image/webp" {
  return kind === "logo" ? "image/png" : "image/webp";
}

export interface ConvertSuccess {
  ok: true;
  file: File;
  message: string;
}

export interface ConvertFailure {
  ok: false;
  message: string;
}

export type ConvertResult = ConvertSuccess | ConvertFailure;

/** One encode of `bitmap` at `width`x`height`, in `format` (falling back to
 *  PNG when the browser cannot produce `format`). Tries `OffscreenCanvas`
 *  first, since it needs no element in the page, and falls back to a
 *  `<canvas>` for engines without it. Neither call fills a background before
 *  drawing, so transparency in the source survives. */
async function encodeAttempt(
  bitmap: ImageBitmap,
  width: number,
  height: number,
  quality: number | undefined,
  format: "image/png" | "image/webp",
): Promise<Blob> {
  if (typeof OffscreenCanvas !== "undefined") {
    const canvas = new OffscreenCanvas(width, height);
    const ctx = canvas.getContext("2d");
    if (!ctx) throw new Error("2d context unavailable");
    ctx.drawImage(bitmap, 0, 0, width, height);
    return await canvas.convertToBlob({ type: format, quality });
  }

  const canvas = document.createElement("canvas");
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext("2d");
  if (!ctx) throw new Error("2d context unavailable");
  ctx.drawImage(bitmap, 0, 0, width, height);
  return await new Promise<Blob>((resolve, reject) => {
    canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error("canvas.toBlob produced nothing"))), format, quality);
  });
}

/**
 * Shrink and, when the source is not already WebP, convert `file` for upload
 * as a game's `kind`. Browser only - decoding and canvas encoding both need a
 * real engine, which is why this is exercised by hand in one (#356, #359) and
 * not by `bun test`. {@link fitWithinBox}, {@link planImageUpload} and
 * {@link describeConversion} carry the parts that can run headless.
 *
 * Tries each quality step in {@link QUALITY_STEPS} at the current dimensions,
 * then steps the dimensions down by {@link DIMENSION_STEP_FACTOR} and tries
 * again, up to {@link MAX_DIMENSION_ROUNDS} times or until an edge would drop
 * below {@link MIN_DIMENSION}. Stops as soon as one attempt lands at or under
 * {@link GAME_BRANDING_MAX_BYTES}.
 *
 * Some engines still answer `canvas.toBlob(format)` with a PNG instead of the
 * WebP a banner asked for (Safari added WebP encoding late). That is read off
 * the returned blob's own `type`, not assumed from what was asked for. Once it
 * happens, quality steps are skipped for the rest of the attempt, since PNG
 * has none to give, and only the dimension gets smaller. A logo already asks
 * for PNG ({@link targetFormat}), so this only ever fires for a banner.
 */
export async function convertImageForUpload(file: File, kind: GameImageKind): Promise<ConvertResult> {
  let bitmap: ImageBitmap;
  try {
    bitmap = await createImageBitmap(file);
  } catch {
    return { ok: false, message: "This file could not be read as a picture. Save it as PNG, WebP or JPEG and try again." };
  }

  try {
    const box = IMAGE_TARGETS[kind];
    let { width, height } = fitWithinBox(bitmap.width, bitmap.height, box.maxWidth, box.maxHeight);
    const format = targetFormat(kind);
    let formatSupported = format === "image/webp";
    let smallest: Blob | null = null;

    for (let round = 0; round < MAX_DIMENSION_ROUNDS; round++) {
      const qualities = formatSupported ? QUALITY_STEPS : [undefined];

      for (const quality of qualities) {
        let blob: Blob;
        try {
          blob = await encodeAttempt(bitmap, width, height, quality, format);
        } catch {
          return { ok: false, message: "This picture could not be converted in your browser. Try a different browser or a smaller picture." };
        }

        if (blob.type !== format) formatSupported = false;
        if (!smallest || blob.size < smallest.size) smallest = blob;

        if (blob.size <= GAME_BRANDING_MAX_BYTES) {
          const ext = blob.type === "image/webp" ? "webp" : "png";
          return {
            ok: true,
            file: new File([blob], `${kind}.${ext}`, { type: blob.type }),
            message: describeConversion(file.type, file.size, blob.size, blob.type),
          };
        }

        if (!formatSupported) break;
      }

      width = Math.max(MIN_DIMENSION, Math.round(width * DIMENSION_STEP_FACTOR));
      height = Math.max(MIN_DIMENSION, Math.round(height * DIMENSION_STEP_FACTOR));
      if (width <= MIN_DIMENSION && height <= MIN_DIMENSION) break;
    }

    const smallestSize = smallest ? kilobytes(smallest.size) : kilobytes(file.size);
    return {
      ok: false,
      message: `This picture is still ${smallestSize} after shrinking it as far as the hub allows. Try a smaller or simpler picture.`,
    };
  } finally {
    bitmap.close();
  }
}
