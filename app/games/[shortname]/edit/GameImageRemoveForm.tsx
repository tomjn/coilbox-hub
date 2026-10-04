"use client";

import { useActionState } from "react";
import { removeGameImage } from "@/app/games/actions";
import type { GameImageKind } from "@/lib/api/gameBranding";
import { type GameImageUploadState, REMOVE_MESSAGES } from "@/lib/games/imageUpload";
import { Button, buttonClass } from "@/components/Button";

/**
 * The way to take one of a game's pictures off again (#360), with a
 * confirmation first and the answer shown after.
 *
 * The confirmation is a native disclosure, so it is keyboard reachable and
 * holds no state of its own. The component stays mounted once the picture is
 * gone, with `present` false, so the message saying so is still there to read.
 */

/** What to call the picture in this form's sentences. The kind itself reads
 *  wrongly for the third one: "remove this game's card" is not what the button
 *  does. */
const LABELS: Record<GameImageKind, string> = {
  logo: "logo",
  banner: "banner",
  card: "card art",
};

export function GameImageRemoveForm({
  shortname,
  kind,
  present,
}: {
  shortname: string;
  kind: GameImageKind;
  present: boolean;
}) {
  const label = LABELS[kind];
  const [state, action, pending] = useActionState<GameImageUploadState | null, FormData>(
    async (previous, form) => {
      try {
        return await removeGameImage(previous, form);
      } catch {
        return { ok: false, message: REMOVE_MESSAGES.notSent };
      }
    },
    null,
  );

  return (
    <div className="flex flex-col gap-3">
      {present ? (
        <details>
          <summary className={buttonClass("ghost", { className: "w-fit cursor-pointer list-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400 [&::-webkit-details-marker]:hidden" })}>
            Remove {label}
          </summary>
          <form action={action} className="mt-3 flex flex-col gap-3 rounded-md border border-neutral-800 p-4">
            <input type="hidden" name="shortname" value={shortname} />
            <input type="hidden" name="kind" value={kind} />
            <p className="text-sm text-neutral-300">
              Remove this game&apos;s {label}? The game page, the games list and link previews stop showing
              it straight away. To show {label} again, upload a picture.
            </p>
            <Button
              type="submit"
              disabled={pending}
              variant="destructive"
              className="self-start"
            >
              {pending ? "Removing…" : `Yes, remove the ${label}`}
            </Button>
          </form>
        </details>
      ) : null}
      {state ? (
        <p role={state.ok ? "status" : "alert"} className={`text-sm ${state.ok ? "text-neutral-300" : "text-red-400"}`}>
          {state.message}
        </p>
      ) : null}
    </div>
  );
}
