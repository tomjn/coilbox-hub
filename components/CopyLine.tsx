"use client";

import { useState } from "react";

const action =
  "shrink-0 rounded border border-neutral-800 px-3 py-1.5 text-xs text-neutral-300 transition-colors hover:border-neutral-600 hover:text-white active:border-neutral-500 active:text-white";

/**
 * One autohost command and its copy button, which is the only part of the
 * commands that has to run in the browser.
 *
 * A payload is thousands of characters of base64, so the row shows the start
 * of it. The whole line is in the page all the same, clipped by CSS and
 * selected as one piece: somebody who selects the text by hand, not with the
 * button, still copies a line that works and never the visible stub of one.
 */
export function CopyLine({ line, index, total }: { line: string; index: number; total: number }) {
  const [state, setState] = useState<"idle" | "copied" | "refused">("idle");

  async function copy() {
    try {
      await navigator.clipboard.writeText(line);
      setState("copied");
    } catch {
      setState("refused");
    }
  }

  return (
    <li className="flex flex-col gap-2 rounded border border-neutral-800 bg-black p-3">
      <div className="flex items-center gap-3">
        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
          <span className="text-xs text-neutral-400">
            Command {index + 1} of {total}, {line.length.toLocaleString("en-GB")} characters
          </span>
          <code className="select-all overflow-hidden text-ellipsis whitespace-nowrap text-xs text-neutral-100">
            {line}
          </code>
        </div>
        <button type="button" onClick={copy} className={action}>
          <span aria-live="polite">{state === "copied" ? "Copied" : "Copy"}</span>
        </button>
      </div>
      {state === "refused" ? (
        <p className="text-xs text-neutral-400">
          Your browser refused the copy. Click the command to select all of it, then copy it.
        </p>
      ) : null}
    </li>
  );
}
