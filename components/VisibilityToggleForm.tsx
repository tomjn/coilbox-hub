"use client";

import type { ReactNode } from "react";
import { useActionState } from "react";
import type { GameFormState } from "@/lib/games/formState";

/**
 * One hide or show control, wherever it appears: a game's edit page, or the
 * moderation queue's hide and unhide lists (#362). `setGameVisibility` and
 * `setVersionVisibility` (`@/app/games/actions`) share this, since both
 * answer a toggle the same way - what the hidden fields are and what the
 * button says is all that differs between the two.
 *
 * `children`, before the button, is for the moderation queue's two forms
 * that ask for a shortname (and a release) by hand rather than carrying it as
 * a hidden field for an already-known row.
 */
export function VisibilityToggleForm({
  action,
  fields,
  label,
  pendingLabel,
  buttonClassName,
  formClassName = "flex flex-col gap-1.5",
  children,
}: {
  action: (previous: GameFormState | null, form: FormData) => Promise<GameFormState>;
  fields: Record<string, string>;
  label: string;
  pendingLabel: string;
  buttonClassName: string;
  formClassName?: string;
  children?: ReactNode;
}) {
  const [state, formAction, pending] = useActionState<GameFormState | null, FormData>(action, null);

  return (
    <form action={formAction} className={formClassName}>
      {Object.entries(fields).map(([name, value]) => (
        <input key={name} type="hidden" name={name} value={value} />
      ))}
      {children}
      <button type="submit" disabled={pending} className={buttonClassName}>
        {pending ? pendingLabel : label}
      </button>
      {state ? (
        <p
          role={state.ok ? "status" : "alert"}
          className={`w-full text-sm ${state.ok ? "text-neutral-300" : "text-red-400"}`}
        >
          {state.message}
        </p>
      ) : null}
    </form>
  );
}
