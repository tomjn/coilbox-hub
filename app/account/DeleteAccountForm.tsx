"use client";

import { useActionState } from "react";
import { Button } from "@/components/Button";
import { type DeleteState, deleteAccount } from "./actions";

const field =
  "w-full rounded-md border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-400 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400";

export function DeleteAccountForm({ name }: { name: string }) {
  const [state, action, pending] = useActionState<DeleteState, FormData>(
    deleteAccount,
    {},
  );

  return (
    <form action={action} className="flex flex-col gap-3">
      <label className="flex flex-col gap-2">
        <span className="text-sm text-neutral-400">
          To confirm, type <span className="text-neutral-100">{name}</span>
        </span>
        <input
          name="confirm"
          required
          autoComplete="off"
          spellCheck={false}
          className={field}
        />
      </label>

      {state.error ? (
        <p role="alert" className="text-sm text-red-400">
          {state.error}
        </p>
      ) : null}

      <Button
        type="submit"
        variant="destructive"
        disabled={pending}
        className="self-start"
      >
        Delete everything
      </Button>
    </form>
  );
}
