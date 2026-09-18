"use client";

import { useActionState } from "react";
import { editGameDetails } from "@/app/games/actions";
import type { GameFormState } from "@/lib/games/formState";
import type { GameLink } from "@/lib/games/catalog";

/**
 * The words on a game's edit page: display name, description and links. A
 * client component only for the save message beside the button (#362) -
 * everything else here still works exactly as a plain form.
 *
 * Where the game is downloaded from used to be a fieldset here. It became an
 * ordered list with its own form and its own save in #396, the way the
 * conquest factions did, because a list is reordered and resized in the browser
 * before it is ever sent.
 */

const CONTROL =
  "w-full rounded-md border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-500 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400";

const LABEL = "text-xs uppercase tracking-wide text-neutral-400";

const ROWS = [0, 1, 2, 3, 4];

export function GameDetailsForm({
  shortname,
  displayName,
  description,
  links,
}: {
  shortname: string;
  displayName: string;
  description: string;
  links: GameLink[];
}) {
  const [state, action, pending] = useActionState<GameFormState | null, FormData>(editGameDetails, null);

  return (
    <form action={action} className="flex flex-col gap-4">
      <input type="hidden" name="shortname" value={shortname} />
      <div className="flex flex-col gap-1.5">
        <label htmlFor="display_name" className={LABEL}>
          Display name
        </label>
        <input
          id="display_name"
          name="display_name"
          defaultValue={displayName}
          maxLength={256}
          className={CONTROL}
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <label htmlFor="description" className={LABEL}>
          Description
        </label>
        <textarea
          id="description"
          name="description"
          defaultValue={description}
          maxLength={4000}
          rows={5}
          className={CONTROL}
          aria-describedby="description-help"
        />
        <p id="description-help" className="text-xs text-neutral-500">
          A blank line starts a new paragraph, one line break keeps text on the next line.
          Wrap words in **two asterisks** for bold, and *one asterisk* or _underscores_ for
          italic. Nothing else formats - no links, headings or HTML.
        </p>
      </div>

      <fieldset className="flex flex-col gap-2">
        <legend className={LABEL}>Links</legend>
        {ROWS.map((row) => (
          <div key={row} className="flex gap-2">
            <input
              name="label"
              placeholder="Label"
              defaultValue={links[row]?.label ?? ""}
              aria-label={`Link ${row + 1} label`}
              className={`${CONTROL} w-40`}
            />
            <input
              name="url"
              placeholder="https://"
              type="url"
              defaultValue={links[row]?.url ?? ""}
              aria-label={`Link ${row + 1} URL`}
              className={CONTROL}
            />
          </div>
        ))}
      </fieldset>

      <div className="flex items-center gap-3">
        <button
          type="submit"
          disabled={pending}
          className="self-start rounded-md border border-neutral-800 px-4 py-2 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white disabled:opacity-60"
        >
          {pending ? "Saving…" : "Save"}
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
