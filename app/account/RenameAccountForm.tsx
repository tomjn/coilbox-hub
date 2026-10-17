"use client";

import { useActionState } from "react";
import { Button } from "@/components/Button";
import { DISPLAY_NAME_MAX, DISPLAY_NAME_MIN } from "@/lib/account/displayName";
import { type RenameState, renameAccount } from "./actions";

const field =
  "w-full rounded-md border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-400 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400";

export function RenameAccountForm({ name }: { name: string }) {
  const [state, action, pending] = useActionState<RenameState, FormData>(
    renameAccount,
    {},
  );

  return (
    <form action={action} className="flex flex-col gap-3">
      <label className="flex flex-col gap-2">
        <span className="text-sm text-neutral-400">Display name</span>
        <input
          name="displayName"
          required
          defaultValue={state.typed ?? name}
          autoComplete="nickname"
          spellCheck={false}
          aria-describedby="display-name-help"
          className={field}
        />
      </label>
      <p id="display-name-help" className="text-sm text-neutral-400">
        {DISPLAY_NAME_MIN} to {DISPLAY_NAME_MAX} characters. Nobody else can
        hold the same name, whatever the capital letters.
      </p>

      {state.error ? (
        <p role="alert" className="text-sm text-red-400">
          {state.error}
        </p>
      ) : null}
      {state.saved && !pending ? (
        <p role="status" className="text-sm text-green-400">
          Saved. You are now {state.saved}.
        </p>
      ) : null}

      <Button type="submit" disabled={pending} className="self-start">
        {pending ? "Saving..." : "Save name"}
      </Button>
    </form>
  );
}
