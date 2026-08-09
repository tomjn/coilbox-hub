"use client";

import { useActionState } from "react";
import { publish, type PublishState } from "./actions";

const field =
  "w-full rounded-md border border-neutral-800 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-600 focus:border-neutral-600 focus:outline-none";

export function PublishForm() {
  const [state, action, pending] = useActionState<PublishState, FormData>(
    publish,
    {},
  );

  if (state.publishedId) {
    return (
      <div className="flex flex-col gap-3 rounded-md border border-neutral-800 bg-neutral-950 p-6">
        <h2 className="text-lg font-medium">Published</h2>
        <p className="text-sm text-neutral-400">
          It is in the gallery. Browsing and importing are still being built, so
          there is nothing to look at yet.
        </p>
        <code className="text-xs text-neutral-500">{state.publishedId}</code>
      </div>
    );
  }

  return (
    <form action={action} className="flex flex-col gap-5">
      <label className="flex flex-col gap-2">
        <span className="text-sm font-medium">Share code</span>
        <span className="text-xs text-neutral-500">
          Copy it from Coilbox, then paste it here. Presets, challenges and setup
          packs all produce one.
        </span>
        <textarea
          name="code"
          rows={4}
          required
          spellCheck={false}
          placeholder="cbz1.…"
          className={`${field} font-mono`}
        />
      </label>

      <label className="flex flex-col gap-2">
        <span className="text-sm font-medium">Title</span>
        <input name="title" required maxLength={120} className={field} />
      </label>

      <label className="flex flex-col gap-2">
        <span className="text-sm font-medium">Description</span>
        <span className="text-xs text-neutral-500">
          What it is for, and why somebody would want it.
        </span>
        <textarea
          name="description"
          rows={4}
          maxLength={2000}
          className={field}
        />
      </label>

      <label className="flex flex-col gap-2">
        <span className="text-sm font-medium">Tags</span>
        <span className="text-xs text-neutral-500">
          Comma separated, up to eight. For the things a filter cannot work out
          on its own.
        </span>
        <input name="tags" className={field} placeholder="eco, 8v8, beginner" />
      </label>

      {state.error ? (
        <p role="alert" className="text-sm text-red-400">
          {state.error}
        </p>
      ) : null}

      <button
        type="submit"
        disabled={pending}
        className="self-start rounded-md bg-neutral-100 px-5 py-2.5 text-sm font-medium text-neutral-900 transition-colors hover:bg-white disabled:opacity-60"
      >
        {pending ? "Publishing…" : "Publish"}
      </button>
    </form>
  );
}
