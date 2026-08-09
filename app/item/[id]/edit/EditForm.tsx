"use client";

import { useActionState } from "react";
import { type EditState, saveItem } from "./actions";

const field =
  "w-full rounded-md border border-neutral-800 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-600 focus:border-neutral-600 focus:outline-none";

export function EditForm({
  id,
  title,
  description,
  tags,
}: {
  id: string;
  title: string;
  description: string;
  tags: string[];
}) {
  const [state, action, pending] = useActionState<EditState, FormData>(
    saveItem,
    {},
  );

  return (
    <form action={action} className="flex flex-col gap-5">
      <input type="hidden" name="id" value={id} />

      <label className="flex flex-col gap-2">
        <span className="text-sm font-medium">Title</span>
        <input
          name="title"
          required
          maxLength={120}
          defaultValue={title}
          className={field}
        />
      </label>

      <label className="flex flex-col gap-2">
        <span className="text-sm font-medium">Description</span>
        <textarea
          name="description"
          rows={5}
          maxLength={2000}
          defaultValue={description}
          className={field}
        />
      </label>

      <label className="flex flex-col gap-2">
        <span className="text-sm font-medium">Tags</span>
        <input
          name="tags"
          defaultValue={tags.join(", ")}
          className={field}
          placeholder="eco, 8v8, beginner"
        />
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
        {pending ? "Saving…" : "Save"}
      </button>
    </form>
  );
}
