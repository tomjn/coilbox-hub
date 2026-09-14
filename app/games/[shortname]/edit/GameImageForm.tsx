"use client";

import { useActionState } from "react";
import { uploadGameImage } from "@/app/games/actions";
import { type GameImageUploadState, sendGameImage } from "@/lib/games/imageUpload";

/**
 * The logo or banner upload on a game's edit page, with the answer shown
 * beside it (#354).
 *
 * This form needs the bundle, unlike the rest of the page. A file over the
 * server action body limit never reaches the action, and the error Next.js
 * throws for it would replace the whole page. So the browser checks the size
 * before sending and turns anything thrown into a message here.
 */
export function GameImageForm({ shortname, kind }: { shortname: string; kind: "logo" | "banner" }) {
  const [state, action, pending] = useActionState<GameImageUploadState | null, FormData>(
    (previous, form) => sendGameImage(uploadGameImage, previous, form),
    null,
  );

  return (
    <form action={action} className="flex flex-col gap-3">
      <input type="hidden" name="shortname" value={shortname} />
      <input type="hidden" name="kind" value={kind} />
      <div className="flex items-end gap-3">
        <input
          type="file"
          name="image"
          accept="image/png,image/webp,image/jpeg"
          required
          className="text-sm text-neutral-300"
        />
        <button
          type="submit"
          disabled={pending}
          className="rounded-md border border-neutral-800 px-4 py-2 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white disabled:opacity-60"
        >
          {pending ? "Uploading…" : kind === "logo" ? "Upload logo" : "Upload banner"}
        </button>
      </div>
      {state ? (
        <p role={state.ok ? "status" : "alert"} className={`text-sm ${state.ok ? "text-neutral-300" : "text-red-400"}`}>
          {state.message}
        </p>
      ) : null}
    </form>
  );
}
