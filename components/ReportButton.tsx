"use client";

import { useState } from "react";
import { useFormStatus } from "react-dom";
import { report } from "@/app/moderation/actions";

/** Dimmed and labelled while the report is on its way, so a second press is
 *  not a second report. A child of the form, which is where `useFormStatus`
 *  reads from. */
function SendButton() {
  const { pending } = useFormStatus();
  return (
    <button
      type="submit"
      disabled={pending}
      className="rounded border border-neutral-800 px-3 py-1.5 text-xs text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white disabled:opacity-60"
    >
      {pending ? "Sending…" : "Send"}
    </button>
  );
}

export function ReportButton({ itemId }: { itemId: string }) {
  const [open, setOpen] = useState(false);
  const [sent, setSent] = useState(false);

  if (sent) {
    return <p className="text-xs text-neutral-600">Reported. Thank you.</p>;
  }

  if (!open) {
    return (
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="self-start text-xs text-neutral-600 transition-colors hover:text-neutral-400 active:text-neutral-400"
      >
        Report this
      </button>
    );
  }

  return (
    <form
      action={async (form) => {
        await report(form);
        setSent(true);
      }}
      className="flex flex-col gap-2 rounded-md border border-neutral-800 bg-black p-3"
    >
      <input type="hidden" name="item_id" value={itemId} />
      <label className="text-xs text-neutral-500" htmlFor="reason">
        What is wrong with it? No account needed.
      </label>
      <textarea
        id="reason"
        name="reason"
        rows={3}
        required
        maxLength={1000}
        className="w-full rounded border border-neutral-800 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus:border-neutral-600 focus:outline-none"
      />
      <div className="flex gap-2">
        <SendButton />
        <button
          type="button"
          onClick={() => setOpen(false)}
          className="px-3 py-1.5 text-xs text-neutral-600 hover:text-neutral-400 active:text-neutral-400"
        >
          Cancel
        </button>
      </div>
    </form>
  );
}
