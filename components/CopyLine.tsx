"use client";

import { useState } from "react";

const action =
  "shrink-0 rounded border border-neutral-800 px-3 py-1.5 text-xs text-neutral-300 transition-colors hover:border-neutral-600 hover:text-white active:border-neutral-500 active:text-white";

/** `!bset tweakdefs3 <payload>` as the option it sets and how long it is. A
 *  payload is thousands of characters of base64, so the row says what the line
 *  is and leaves reading it to the clipboard. */
function describe(line: string): { option: string; start: string } {
  const [, option = "", payload = ""] = line.split(" ");
  return { option, start: payload.slice(0, 24) };
}

/** One lobby line and its copy button, which is the only part of the lobby
 *  commands that has to run in the browser. */
export function CopyLine({ line, index, total }: { line: string; index: number; total: number }) {
  const [state, setState] = useState<"idle" | "copied" | "refused">("idle");
  const { option, start } = describe(line);

  async function copy() {
    try {
      await navigator.clipboard.writeText(line);
      setState("copied");
    } catch {
      // Clipboard access can be refused, and the row only shows the start of
      // the line, so the whole of it has to appear somewhere it can be selected.
      setState("refused");
    }
  }

  return (
    <li className="flex flex-col gap-2 rounded border border-neutral-800 bg-black p-3">
      <div className="flex items-center gap-3">
        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
          <span className="text-xs text-neutral-400">
            Line {index + 1} of {total}, {line.length.toLocaleString("en-GB")} characters
          </span>
          <code className="overflow-hidden text-ellipsis whitespace-nowrap text-xs text-neutral-100">
            !bset {option} {start}…
          </code>
        </div>
        <button type="button" onClick={copy} className={action}>
          <span aria-live="polite">{state === "copied" ? "Copied" : "Copy"}</span>
        </button>
      </div>
      {state === "refused" ? (
        <label className="flex flex-col gap-1 text-xs text-neutral-400">
          Your browser refused the copy. Select all of this instead.
          <textarea
            readOnly
            rows={3}
            value={line}
            onFocus={(event) => event.currentTarget.select()}
            className="rounded border border-neutral-800 bg-neutral-950 p-2 font-mono text-neutral-100"
          />
        </label>
      ) : null}
    </li>
  );
}
