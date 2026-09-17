import type { GameImageKind } from "@/lib/api/gameBranding";
import { GAME_BRANDING_MAX_BYTES, isGameImageKind } from "@/lib/api/gameBranding";
import { IMAGE_HEADER_BYTES, readImageHeader } from "@/lib/assets/imageHeader";
import { type ConvertResult, convertImageForUpload, IMAGE_TARGETS, planImageUpload } from "@/lib/games/imageResize";

/**
 * What the logo and banner forms on a game's edit page tell the person
 * uploading (#354). Before this, every failed upload returned nothing, so a
 * refused file looked like a form that did nothing.
 *
 * The server action and the form share these, so the size check the browser
 * makes reads the same as the one the server makes.
 *
 * A file that needs shrinking or converting (#356, #359) goes through
 * `./imageResize` first, which is where that work and its own messages live.
 * This file only decides whether a chosen file needs that detour, and stitches
 * its answer onto the server's.
 */

/** The answer an upload form shows. Null before the first upload. */
export interface GameImageUploadState {
  ok: boolean;
  message: string;
}

export const UPLOAD_MESSAGES = {
  noFile: "Choose a PNG, WebP or JPEG file to upload.",
  wrongType: "This file is not a PNG or WebP picture. Save it as PNG or WebP and try again.",
  signedOut: "You are signed out. Sign in, then upload the picture again.",
  notAllowed: "You can no longer change this game. Only its owner or a moderator can upload its pictures.",
  notSaved: "The picture could not be saved. Try again in a few minutes.",
  notSent: "The upload did not reach the hub. Reload the page and try again.",
  noKind: "The upload did not say which picture it is. Reload the page and try again.",
} as const;

/** What the remove control beside each upload form says (#360). Same shape of
 *  answer as an upload, so both forms show their messages the same way. */
export const REMOVE_MESSAGES = {
  signedOut: "You are signed out. Sign in, then remove the picture again.",
  notAllowed: "You can no longer change this game. Only its owner or a moderator can remove its pictures.",
  notSaved: "The picture could not be removed. Try again in a few minutes.",
  notSent: "The removal did not reach the hub. Reload the page and try again.",
} as const;

function kilobytes(bytes: number): string {
  return `${Math.ceil(bytes / 1024)} KB`;
}

function refused(message: string): GameImageUploadState {
  return { ok: false, message };
}

/** The refusal for a missing or empty file, or null when a file is there.
 *  Shared by {@link refuseImageFile} and {@link sendGameImage}: the server
 *  still checks the size on the finished upload, but the browser no longer
 *  refuses a big file on sight, since shrinking it is the point of #359. */
function refuseMissingFile(file: FormDataEntryValue | null): GameImageUploadState | null {
  if (!(file instanceof File) || file.size === 0) return refused(UPLOAD_MESSAGES.noFile);
  return null;
}

/** The refusal for a missing, empty or oversized file, or null when the file
 *  may be sent. What the bytes are is for the server to read. Used server side
 *  (`app/games/actions.ts`) as the last check on what actually arrived. */
export function refuseImageFile(file: FormDataEntryValue | null): GameImageUploadState | null {
  const missing = refuseMissingFile(file);
  if (missing) return missing;
  const picked = file as File;
  if (picked.size > GAME_BRANDING_MAX_BYTES) {
    return refused(
      `This file is ${kilobytes(picked.size)}. The largest picture you can upload is ` +
        `${kilobytes(GAME_BRANDING_MAX_BYTES)}. Make it smaller and try again.`,
    );
  }
  return null;
}

/**
 * Send an upload form to the server action, from the browser.
 *
 * A PNG or WebP already inside its display box and under the byte limit is
 * sent unchanged. Everything else - a JPEG, an oversized PNG or WebP, or
 * anything the header can't read - is handed to `convert` first, which shrinks
 * or converts it and reports what it did. Its answer, when it succeeds, is
 * stitched onto the server's so the person sees both.
 *
 * The size is checked before sending, unchanged or converted, because Next.js
 * refuses a server action body over 1 MB before the action runs, throwing in
 * the browser and replacing the page with an error screen. Anything else
 * thrown on the way, such as a dropped connection, becomes a message beside
 * the form for the same reason.
 *
 * `convert` defaults to the real browser conversion (`./imageResize`) and is
 * only ever overridden in tests, the same way `send` already was.
 */
export async function sendGameImage(
  send: (previous: GameImageUploadState | null, form: FormData) => Promise<GameImageUploadState>,
  previous: GameImageUploadState | null,
  form: FormData,
  convert: (file: File, kind: GameImageKind) => Promise<ConvertResult> = convertImageForUpload,
): Promise<GameImageUploadState> {
  const entry = form.get("image");
  const missing = refuseMissingFile(entry);
  if (missing) return missing;
  const file = entry as File;

  // A lookup rather than a ternary. The two-way form this replaces read every
  // kind that was not "logo" as "banner", so a card would have been shrunk to
  // the banner's box and sent under the banner's name.
  const named = String(form.get("kind") ?? "");
  if (!isGameImageKind(named)) return refused(UPLOAD_MESSAGES.noKind);
  const kind: GameImageKind = named;
  const headerBytes = new Uint8Array(await file.slice(0, IMAGE_HEADER_BYTES).arrayBuffer());
  const header = readImageHeader(headerBytes);
  // A WebP logo always converts, even one already inside its box and under the
  // byte limit: it feeds the link preview's renderer, which cannot decode
  // WebP (#366), so `convert` (`./imageResize`) must turn it into PNG rather
  // than let it upload unchanged.
  const plan =
    kind === "logo" && header?.mime === "image/webp"
      ? "convert"
      : planImageUpload(header, file.size, GAME_BRANDING_MAX_BYTES, IMAGE_TARGETS[kind]);

  let note = "";
  if (plan === "convert") {
    const result = await convert(file, kind);
    if (!result.ok) return refused(result.message);
    form.set("image", result.file);
    note = `${result.message} `;
  }

  try {
    const sent = await send(previous, form);
    return { ok: sent.ok, message: `${note}${sent.message}`.trim() };
  } catch {
    return refused(UPLOAD_MESSAGES.notSent);
  }
}
