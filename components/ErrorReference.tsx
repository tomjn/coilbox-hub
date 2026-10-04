"use client";

import { useState } from "react";
import { Button } from "@/components/Button";

/**
 * The digest Next attaches to a server error, shown so a visitor can quote it
 * in a bug report. It matches the line in the server log. Used by both
 * `error.tsx` and `global-error.tsx`, so it depends on nothing from the layout.
 */
export function ErrorReference({ digest }: { digest: string }) {
  const [copied, setCopied] = useState(false);
  const [copyFailed, setCopyFailed] = useState(false);

  async function copy() {
    try {
      await navigator.clipboard.writeText(digest);
      setCopied(true);
    } catch {
      // Clipboard access can be refused. The reference is on screen anyway.
      setCopyFailed(true);
    }
  }

  return (
    <div className="flex flex-wrap items-center gap-2 text-xs text-neutral-400">
      <span>
        Reference: <code>{digest}</code>
      </span>
      <Button variant="ghost" size="xs" onClick={copy}>
        {copied ? "Copied" : copyFailed ? "Copy failed" : "Copy"}
      </Button>
    </div>
  );
}
