"use client";

import { useActionState } from "react";
import { removeGameImage } from "@/app/games/actions";
import { type GameImageUploadState, REMOVE_MESSAGES } from "@/lib/games/imageUpload";

/**
 * The way to take a game's logo or banner off again (#360), with a
 * confirmation first and the answer shown after.
 *
 * The confirmation is a native disclosure, so it is keyboard reachable and
 * holds no state of its own. The component stays mounted once the picture is
 * gone, with `present` false, so the message saying so is still there to read.
 */
export function GameImageRemoveForm({
  shortname,
  kind,
  present,
}: {
  shortname: string;
  kind: "logo" | "banner";
  present: boolean;
}) {
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
          <summary className="w-fit cursor-pointer list-none rounded-md border border-neutral-800 px-4 py-2 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400 [&::-webkit-details-marker]:hidden">
            Remove {kind}
          </summary>
          <form action={action} className="mt-3 flex flex-col gap-3 rounded-md border border-neutral-800 p-4">
            <input type="hidden" name="shortname" value={shortname} />
            <input type="hidden" name="kind" value={kind} />
            <p className="text-sm text-neutral-300">
              Remove this game&apos;s {kind}? The game page, the games list and link previews stop showing it
              straight away. To show a {kind} again, upload one.
            </p>
            <button
              type="submit"
              disabled={pending}
              className="self-start rounded-md border border-red-900 px-4 py-2 text-sm text-red-300 transition-colors hover:border-red-700 active:border-red-600 hover:text-red-200 active:text-red-200 disabled:opacity-60"
            >
              {pending ? "Removing…" : `Yes, remove the ${kind}`}
            </button>
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
