"use client";

import { useActionState } from "react";
import { setSnippet } from "@/app/games/actions";
import type { GameFormState } from "@/lib/games/formState";

/** A unit's author snippet, with the save answered beside the button (#362). */
export function SnippetForm({
  shortname,
  unitName,
  snippet,
}: {
  shortname: string;
  unitName: string;
  snippet: string;
}) {
  const [state, action, pending] = useActionState<GameFormState | null, FormData>(setSnippet, null);

  return (
    <form action={action} className="flex flex-col gap-2">
      <input type="hidden" name="shortname" value={shortname} />
      <input type="hidden" name="unit_name" value={unitName} />
      <textarea
        name="snippet"
        rows={3}
        maxLength={2000}
        defaultValue={snippet}
        placeholder="A sentence about this unit, in your own words"
        aria-label="Author snippet"
        className="rounded-md border border-neutral-800 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-500 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400"
      />
      <div className="flex items-center gap-3">
        <button
          type="submit"
          disabled={pending}
          className="self-start rounded-md border border-neutral-800 px-4 py-2 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white disabled:opacity-60"
        >
          {pending ? "Saving…" : "Save snippet"}
        </button>
        {state ? (
          <p role={state.ok ? "status" : "alert"} className={`text-sm ${state.ok ? "text-neutral-300" : "text-red-400"}`}>
            {state.message}
          </p>
        ) : null}
      </div>
    </form>
  );
}
