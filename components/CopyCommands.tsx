"use client";

import { useState } from "react";

const action =
  "shrink-0 rounded border border-neutral-800 px-3 py-1.5 text-xs text-neutral-300 transition-colors hover:border-neutral-600 hover:text-white active:border-neutral-500 active:text-white";

/**
 * Every autohost command in one block, with one button that copies the lot.
 *
 * A preset runs to forty commands or more, because SPADS sets one option per
 * `!bSet` and has nothing that takes several at once. Copying them one at a
 * time is the only part of that anybody can be spared, so the button hands
 * over all of them and the block keeps them in the order they go in.
 */
export function CopyCommands({ lines }: { lines: string[] }) {
  const [state, setState] = useState<"idle" | "copied" | "refused">("idle");
  const text = lines.join("\n");

  async function copy() {
    try {
      await navigator.clipboard.writeText(text);
      setState("copied");
    } catch {
      setState("refused");
    }
  }

  return (
    <div className="flex flex-col gap-2">
      {/* The button sits over the commands rather than beside them. A tweakdef
          line is thousands of characters wide, and a button in the same row
          would take its width off every line for the whole block's sake. */}
      <div className="relative rounded border border-neutral-800 bg-black p-3">
        {/* `select-all` so somebody who drags across it by hand still takes
            every line, never the part their pointer happened to cover. The
            right hand padding keeps the first lines clear of the button. */}
        <pre className="max-h-80 select-all overflow-auto pr-24 text-xs leading-relaxed text-neutral-100">
          {text}
        </pre>
        <button
          type="button"
          onClick={copy}
          className={`${action} absolute right-3 top-3 bg-black`}
        >
          <span aria-live="polite">
            {state === "copied" ? "Copied" : lines.length === 1 ? "Copy" : "Copy all"}
          </span>
        </button>
      </div>
      {state === "refused" ? (
        <p className="text-xs text-neutral-400">
          Your browser refused the copy. Click the commands to select them, then copy them
          yourself.
        </p>
      ) : null}
    </div>
  );
}
