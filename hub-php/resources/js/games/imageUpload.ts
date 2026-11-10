import { GAME_BRANDING_MAX_BYTES, type GameImageKind, isGameImageKind } from "./branding";
import { IMAGE_HEADER_BYTES, readImageHeader } from "./imageHeader";
import { type ConvertResult, convertImageForUpload, IMAGE_TARGETS, planImageUpload } from "./imageResize";

/**
 * The browser half of a picture upload on a game's edit page, from the Next
 * app's `lib/games/imageUpload.ts`. The server half, and the rest of the
 * messages, are `App\Domain\Games\ImageUpload`.
 *
 * One change from the Next code. The Next form sent its upload to a server
 * action and stitched the conversion sentence onto the answer in the browser.
 * Here the form posts and the page reloads, so the sentence also rides along
 * in the `note` field and the server puts it in front of its own answer.
 */

/** The answer an upload form shows. */
export interface GameImageUploadState {
  ok: boolean;
  message: string;
}

export const UPLOAD_MESSAGES = {
  noFile: "Choose a PNG, WebP or JPEG file to upload.",
  notSent: "The upload did not reach the hub. Reload the page and try again.",
  noKind: "The upload did not say which picture it is. Reload the page and try again.",
} as const;

function refused(message: string): GameImageUploadState {
  return { ok: false, message };
}

/**
 * Send an upload form, after shrinking or converting the picture when it needs
 * it.
 *
 * A PNG or WebP already inside its display box and under the byte limit is
 * sent unchanged. Everything else, such as a JPEG, an oversized PNG or WebP, or
 * anything the header can't read, is handed to `convert` first. A WebP logo
 * always converts, because the link preview renderer cannot decode WebP.
 *
 * `convert` defaults to the real browser conversion and is only overridden in
 * tests.
 */
export async function sendGameImage(
  send: (previous: GameImageUploadState | null, form: FormData) => Promise<GameImageUploadState>,
  previous: GameImageUploadState | null,
  form: FormData,
  convert: (file: File, kind: GameImageKind) => Promise<ConvertResult> = convertImageForUpload,
): Promise<GameImageUploadState> {
  const entry = form.get("image");
  if (!(entry instanceof File) || entry.size === 0) return refused(UPLOAD_MESSAGES.noFile);
  const file = entry;

  // A lookup rather than a ternary, so a card is never shrunk to the banner's box.
  const named = String(form.get("kind") ?? "");
  if (!isGameImageKind(named)) return refused(UPLOAD_MESSAGES.noKind);
  const kind: GameImageKind = named;
  const header = readImageHeader(new Uint8Array(await file.slice(0, IMAGE_HEADER_BYTES).arrayBuffer()));
  const plan =
    kind === "logo" && header?.mime === "image/webp"
      ? "convert"
      : planImageUpload(header, file.size, GAME_BRANDING_MAX_BYTES, IMAGE_TARGETS[kind]);

  let note = "";
  if (plan === "convert") {
    const result = await convert(file, kind);
    if (!result.ok) return refused(result.message);
    form.set("image", result.file);
    form.set("note", result.message);
    note = `${result.message} `;
  }

  try {
    const sent = await send(previous, form);
    return { ok: sent.ok, message: `${note}${sent.message}`.trim() };
  } catch {
    return refused(UPLOAD_MESSAGES.notSent);
  }
}
