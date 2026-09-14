import { GAME_BRANDING_MAX_BYTES } from "@/lib/api/gameBranding";

/**
 * What the logo and banner forms on a game's edit page tell the person
 * uploading (#354). Before this, every failed upload returned nothing, so a
 * refused file looked like a form that did nothing.
 *
 * The server action and the form share these, so the size check the browser
 * makes reads the same as the one the server makes.
 */

/** The answer an upload form shows. Null before the first upload. */
export interface GameImageUploadState {
  ok: boolean;
  message: string;
}

export const UPLOAD_MESSAGES = {
  noFile: "Choose a PNG or WebP file to upload.",
  wrongType: "This file is not a PNG or WebP picture. Save it as PNG or WebP and try again.",
  signedOut: "You are signed out. Sign in, then upload the picture again.",
  notAllowed: "You can no longer change this game. Only its owner or a moderator can upload its pictures.",
  notSaved: "The picture could not be saved. Try again in a few minutes.",
  notSent: "The upload did not reach the hub. Reload the page and try again.",
} as const;

function kilobytes(bytes: number): string {
  return `${Math.ceil(bytes / 1024)} KB`;
}

function refused(message: string): GameImageUploadState {
  return { ok: false, message };
}

/** The refusal for a missing, empty or oversized file, or null when the file
 *  may be sent. What the bytes are is for the server to read. */
export function refuseImageFile(file: FormDataEntryValue | null): GameImageUploadState | null {
  if (!(file instanceof File) || file.size === 0) return refused(UPLOAD_MESSAGES.noFile);
  if (file.size > GAME_BRANDING_MAX_BYTES) {
    return refused(
      `This file is ${kilobytes(file.size)}. The largest picture you can upload is ` +
        `${kilobytes(GAME_BRANDING_MAX_BYTES)}. Make it smaller and try again.`,
    );
  }
  return null;
}

/**
 * Send an upload form to the server action, from the browser.
 *
 * The size is checked here first because Next.js refuses a server action body
 * over 1 MB before the action runs. That refusal is thrown in the browser, and
 * the page is replaced by an error screen. Anything else thrown on the way,
 * such as a dropped connection, becomes a message beside the form for the same
 * reason.
 */
export async function sendGameImage(
  send: (previous: GameImageUploadState | null, form: FormData) => Promise<GameImageUploadState>,
  previous: GameImageUploadState | null,
  form: FormData,
): Promise<GameImageUploadState> {
  const refusal = refuseImageFile(form.get("image"));
  if (refusal) return refusal;

  try {
    return await send(previous, form);
  } catch {
    return refused(UPLOAD_MESSAGES.notSent);
  }
}
