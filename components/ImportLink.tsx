"use client";

import { useState } from "react";

/**
 * The one action every item has.
 *
 * A `coilbox://` link does nothing at all when the scheme has no registered
 * handler, which is the case for anyone without coilbox installed. The browser
 * reports that to the console and nowhere else, so the button just appears
 * broken.
 *
 * So using it always reveals the fallback. Somebody who has coilbox is already in
 * the app and never sees it. Somebody who does not gets the URL and a sentence
 * saying why nothing happened, rather than a dead button and a console message
 * they will never read.
 */
export function ImportLink({
  shareUrl,
  variant = "quiet",
}: {
  shareUrl: string;
  variant?: "quiet" | "solid";
}) {
  const [shown, setShown] = useState(false);
  const [copied, setCopied] = useState(false);
  const [copyFailed, setCopyFailed] = useState(false);

  async function copy() {
    try {
      await navigator.clipboard.writeText(shareUrl);
      setCopied(true);
    } catch {
      // Clipboard access can be refused. In the panel the URL is on screen
      // anyway, but on a card it is not, so it has to appear.
      setCopyFailed(true);
    }
  }

  const className =
    variant === "solid"
      ? "inline-flex items-center gap-2 self-start rounded-md bg-neutral-100 px-5 py-2.5 text-sm font-medium text-neutral-900 transition-colors hover:bg-white"
      : "-my-1 inline-flex items-center gap-1.5 self-start py-1 text-sm text-neutral-300 underline-offset-4 hover:underline";

  return (
    <div className="flex flex-col gap-2">
      <a
        href={`coilbox://import?url=${encodeURIComponent(shareUrl)}`}
        onClick={() => setShown(true)}
        className={className}
      >
        <svg
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth={2}
          strokeLinecap="round"
          strokeLinejoin="round"
          aria-hidden="true"
          className={variant === "solid" ? "size-4" : "size-3.5"}
        >
          <path d="M12 5v14M5 12h14" />
        </svg>
        Import into Coilbox
      </a>

      {/* On a card the full panel shoves every row below it down, so there the
          same thing is said in one line and the URL only appears if copying it
          was refused. */}
      {shown && variant === "quiet" ? (
        <p className="text-xs text-neutral-400">
          Nothing happened? Coilbox is not installed here.{" "}
          <button
            type="button"
            onClick={copy}
            className="rounded border border-neutral-800 px-2 py-1 text-neutral-300 transition-colors hover:border-neutral-600 hover:text-white"
          >
            {copied ? "Copied" : "Copy the link"}
          </button>
          {copyFailed ? (
            <code className="mt-1 block break-all text-neutral-400">
              {shareUrl}
            </code>
          ) : null}
        </p>
      ) : null}

      {shown && variant === "solid" ? (
        <div className="flex flex-col gap-2 rounded-md border border-neutral-800 bg-black p-3">
          <p className="text-xs text-neutral-400">
            Nothing happened? Coilbox is not installed, or has never been opened
            on this machine. Copy this and paste it into Coilbox.
          </p>
          <div className="flex items-center gap-2">
            <code className="min-w-0 flex-1 overflow-hidden text-ellipsis whitespace-nowrap text-xs text-neutral-400">
              {shareUrl}
            </code>
            <button
              type="button"
              onClick={copy}
              className="shrink-0 rounded border border-neutral-800 px-2 py-1 text-xs text-neutral-300 transition-colors hover:border-neutral-600 hover:text-white"
            >
              {copied ? "Copied" : "Copy"}
            </button>
          </div>
        </div>
      ) : null}
    </div>
  );
}
