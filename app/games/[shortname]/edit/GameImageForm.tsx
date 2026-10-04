"use client";

import { useActionState } from "react";
import { uploadGameImage } from "@/app/games/actions";
import type { GameImageKind } from "@/lib/api/gameBranding";
import { type GameImageUploadState, sendGameImage } from "@/lib/games/imageUpload";
import { Button } from "@/components/Button";

/**
 * One picture upload on a game's edit page, with the answer shown beside it
 * (#354).
 *
 * This form needs the bundle, unlike the rest of the page. A file over the
 * server action body limit never reaches the action, and the error Next.js
 * throws for it would replace the whole page. So the browser checks the size
 * before sending and turns anything thrown into a message here.
 */
/** What the button offers to upload, in the words the section above it uses. */
const UPLOAD_LABELS: Record<GameImageKind, string> = {
  logo: "logo",
  banner: "banner",
  card: "card art",
};

export function GameImageForm({ shortname, kind }: { shortname: string; kind: GameImageKind }) {
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
        <Button
          type="submit"
          disabled={pending}
        >
          {pending ? "Uploading…" : `Upload ${UPLOAD_LABELS[kind]}`}
        </Button>
      </div>
      {state ? (
        <p role={state.ok ? "status" : "alert"} className={`text-sm ${state.ok ? "text-neutral-300" : "text-red-400"}`}>
          {state.message}
        </p>
      ) : null}
    </form>
  );
}
