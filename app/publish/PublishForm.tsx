"use client";

import { useActionState, useRef, useState } from "react";
import { publish, type PublishState } from "./actions";

const field =
  "w-full rounded-md border border-neutral-800 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-600 focus:border-neutral-600 focus:outline-none";

export function PublishForm() {
  const [state, action, pending] = useActionState<PublishState, FormData>(
    publish,
    {},
  );

  // Scenarios can only be exported as a file, and a saved export of anything else
  // is a file too. Reading it here rather than uploading it keeps one validation
  // path on the server instead of two.
  const codeRef = useRef<HTMLTextAreaElement>(null);
  const [fileName, setFileName] = useState<string | null>(null);

  async function readFile(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    if (!file || !codeRef.current) return;
    codeRef.current.value = await file.text();
    setFileName(file.name);
  }

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
        <span className="text-sm font-medium">What you are sharing</span>
        <span className="text-xs text-neutral-500">
          Whatever Coilbox gave you. A share link, a bare code from a setup pack,
          or an exported JSON file.
        </span>
        <textarea
          ref={codeRef}
          name="code"
          rows={4}
          required
          spellCheck={false}
          defaultValue={state.values?.code ?? ""}
          placeholder="coilbox://import?code=…"
          className={`${field} font-mono`}
        />
        <span className="flex items-center gap-3 text-xs text-neutral-500">
          <label className="cursor-pointer rounded-md border border-neutral-800 px-3 py-1.5 transition-colors hover:border-neutral-600 hover:text-neutral-300">
            Or choose an exported file
            <input
              type="file"
              accept="application/json,.json"
              onChange={readFile}
              className="hidden"
            />
          </label>
          {fileName ? <span>Loaded {fileName}</span> : null}
        </span>
      </label>

      <label className="flex flex-col gap-2">
        <span className="text-sm font-medium">Title</span>
        <input
          name="title"
          required
          maxLength={120}
          defaultValue={state.values?.title ?? ""}
          className={field}
        />
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
          defaultValue={state.values?.description ?? ""}
          className={field}
        />
      </label>

      <label className="flex flex-col gap-2">
        <span className="text-sm font-medium">Tags</span>
        <span className="text-xs text-neutral-500">
          Comma separated, up to eight. For the things a filter cannot work out
          on its own.
        </span>
        <input
          name="tags"
          defaultValue={state.values?.tags ?? ""}
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
        {pending ? "Publishing…" : "Publish"}
      </button>
    </form>
  );
}
